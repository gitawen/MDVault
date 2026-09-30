# ADR: Reconciling external changes with the notes registry

- **Status**: Accepted; delivered in Phase 5 (G2, G3 approved 2026-09-30; signed off 2026-09-30)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 5, Filesystem Intelligence (§13, §15, §17, §18, §38, §41, §43, §54; Rules 3, 4, 8, 9, 10)
- **Amends**: ADR `note-registry-and-indexing` (pairing steps, triggers, follow-ups)

## Context
- `VaultIndexService::reindex` scans, hashes **every** file, and pairs records to files by exact path → unique case-insensitive path → unique hash. Everything is applied in one transaction (deletes → updates/moves → inserts). The UUID survives renames and moves only when the content is unchanged.
- Polling (ADR `external-change-detection`) runs a reconcile every few seconds, so hashing every byte each time is unacceptable for large vaults.
- A folder rename in Explorer moves many files at once. Duplicate-content files (e.g. several empty notes) make the hash step ambiguous, which yields new UUIDs.
- Requests can overlap in browser dev (Herd/FPM). In the desktop they are serial, but in-app operations (create, rename, save) change the registry between a scan and its apply.
- SQLite can't roll back file operations, and the filesystem is the truth (Rules 9, 10). There is no `folders` table (§15).
- MDVault's own writes update `file_hash` right after writing (`NoteService`), before any later check.

## Options Considered
1. **Change skipping**:
   - (a) hash every file on every check;
   - (b) **a trusted `(size, mtime)` shortcut stored in a new nullable `notes.file_mtime`, with a racy-mtime rule (chosen)**;
   - (c) a snapshot in the cache store.

   (a) is O(bytes). (c) is a second, less deterministic registry.
2. **Identity pairing**:
   - (a) keep three steps;
   - (b) **add "unique file name" (case-insensitive basename, 1:1 among leftovers) as step 4 (chosen)**;
   - (c) add "the single vanished/new pair in the same folder" (it pairs an unrelated delete plus create, e.g. replacing `Draft.md` with `Final.md`).
3. **Concurrency**:
   - (a) a cache lock;
   - (b) **re-read a fingerprint of all vault rows inside the apply transaction and abort as `stale` on any difference; retry once (chosen)**;
   - (c) none (risks a unique-index violation or resurrecting a just-renamed record).
4. **Folder change detection**:
   - (a) a folders table (forbidden by §15);
   - (b) server-side memory of the previous scan;
   - (c) **a stateless tree signature = SHA-256 of the sorted scanned directories plus the sorted `uuid:path` pairs, compared by the client (chosen)**.

## Decision
- **Schema**: `notes.file_mtime` is an unsigned big integer, nullable. It holds a *trusted* modification time (Unix seconds), or `null`, meaning "hash on the next check". It is derived metadata that is never shown or exported as truth. Phase 6 may drop it on restore.
- **Modes** (`App\Enums\IndexMode`):
  - `Full` (manual Re-index; `reindex()`) hashes every file.
  - `Quick` (automatic checks; vault open, create and add) skips hashing a file when a record exists at the exact path, its path isn't in `$verifyPaths`, `file_mtime` isn't null, and both mtime and size are equal.
  - The open note is always passed in `$verifyPaths`.
- **Racy-mtime rule**: whenever a file is hashed, its mtime is stored only if it is older than `scanStart − 2 s` (FAT has 2 s resolution; PHP has 1 s). Otherwise `null` is stored. This means an edit landing in the same second as a scan is never masked.
- **`plan()`**: scan → selective hash → pairing, then leftover rows are deleted (or kept under unreadable directories), and leftover files are inserted with UUIDv7. Pairing order:
  1. exact path;
  2. unique case-insensitive path;
  3. unique identical hash;
  4. **unique file name**.

  Only 1:1 pairs are made. An exact-path file whose content is unchanged but whose trusted mtime differs becomes a **touch** (an mtime-only update, with no `updated_at` bump, not reported).
- **`apply()`**: one transaction.
  1. Re-read `id → relative_path|file_hash|file_size` for every vault row. Any difference from the plan's snapshot → return `stale`, with nothing applied.
  2. Deletes → updates/moves → touches → inserts. The unique `(vault_id, relative_path)` index can't be violated, for the Phase 3 reason; the basename step targets only scanned paths that had no exact record.
  3. A `QueryException` is reported and treated as `stale`.

  `reconcile()` retries a stale plan once.
- **Result**: `IndexResult` gains:
  - `touched`;
  - `changes` (created / modified / moved with `from` and `content_changed` / deleted, each with a UUID);
  - `stale`;
  - `orphanTempFiles` (`.mdvault-save-*` files older than 60 s, never indexed or deleted);
  - `treeSignature`.

  `browse()` returns the same signature for the Workspace prop, computed from the same inputs.
- **Failure handling (Rule 10)**:
  - Reconcile performs **no filesystem writes**.
  - An unreadable file keeps its record; an unreadable directory keeps the records under it.
  - A missing or unreadable vault root aborts with nothing changed (`unavailable` to the client; the vault status becomes `missing` through `VaultService::refreshStatus`).
  - A DB failure changes nothing, and the next check retries.
- **Own writes**: in-app operations update the registry before responding, so the next check finds matching hashes and paths and reports nothing. The racy rule makes the next check hash a just-saved file once, then trust it.
- **Boundaries**: filesystem access only through `FileStorageService` (`scan` now returns `mtime`; new `modifiedTime`) and `FileHashService`. `VaultIndexService` and `ExternalChangeService` use no raw filesystem functions (arch test).

## Consequences
- **Positive**:
  - A check costs a stat walk plus hashing of changed files only.
  - UUIDs survive moves and folder renames even with edits or duplicate content.
  - Concurrent in-app operations can never be clobbered by a stale plan.
  - Folder changes are visible without a folders table.
  - Everything is still fully rebuildable from disk (Full mode).
- **Negative / trade-offs**:
  - A same-size edit whose mtime is deliberately restored (e.g. `touch -r`, some copy tools) is missed by Quick mode until the note is opened, saved or fully re-indexed.
  - The basename step can attach a UUID to an unrelated same-name file when one is deleted and another is created in a different folder between two checks. Content differences still go through the conflict UI.
  - A rename plus an edit (different name) still gets a new UUID.
  - A continuously busy registry could keep returning `stale`; the client simply retries on the next tick.
  - One migration.
- **Follow-ups**:
  - Phase 6 (delivered): manifests carry uuid, relative_path, file_hash and file_size but never file_mtime; restored notes start with `file_mtime` null.
  - Phase 7: pairing over encrypted names.
  - Consider progress reporting for Full re-index of very large vaults.
  - With automatic checks off, "Save as a new note" on a note that was moved (not deleted) clears its stale row, so the moved file is re-registered with a new UUID (F3); consider a Quick reconcile before the copy.
  - AR-03 (delivered): in-app hash updates (`NoteService::save`, `preview`) set `file_mtime` to null, so a non-null `file_mtime` always attests to the stored hash.
