# ADR: Vault removal (unregister / OS trash) and rename semantics

- **Status**: Proposed (Phase 2 plan, pending user approvals C1, C2, C4)
- **Date**: 2026-09-27
- **Phase**: Master Plan Phase 2, Vault Management (§7, §43, §51 "Vault can be removed safely", "Vault can be renamed")

## Context
- Vault folders hold the user's only copy of their notes (Markdown is the source of truth, Rule 1).
- §51 requires removal to be *safe*.
- External tools may hold the folder open (Explorer, VS Code, OneDrive sync). On Windows this makes renames and deletes fail partway or entirely.
- NativePHP 2.3 exposes `Native\Desktop\Facades\Shell::trashFile($path)`, which calls Electron `shell.trashItem()` over the local API:
  - it only exists in the desktop runtime (`nativephp-internal.running`);
  - it returns `void`, and the Electron endpoint's 400 error response is **not** surfaced to PHP;
  - `ShellFake` records calls but deletes nothing.
- In browser dev (Herd) there is no OS trash.

## Options Considered
- **Delete**:
  1. Unregister only. Always safe, but a user who wants the folder gone must delete it by hand.
  2. Permanent delete. Irreversible; rejected on data-safety grounds.
  3. OS trash only. Unavailable in browser dev, and failures are silent.
  4. **Unregister by default, plus an optional OS trash checked afterwards (chosen).**
- **Rename**:
  1. **Display name only (chosen).**
  2. Also rename the directory. Fails under locks, breaks external references, and needs a DB/FS compensation path. It is effectively the "move" operation, which is deferred.
- **Trash testability**:
  1. `Shell::fake()`: can't simulate success.
  2. `Http::fake()`: can't delete a folder either.
  3. **An `App\Contracts\Trash` contract, implemented by `App\Support\NativeTrash`, substituted in tests (chosen).** This matches ADR `service-layer-architecture` rule 4(b): an OS boundary that tests must substitute.

## Decision
- **Remove** (`VaultService::remove(Vault, bool $moveFolderToTrash = false)`):
  - **Default (unregister)**: delete the record, and clear `app.current_vault` if it pointed to this vault, in one DB transaction. The filesystem is never touched.
  - **With trash**:
    - Requires `Trash::isAvailable()` (desktop) and an `active` vault.
    - Safety guards refuse filesystem roots, the storage root or any folder containing it, and the Documents folder or any folder containing it.
    - Order: `Trash::moveToTrash(path)`, then `clearstatcache()`, then check that `! file_exists(path)`, and only then the DB transaction.
    - If the folder still exists (silent failure, lock or partial move), the operation fails with a "close programs using it" message and **the record is kept**.
    - If the DB delete fails after a successful trash, the record remains, shows as Missing, and can be unregistered again. The folder is recoverable from the OS trash.
  - **MDVault never permanently deletes a vault folder or any non-empty directory in v1.** The only directory removal in `app/` is `FileStorageService::deleteEmptyDirectory()`, used solely to undo a folder this same create call just made. Any future permanent delete needs a new ADR.
- **Rename** (`VaultService::rename`): updates `name` and `description` only, with the name validity and uniqueness rules of ADR `vault-registry-and-consistency`. The folder keeps the name it was created with, and the UI shows the real path.
- **Deferred (C4)**: moving a vault, changing its location, renaming its folder on disk, and relinking a missing vault. These form one future "vault relocation" item and must preserve the UUID.
- **Enforcement**:
  - Pest arch rule: `Native\Desktop\Facades\Shell` / `Native\Desktop\Shell` are only used in `App\Support\NativeTrash`.
  - QA code review plus a grep for `deleteDirectory|rmdir|unlink` in `app/Services`.
  - The default `Trash` binding is inert outside the desktop runtime, so tests can't trash real folders.

## Consequences
- **Positive**: no path in the app can destroy user data irreversibly. Removal failures leave a consistent registry. The trash outcome is checked, not assumed.
- **Negative / trade-offs**:
  - In browser dev, "remove" can only unregister.
  - Display names and folder names can drift apart after a rename.
  - The trash behaviour is ultimately verified manually on the desktop (automated tests cover the logic through the contract).
- **Follow-ups**:
  - Vault relocation (move, relink, folder rename), before Phase 6.
  - Phase 6 backups may offer "back up before removing".

---

## Summary

The Phase 2 plan is ready, but it is **BLOCKED** until the user answers approvals C1–C10. The design already assumes each recommended answer, so implementation can start as soon as they are approved.

- **Feature**: `phase-2-vault-management` (implements Master Plan Phase 2, §51), Level 3.
- **Artifacts** (above, each after its `<!-- path: -->` line):
  - `c:\Users\cherw\Herd\MDVault\.ai\features\active\phase-2-vault-management\requirements.md`
  - `c:\Users\cherw\Herd\MDVault\.ai\features\active\phase-2-vault-management\plan.md`
  - `c:\Users\cherw\Herd\MDVault\.ai\decisions\vault-registry-and-consistency.md` (new)
  - `c:\Users\cherw\Herd\MDVault\.ai\decisions\vault-removal-and-rename-semantics.md` (new)
- **Open questions**: C1–C10 in `plan.md` §6. If C3 is rejected, skip T6, the add-existing dialog and `register()`. If C1 is answered "unregister only", drop the trash contract, the trash tests and the checkbox.
- **Things I checked in `vendor/` that shaped the plan**:
  - NativePHP's `Shell::trashFile()` returns `void` and hides the Electron 400 error. That is why the plan checks afterwards that the folder is gone and puts a `Trash` contract in front of it, since `ShellFake` can't simulate success.
  - `ShellContract` is bound with `bind`, so each call gets a fresh client and `Http::fake()` works in the `NativeTrash` tests.
  - `AppSidebar.vue` becomes single-root by removing its unused trailing `<slot />`; its only consumer, `AppSidebarLayout.vue`, passes no slot content.
- **Next step**: relay the briefing to the user and record their C1–C10 answers in `plan.md` §6. Then set the status to APPROVED (updating the Revision Log if any answer changes tasks) and route to `senior-developer` with "Implement `.ai/features/active/phase-2-vault-management/plan.md` (Revision 1)".
