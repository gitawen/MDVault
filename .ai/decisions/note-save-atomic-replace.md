# ADR: Saving a note (atomic replace, stale-write protection, failure handling)

- **Status**: Accepted (F4, F5, F6 approved; delivered in Phase 4, 2026-09-29)
- **Date**: 2026-09-29
- **Phase**: Master Plan Phase 4, Tiptap Editor (§17, §18, §22, §24, §41, §43, §44; Rules 1, 5, 8, 9, 10)

## Context
- ADR `note-file-operations` forbids overwriting: every create is `fopen('x')`, and every rename pre-checks its target. It names the Phase 4 save as the one intended replacement, needing its own ADR.
- An in-place write (`file_put_contents`) that is interrupted by a crash, power loss or a full disk leaves a truncated file, and the note is the user's only copy.
- PHP `rename()` replaces the target atomically on POSIX. On Windows it uses `MoveFileEx` with REPLACE_EXISTING, which fails with the target intact if another process holds the file without share-delete (Word, some sync or antivirus tools).
- External tools may edit the file while it is open (Rule 8). §24 forbids silently overwriting their changes. Phase 5's watcher doesn't exist yet.
- SQLite can't roll back file writes (Rule 10). The filesystem is the truth (§18).
- One user and one instance (§44). `FileStorageService` is the only filesystem boundary; `FileHashService` is the only hasher.

## Options Considered
1. **Write method**:
   - (a) in place (truncation risk);
   - (b) **temp sibling + rename replace (chosen)**;
   - (c) a backup copy + in-place write + delete the backup (two writes; still not atomic);
   - (d) Windows `ReplaceFile` (not reachable from PHP).
2. **Stale-write protection**:
   - (a) none (violates §24);
   - (b) compare the modification time (coarse, and fooled by copies);
   - (c) **compare the SHA-256 of the bytes the client loaded (base hash), before writing and again immediately before the rename (chosen)**.
3. **Overwrite on conflict**:
   - (a) a `force` flag (bypasses the check entirely);
   - (b) **re-send with the disk's current hash as the new base, after user confirmation (chosen)**, so a further change still conflicts.
4. **DB failure after a successful write**:
   - (a) restore the old file (destroys the user's edit, and could itself fail);
   - (b) **keep the file, report, and let the next open or re-index reconcile the hash (chosen)**.
5. **Transport**:
   - (a) an Inertia visit with a partial reload (resends up to 1 MiB of content as props and remounts the editor);
   - (b) **JSON `PUT` through Inertia v3 `useHttp` (chosen)**.

## Decision
- **Endpoint**: `PUT /notes/{note:uuid}/content` with `{content, base_hash, mode: rich|source}` plus, for Rich saves, `has_frontmatter` and `frontmatter` (raw YAML between the delimiters). An absent field keeps the current block. A value equal to the current YAML keeps its bytes exactly. Otherwise the block is replaced with the existing delimiter lines and separator (new blocks use `---`), or it is removed. A `---` line inside is refused, and a decode self-check confirms the frontmatter/body split is stable. Responses:
  - 200 `{saved, file_hash, file_size, updated_at}`;
  - 409 `{reason: changed|missing, message, current_hash}`;
  - 422 for validation and operation errors (field `content`, or `vault`).

  Web middleware and CSRF apply.
- **`NoteService::save`**, in order:
  1. The vault is available.
  2. The file exists (otherwise 409 `missing`; nothing is ever recreated) and is not a symlink.
  3. Read the current bytes and hash them. A mismatch with `base_hash` → 409 `changed`.
  4. The current file is editable: ≤ 1 MiB and valid UTF-8.
  5. `MarkdownService` composes (rich: the current frontmatter + body; source: verbatim) and encodes (the original EOL and BOM). The result must be ≤ 1 MiB.
  6. Identical bytes → no write; reconcile a stale DB hash.
  7. The file is writable. A read-only file is respected, never bypassed by the rename.
  8. `FileStorageService::replaceFile`.
  9. Verify the disk hash equals the new hash; otherwise 409 `changed`.
  10. Update `file_hash` and `file_size`. A DB exception is reported and the save still succeeds.
- **`FileStorageService::replaceFile(path, contents, beforeReplace)`**, the **only** method that replaces a file:
  1. The target is a regular non-symlink file (otherwise `TargetInvalid`).
  2. Exclusive-create `.mdvault-save-<12 random>` in the same directory.
  3. Write it and verify its size (`WriteFailed` on a short write).
  4. Best-effort `fsync`.
  5. Copy the target's permission bits.
  6. `beforeReplace()` re-checks the base hash (`GuardFailed`).
  7. `rename`, up to 3 attempts 100 ms apart (`ReplaceFailed`).
  8. Check afterwards.

  Every failure discards the temp file through a private `discardTempFile`, which only unlinks names with the `.mdvault-save-` prefix. The target is never touched on a failure path.
- **Client**:
  - One save in flight at a time. Each success's `file_hash` becomes the next base.
  - Autosave 1.5 s after the last change, 10 s max wait; explicit save with Ctrl/Cmd+S and a button.
  - A conflict pauses autosave and offers Reload from disk / Keep my version (confirmed; re-sent with `current_hash`) / Copy my text.
  - Unsaved changes are saved before any navigation or window close.
  - While a guarded navigation is in progress the editor is read-only (frozen until the visit finishes), so no keystroke can land after the final flush. A mode switch re-baselines the saver from the pristine props (full file for Source), never from the other mode's serialization.
- **Boundaries**: `unlink`, `file_put_contents` and `fsync` may appear only in `FileStorageService` (Pest arch rule). `NoteService` and `MarkdownService` use no raw filesystem functions. The indexer ignores `.mdvault-save-*` (the dot-prefix rule).

## Consequences
- **Positive**:
  - A crash or failure at any point leaves either the old file or the new file, never a mix.
  - External edits are never silently overwritten (§24), even before Phase 5.
  - A lock, a full disk or a read-only file fails safely with the text kept in the editor.
  - The DB converges on the file (Rule 9).
- **Negative / trade-offs**:
  - A small time-of-check/time-of-use window between the guard and the rename (accepted, §44). The post-replace check detects it after the fact, but the external write in that window may already have been replaced.
  - Replacing creates a new inode, which breaks hard links and resets ACLs to the directory's inherited defaults. Permission bits are copied. Windows file-name tunnelling keeps the creation time.
  - A crash between the temp write and the rename leaves a hidden `.mdvault-save-*` orphan that holds the user's new text. It is never auto-deleted.
  - "Keep my version" deliberately discards the external change after confirmation.
  - The DB may briefly hold a stale hash after a DB failure.
- **Follow-ups**:
  - **Phase 5** (delivered): own saves update `file_hash` before any later check, so they are never reported as external; Compare view and 'Save mine as a new note' (ADR `open-note-external-conflicts`); orphan `.mdvault-save-*` files older than 60 s are reported, never deleted.
  - **Phase 6**: backups exclude `.mdvault-*` temp files.
  - Revisit the 1 MiB edit cap if performance testing allows.
