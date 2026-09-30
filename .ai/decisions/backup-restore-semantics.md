# ADR: Validating and restoring backups (one flow, identity, destination, atomicity, archive safety)

- **Status**: Accepted (user approved H5, H6, H9 on 2026-09-30)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 6, Backup and Restore (§12, §25, §28, §29, §38, §43, §44, §55; Rules 3, 4, 8, 9, 10)
- **Amends**: ADR `vault-removal-and-rename-semantics` (a narrow exception to "never delete a non-empty directory")

## Context
- §28 requires: read manifest → validate format → validate database → validate files → check integrity → import. "A corrupted or incompatible backup must not partially overwrite an existing MDVault installation."
- §29 prescribes a temporary extraction, then import, then rebuild the file index, then verify.
- §25 lists "Restore Backup" and "Import Backup" separately without defining the difference.
- §55's acceptance test: create → back up → remove local data → fresh app state → import → verify vaults, notes and content.
- UUIDs are globally unique in `vaults.uuid` and `notes.uuid` (unique indexes). A vault name is also its folder name, is unique case-insensitively, and must pass `assertValidFolderName`. New vaults live at `<storage root>/<name>` (ADR `vault-registry-and-consistency`).
- SQLite can't roll back file operations (Rule 10). MDVault never overwrites user files, and never deletes non-empty directories (ADRs `note-file-operations`, `vault-removal-and-rename-semantics`).
- A directory `rename()` is atomic only within one volume. Windows antivirus or indexers may briefly lock freshly written files.
- Archives may come from anywhere (a USB stick, a download). `ZipArchive::extractTo()` trusts entry names (zip-slip). Entries can be symlinks, encrypted, oversized or lie about their size.

## Options Considered
1. **Restore vs Import**:
   - (a) **one flow, "Restore from backup", with a per-vault action (chosen)**;
   - (b) two operations: "Restore" replaces the installation, "Import" adds to it.

   (b) needs a destructive replace path for little v1 value.
2. **A vault UUID that is already registered**:
   - (a) replace the existing vault;
   - (b) **skip by default, with an explicit "Restore as a copy" option (new UUIDs, suffixed name) (chosen)**;
   - (c) always copy.

   (a) needs deleting or trashing live data and is irreversible in browser dev.
3. **Destination**:
   - (a) **the current storage root, `<root>/<name>`, with an automatic `(restored)` suffix when the name or folder is taken (chosen)**;
   - (b) a user-chosen folder per restore;
   - (c) the original absolute path (not portable, and not in the manifest).
4. **Atomicity**:
   - (a) extract straight into the final folders;
   - (b) **validate → extract into a hidden same-volume staging folder with per-file hash checks → one DB transaction that inserts the records and renames each staged folder into place, compensating by renaming back (chosen)**;
   - (c) the same as (b), but committing vault by vault.

   (a) can leave half-extracted vaults. (c) can leave a partial multi-vault import.
5. **Staging cleanup**:
   - (a) leave staging folders behind (clutters the storage root and duplicates data);
   - (b) **allow one tightly scoped recursive delete, for MDVault's own `.mdvault-restore-*` staging folders only (chosen)**.

## Decision
- **One operation**: "Restore from backup". Import is the same flow (H5). Per vault in the backup, the action is one of these (enum `RestoreAction`):
  - `restore`: keep the original vault and note UUIDs. Allowed only when the vault UUID isn't registered; this is the default for such vaults.
  - `copy`: new UUIDv7s for the vault and all its notes. Allowed always; offered when the UUID is registered.
  - `skip`: the default when the UUID is registered, and for any vault not listed.

  There's no in-place replace in v1. To roll a vault back, remove it (unregister or Recycle Bin), then restore, or restore a copy.
- **Identity** (Rule 4):
  - `restore` inserts the vault with its manifest UUID and each note with its manifest UUID. If one of those note UUIDs already exists elsewhere, that note alone gets a new UUIDv7, and the result carries a warning with the count.
  - `created_at`/`updated_at` come from the manifest.
  - Description is preserved. `is_encrypted` is false. `status` is `active`. `file_mtime` is `null`.
- **Destination** (H6):
  - the storage root from `StoragePathService::ensureRootReady()`. Folder = the vault name when all of these hold:
    - no vault has that name (case-insensitive);
    - nothing exists at `<root>/<name>`;
    - no registered vault path overlaps it;
    - no other vault in the same restore claims it.
  - Otherwise the first free name from `<name> (restored)`, `<name> (restored 2)` … `(restored 20)` (the base is truncated so the total is ≤ 100 characters, with trailing spaces and dots trimmed). If none is free, the restore is refused.
  - `path` = canonical target; `relative_path` = the folder name.
  - Existing folders (registered or not) are never merged into or overwritten. A folder left on disk after "remove local data" leads to a suffixed restore (documented).
- **Validation pipeline** (§28; `BackupService::inspect` and the first stage of `restore`), where every failure is a user-readable problem (at most 20 listed):
  1. The path is absolute, a regular non-symlink file, and ends in `.zip`.
  2. It opens as a ZIP (`RDONLY | CHECKCONS`), with ≤ 100,000 entries, no encrypted entries and no symlink entries (Unix mode `0120000` in the external attributes).
  3. `manifest.json` exists and is ≤ 16 MiB. The JSON decodes (depth ≤ 32) to an object.
  4. Header checks:
     - `application` = `MDVault`, `format` = `mdvault-backup`;
     - `format_version`/`database_version` supported (newer → "made by a newer version");
     - `hash_algorithm` = `sha256`;
     - the counts and sums equal the actual contents.
  5. **Registry ("validate database")**:
     - UUIDs are valid and unique (vaults among vaults; notes across the whole backup);
     - names pass `assertValidFolderName`, are unique case-insensitively, and `archive_path` = `vaults/<name>`;
     - `is_encrypted` is false and `encryption` is null;
     - hashes match `^[0-9a-f]{64}$`; sizes are integers ≥ 0; timestamps are ISO-8601 or null; `modified_at` is an integer or null;
     - note paths end in `.md`;
     - every parent directory of a note or file is listed;
     - no duplicate paths (case-insensitive on Windows and macOS).
  6. **Path safety** for every relative path and entry name:
     - valid UTF-8;
     - `/`-separated;
     - no backslash, NUL or control characters, no leading `/`, no drive letter;
     - no empty, `.` or `..` segments, and no segment starting with `.`;
     - each segment ≤ 255 bytes, and the path ≤ 1,024 bytes;
     - on Windows additionally: no `< > : " | ? *`, no trailing dot or space, and no reserved device names.
  7. **Files**: the ZIP's entry set must be exactly `manifest.json`, plus each expected file entry with an uncompressed size equal to the manifest, plus optional directory entries (`vaults/<name>/`, `vaults/<name>/<dir>/`). Anything extra or missing is a problem.
  8. **Integrity**: stream-hash every expected entry (`FileHashService::hashStream`, capped at the declared size + 1). The hash and byte count must match. `inspect` always does this.

  **Amendment (QA round 1, `mdv-p6`; revised round 2):** the absolute caps (`MAX_DECLARED_FILE_BYTES`, 10 GiB per entry; `MAX_DECLARED_TOTAL_BYTES`, 100 GiB total) remain unchanged, checked in `parseRegistryEntry()` and alongside the other total-vs-manifest checks, before Stage 8. On their own they bound Stage 8's worst case but don't make it small: a small, well-compressed physical archive can declare a size anywhere under either cap and still reach Stage 8. Stage 7 (entry-set matching) therefore also applies a compression-ratio check: for every expected entry whose uncompressed size — already cross-checked equal to the manifest's declared `file_size` — exceeds 1 MiB, the physical entry's uncompressed-to-compressed ratio (using `compressed_size` from `ArchiveService::entries()`) is rejected above `BackupService::MAX_COMPRESSION_RATIO` (250:1; a compressed size of 0 above that floor is also rejected). Entries at or below 1 MiB, including empty files, never trip it, so ordinary short notes and highly-compressible small text still pass. Because `inspect()` and `restore()` share `validateArchive()`, Stages 2–7 — including this check — always run, and always before Stage 8, in both paths.

  **Amendment (QA round 3, `mdv-p6`, QA-05): the ratio check is a heuristic, not a bound; the real guarantee is a separate aggregate gate.** Round 3 QA raised two distinct ways the per-entry ratio check above could fail to bound anything: forging the ZIP central directory's `compressed_size` directly, and splitting a payload into entries small enough to dodge the check's own 1 MiB floor. Both were verified against this codebase's actual read path (`ArchiveService`, which always opens with `ZipArchive::CHECKCONS`):
    - **Direct `compressed_size` forgery does not work here.** libzip's `CHECKCONS` consistency check rejects *any* mismatch it finds between the central directory's record for an entry and that entry's own local file header — in either direction, not only an inflated central-directory value — failing the whole archive as "not a valid ZIP" before the manifest is even read; QA round 4 confirmed this empirically for both an increased and a decreased forged `compressed_size` (the latter failing with `ER_INCONS`). So an attacker who inflates `compressed_size` (the direction that would lower the reported ratio) gets the archive rejected outright — a stronger, pre-existing protection than the ratio check itself — and forging it downward, the only direction CHECKCONS might otherwise tolerate, instead makes the reported ratio *worse* and is rejected too, so neither direction helps evade the cap.
    - **Sub-floor chunking does work, with entirely genuine ZIP metadata.** The ratio check only evaluates entries whose uncompressed size exceeds `COMPRESSION_RATIO_MIN_BYTES` (1 MiB). Splitting a large, highly compressible payload into several entries each AT or under that floor evades the check completely — no forgery of any kind needed. This is the real, verified bypass.

  Either way, the per-entry ratio check is correctly understood only as a best-effort early-rejection heuristic against a hostile archive built with ordinary compression tooling (the round-2 scenario) or a single oversized compressible entry — it stays in place for that — but **not** as something that bounds Stage 8's work against a deliberately crafted, chunked archive.

  The actual unconditional bound comes from two independent things, neither of which per-entry ZIP metadata or chunking can evade: (1) a new **Stage 7.5 aggregate gate** in `validateArchive()` that compares the manifest's declared total bytes ($totalBytes$ — the same figure Stage 8 is about to stream-hash across every expected entry) against the archive's own real, on-disk physical size (`FileStorageService::size()`), rejecting when the declared total exceeds `physicalBytes * MAX_COMPRESSION_RATIO + AGGREGATE_RATIO_ALLOWANCE_BYTES` (a 64 MiB allowance so small, legitimately well-compressed backups of ordinary Markdown notes never trip it); and (2) Stage 8's existing `hashStream()` cap of `expected['size'] + 1` per entry, which was already independent of the ZIP's internal metadata and bounded by the round-1 absolute caps. Together, these mean Stage 8's total work is now provably bounded by roughly the archive's real physical size times 250 (plus the small allowance), **regardless of how the declared total is distributed across entries or what the ZIP's own metadata claims** — this is the actual unconditional guarantee the round-2 amendment's wording overclaimed for the ratio check alone. The residual cost this accepts is up to roughly 250× the physical size of a file the user themselves chose to inspect or restore, in CPU-bound hashing on a synchronous request — judged reasonable for a local desktop app with no untrusted multi-tenant input, the same trade-off already accepted for the round-1 absolute-cap worst case.
- **Restore procedure** (`BackupService::restore(path, actions)`):
  1. Run validation stages 1–7.5 (Stage 8's archive hashing is replaced by per-file size and hash verification during staging); nothing is changed on any problem.
  2. `ensureRootReady()`.
  3. Discard stale `.mdvault-restore-*` staging folders older than 1 h in the root.
  4. Plan the actions, names and UUIDs. A `restore` on a now-registered UUID refuses the whole restore. Nothing selected → error.
  5. Free-space check: `disk_free_space(root) ≥ selected total_bytes + 64 MiB` (skipped when unknown).
  6. **Stage**:
     - `mkdir <root>/.mdvault-restore-<12 random>/v<i>/`;
     - create the directories in `strcmp` order;
     - extract each file with `FileStorageService::createFileFromStream` (exclusive create, byte cap = declared size);
     - verify the size, then the SHA-256 of the written file;
     - best-effort `touch` to `modified_at`.

     On any failure, the staging folder is deleted, the error is reported as "damaged" or "couldn't extract", and nothing visible has changed. Staging is on the same volume as the targets, so the final move is a rename.
  7. **Commit**, in one DB transaction, for each planned vault in order:
     - insert the vault (`forceFill` with UUID and timestamps);
     - bulk-insert its notes in chunks of 500 (attributes from `VaultIndexService::newNoteAttributes`, with UUIDs and timestamps);
     - `renameDirectory(staging/v<i>, target)` (never overwrites; up to 3 attempts 200 ms apart for transient locks; checked afterwards);
     - update `path` to the canonical target.

     On any exception:
     - the transaction rolls back;
     - every folder already moved is renamed back into staging;
     - the staging folder is deleted;
     - the whole restore fails with "Nothing was restored".

     If a rename-back fails, the error names the folder left behind: it isn't registered, and the user can remove it or add it with "Add existing folder".
  8. Remove the (now empty) staging folder.
  9. **Verify**: `VaultIndexService::reconcile($vault, IndexMode::Full)` for each restored vault. Any change or failure becomes a warning in the result; data is never removed.
  10. If no vault is current, open the first restored vault.
- **Staging-folder exception** (H9): `FileStorageService::deleteStagingDirectory()` is the only recursive delete in MDVault. It accepts only a non-symlink directory whose basename starts with `.mdvault-restore-`, deletes without following links, and checks afterwards. Staging folders only ever hold copies extracted from an archive that still exists.
- **Archive safety summary**:
  - `extractTo()` is never used;
  - every path is validated, then joined under a freshly created private staging folder;
  - files are created exclusively (no following of pre-existing links);
  - symlink and encrypted entries are refused;
  - sizes are enforced while streaming (zip-bomb bound), with a total-size versus free-space check, entry-count and manifest-size caps, and an aggregate declared-size versus physical-archive-size gate (Stage 7.5).
- **Transport**:
  - Inspection is a JSON POST (`useHttp`). It returns 200 with `valid` true/false and `problems`, or 422 for an unusable path.
  - Restore is an Inertia form POST. It redirects to Vaults with a toast; errors land on field `path`.
  - Archives are chosen by path: the native Open dialog in the desktop app, a text path input everywhere, or the recent-backups list. There is no upload.
- **Concurrency** (§44): one user and one instance. Restore only adds new vaults and never touches the current vault's folder, so external-change polling is unaffected. Double submission is harmless: the second restore finds the UUIDs registered and refuses or skips.

## Consequences
- **Positive**:
  - Nothing visible changes until every byte has been verified.
  - A multi-vault restore is all-or-nothing.
  - Existing data is never overwritten or merged.
  - UUIDs survive a round trip (§55).
  - Zip-slip, symlink and zip-bomb attacks are handled.
- **Negative / trade-offs**:
  - There's no one-click "replace my vault with the backup".
  - Needs free space for the staged copy (the archive can't be streamed straight into place).
  - Suffixed names when leftover folders exist.
  - The inspection pass plus the restore pass read the archive twice.
  - A crash between the rename and the commit can leave an unregistered restored folder plus a staging folder (cleaned up later). No data is lost; the backup is intact.
  - Rename-back can fail under Windows locks, in which case the user is told which folder to handle.
- **Follow-ups**:
  - Phase 7: restoring encrypted vaults (`vault_encryption` rows from the manifest, locked state).
  - Optional later: replace-in-place with a Recycle Bin pre-step, per-note restore, restoring to a chosen location, progress and cancel.
