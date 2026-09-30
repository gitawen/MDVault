# ADR: Vault removal (unregister / OS trash) and rename semantics

- **Status**: Accepted. Removal: C1 approved 2026-09-27. **Rename: C2 reversed by the user on 2026-09-27; the Rename decision below supersedes the Revision 1 "display name only" decision.**
- **Date**: 2026-09-27 (Revision 1); 2026-09-27 (Revision 2: folder rename)
- **Phase**: Master Plan Phase 2, Vault Management (§7, §14, §43, §51 "Vault can be removed safely", "Vault can be renamed")

## Context
- Vault folders hold the user's only copy of their notes (Markdown is the source of truth, Rule 1).
- §51 requires removal to be *safe*.
- §43 / Rule 10: filesystem and DB operations fail independently, and SQLite can't roll back filesystem changes.
- External tools may hold the folder open (Explorer, VS Code, OneDrive sync). On Windows this makes renames and deletes fail.
- NativePHP 2.3 exposes `Native\Desktop\Facades\Shell::trashFile($path)` (Electron `shell.trashItem()`):
  - it only exists in the desktop runtime;
  - it returns `void`, and the Electron error response is **not** surfaced to PHP;
  - `ShellFake` deletes nothing.
- In browser dev (Herd) there is no OS trash.
- §14: notes store paths relative to their vault, so changing `vaults.path` never rewrites note paths.
- **Revision 2 (2026-09-27)**: the user decided that renaming a vault must also rename its folder on disk, so the folder name and the vault name stay aligned.

## Options Considered
- **Delete**:
  1. Unregister only.
  2. Permanent delete. Irreversible; rejected.
  3. OS trash only. Unavailable in browser dev, and failures are silent.
  4. **Unregister by default, plus an optional OS trash checked afterwards (chosen).**
- **Rename**:
  1. Display name only. This was the Revision 1 decision and is **superseded**.
  2. **Also rename the directory in place, in the same parent folder (chosen in Revision 2, per the user).**
- **Rename operation order**:
  - (a) DB update in a transaction, then the folder rename, then commit, with rename-back if the commit fails. This mirrors create.
  - (b) **Checks → folder rename → check it worked → DB update, with rename-back on a DB failure (chosen).**

  (b) has one compensation direction and no DB write before the rename. The only DB-side failure that could happen before the rename (a unique `path` clash) is checked beforehand by the overlap check. §7's DB-first order is prescribed for create only.
- **A folder whose basename differs from the vault name** (added with "Add existing folder"):
  - (a) **the folder takes the new name (chosen; user-approved D1 on 2026-09-27)**;
  - (b) rename only when the basename equals the old name;
  - (c) never rename folders added with "Add existing folder".
- **Case-only renames** (`Work` → `work`) on case-insensitive filesystems:
  - a direct rename (behaviour varies by OS and filesystem);
  - **a two-step rename through a hidden sibling temp name, restored on failure (chosen)**.
- **Trash testability**: **an `App\Contracts\Trash` contract implemented by `App\Support\NativeTrash`, substituted in tests (chosen)**.
- **Rename-failure testability**: `FileStorageService` is `final`. **Substitute `Illuminate\Filesystem\Filesystem` in the container with a subclass whose `moveDirectory()` fails on chosen calls (chosen).** This is deterministic on every OS; real Windows locks are verified manually.

## Decision
- **Remove** (`VaultService::remove(Vault, bool $moveFolderToTrash = false)`), unchanged from Revision 1:
  - **Default (unregister)**: delete the record, and clear `app.current_vault` if it pointed to this vault, in one DB transaction. The filesystem is never touched.
  - **With trash**:
    - Requires the desktop runtime and an `active` vault.
    - Safety guards refuse filesystem roots, the storage root or any folder containing it, and the Documents folder or any folder containing it.
    - Order: `moveToTrash(path)` → `clearstatcache()` → check `! file_exists(path)` → only then the DB transaction.
    - If the folder still exists → a "close programs using it" error, and **the record is kept**.
  - **MDVault never permanently deletes a vault folder or any non-empty directory in v1.** The only directory removal is `FileStorageService::deleteEmptyDirectory()`, used to undo a folder the same create call just made.
  - Amended in Phase 6 (ADR `backup-restore-semantics`, H9): the only exception is `FileStorageService::deleteStagingDirectory()`, which removes MDVault's own `.mdvault-restore-*` staging folders (copies extracted from a backup archive that still exists).
- **Rename** (`VaultService::rename(Vault, string $name, ?string $description)`), Revision 2:
  - **Name rules**: `assertValidFolderName` (portable, on every OS) and case-insensitive uniqueness among vaults (C7).
  - **No filesystem work when**:
    - the name is unchanged (a description-only edit), even for a missing vault; or
    - the folder's basename is already exactly the new name.
  - **Otherwise**, target = `dirname(path)/<new name>` (same parent folder, for vaults inside or outside the storage root). The steps, in order:
    1. Refresh the status. Missing → refuse (`folderMissing`, field `name`).
    2. `assertSafeFolder`.
    3. Anything existing at the target (a file, an empty or non-empty directory) that is not the same directory → refuse (`renameTargetExists`). "Same directory" = the same normalised path (case-insensitive on Windows), or the same device and inode.
    4. Overlap with any other registered vault record → refuse.
    5. `FileStorageService::renameDirectory(from, to)`:
       - it never overwrites;
       - a case-only change goes through a two-step rename via `.mdvault-rename-<random>`, restored if step 2 fails;
       - it uses `Filesystem::moveDirectory(..., overwrite: false)`;
       - the result is **checked afterwards** (`is_dir(to)`, and the source is gone).
       - On failure (typically a Windows lock) → `renameFailed`, "Close any programs using it… Nothing was changed". The record and the folder are unchanged.
    6. DB update in a transaction: `name`, `description`, `path = realpath(to)`, and `relative_path` with its last segment replaced by the new name (a `null` value stays `null`; it is not recomputed against the current storage root).
    7. **If the DB update fails**: restore the in-memory model and rename the folder back.
       - If renaming back succeeds, the original error is rethrown and nothing has changed.
       - If it fails, the error is `report()`ed and the user gets `renameRollbackFailed`, which names the folder to rename back by hand. The record keeps the old path, so the vault shows as Missing until then. No data is lost.
  - The UUID and `app.current_vault` never change. Note paths (Phase 3+) are vault-relative (§14), so they are unaffected.
- **Still deferred (C4)**: moving a vault to another parent or volume, changing its location, and relinking a missing vault. These form one future "vault relocation" item and must preserve the UUID.
- **Enforcement**:
  - Pest arch rules: `Native\Desktop\...\Shell` is only used in `App\Support\NativeTrash`; no `rename`/`unlink`/`rmdir`/... in `App\Http`.
  - QA greps:
    - `deleteDirectory|rmdir|unlink` in `app/Services`;
    - `moveDirectory|rename\(` in `app` only in `FileStorageService`;
    - no `moveDirectory(..., true)`.
  - The default `Trash` binding is inert outside the desktop runtime.

## Consequences
- **Positive**:
  - No path in the app can destroy user data irreversibly.
  - A folder rename is atomic (same parent folder), checked afterwards and compensated. Every failure path leaves either the old state or a clearly reported, recoverable state.
  - Vault names and folder names stay aligned.
- **Negative / trade-offs**:
  - In browser dev, "remove" can only unregister.
  - A rename fails while other programs have the folder open on Windows; the user must close them.
  - Renaming breaks external references to the old folder path (shortcuts, editor workspaces, sync-client settings).
  - A crash between the folder rename and the DB commit leaves the vault Missing until the folder is renamed back by hand. The window is milliseconds and there is no data loss.
  - A missing vault's name can't be changed (only its description) until its folder is back.
  - Real OS-lock behaviour and the trash behaviour are verified manually on desktop; automated tests cover the logic through substitutable boundaries.
- **Follow-ups**:
  - Vault relocation (move, relink), before Phase 6.
  - Phase 3 (delivered): MDVault holds no watchers or persistent handles; every handle is closed in `finally` (ADR `note-file-operations`). Phase 5 must stop its watcher for a vault before renaming or trashing that vault.
  - Deferred beyond Phase 6: "back up before removing".

## Revision History
| Date | Change |
|---|---|
| 2026-09-27 | Initial: unregister by default plus optional OS trash; rename is display name only. |
| 2026-09-27 | User reversed C2: rename also renames the folder on disk (checks → rename → check → DB, rename-back compensation). The Revision 1 rename decision is superseded. Plan `phase-2-vault-management` Revision 2 (§8). |
