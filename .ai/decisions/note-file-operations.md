# ADR: Note and folder file operations (ordering, compensation, deletion, handles)

- **Status**: Proposed (Phase 3 plan; pending user approvals E1, E2, E9)
- **Date**: 2026-09-28
- **Phase**: Master Plan Phase 3, Markdown Filesystem (§23, §41, §43, §52; Rules 1, 5, 9, 10)

## Context
- SQLite cannot roll back file operations (§43, Rule 10). Notes are the user's only copy of their content.
- PHP `rename()` **replaces an existing target file** on both POSIX and Windows (`MoveFileEx` with `REPLACE_EXISTING`). `fopen(..., 'x')` is the only portable atomic "create, but never overwrite" primitive.
- Windows locks can make renames fail. NativePHP's `Shell::trashFile` reports no error (ADR `vault-removal-and-rename-semantics`).
- The Phase 2 ADR follow-up: "Phase 3: release file watchers and open handles on a vault before renaming it."
- `FileStorageService` is `final`; test substitution happens through `Illuminate\Filesystem\Filesystem` (`failFolderRenames` precedent) and the `Trash` contract (`fakeTrash`).

## Options Considered
- **Create order**:
  - (a) DB first inside a transaction, then the file (the vault-create pattern);
  - (b) **exclusive file create → check → hash → DB insert; on a DB failure, remove the file only if it is still 0 bytes (chosen)**.

  With (b), a crash leaves at most an orphan empty `.md` file, which the next re-index adopts. Nothing is lost, and there is only one compensation direction.
- **Rename/move order**: **checks → no-overwrite rename (case-only via a hidden temp sibling) → check → DB update; on a DB failure, rename back (chosen)**. This mirrors vault rename (Phase 2 Revision 2). If renaming back fails, the user is told to re-index, and hash matching then re-adopts the file under the **same UUID**.
- **Delete**:
  - (a) **OS Recycle Bin via the `Trash` contract, checked afterwards, desktop only (chosen)**;
  - (b) an app-level `.mdvault-trash` folder;
  - (c) `unlink`.

  (a) matches vault removal (C1) and never destroys data.
- **Folders**:
  - create: non-recursive, never over an existing entry;
  - delete: only a completely empty directory (`rmdir`);
  - rename/move of folders deferred (E2).

## Decision
- **Boundaries**:
  - Only `FileStorageService` touches the filesystem: `createFile`, `renameFile`, `deleteNewEmptyFile`, `read`, `size`, `scan`, `makeDirectory`, `deleteEmptyDirectory`, `moveToTrash`.
  - Only `FileHashService` hashes files.
  - `NoteService` and `VaultIndexService` use neither directly (Pest arch rules).
- **Never overwrite**:
  - every create uses `fopen('x')`;
  - every rename pre-checks the target (`exists` and not the same file) and checks afterwards;
  - no API accepts an overwrite flag.
- **Compensation** never deletes content. It either renames back, or unlinks a 0-byte file created by the same call. `unlink` appears only in `FileStorageService::deleteNewEmptyFile`.
- **Delete note**:
  - if the file is already missing → delete the record only;
  - otherwise the trash must be available;
  - guards: the target is a regular file strictly inside the vault;
  - order: `moveToTrash` → check `! file_exists` → only then delete the record;
  - on failure the record is kept.
- **Path safety**:
  - user folder inputs are vault-relative and `/`-separated, with no `..`, `.`, empty segments, backslashes, drive letters, leading `/` or NUL;
  - they must resolve to a directory inside `realpath(vault)`, and no segment may be a symlink.
- **Handles and watchers**:
  - Phase 3 opens handles only inside `FileStorageService`/`FileHashService` calls and closes them in `finally` (or relies on PHP functions that close internally: `hash_file`, `file_get_contents`);
  - scan iterators are scoped to the call;
  - no watcher exists.
  - A vault rename therefore needs no coordination. Note records store only vault-relative paths, so they are unaffected.
- **Vault removal** cascades note records through the FK. Files are untouched, unless the user chose to trash the vault folder.

## Consequences
- **Positive**:
  - Every failure leaves either the prior state, or a state that re-index repairs, without data loss.
  - UUIDs survive every in-app operation.
  - Deletion is always recoverable from the OS trash.
- **Negative / trade-offs**:
  - A time-of-check/time-of-use gap between the pre-check and `rename()` (accepted: single user, §44).
  - Notes can't be deleted in browser dev.
  - A restored note gets a new UUID after re-index.
  - Folder rename/move is unavailable until a follow-up.
- **Follow-ups**:
  - Phase 4 save: write to a temp sibling `.mdvault-save-*`, then replace atomically, then re-hash. This needs its own ADR because it is the one place where replacing a file is intended.
  - Phase 5: the watcher must be paused or stopped for a vault before a vault rename or trash, and must ignore `.mdvault-*` names.
  - Follow-up item: folder rename/move and non-empty folder delete (to the Recycle Bin).
