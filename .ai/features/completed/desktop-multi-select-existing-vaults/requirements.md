# Requirements: Desktop Multi-Select Existing Vaults

## Metadata
- **Feature Name**: Desktop Multi-Select Existing Vaults
- **Feature ID**: desktop-multi-select-existing-vaults
- **Author**: System Analyst
- **Created Date**: 2026-11-21
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault's web interface supports selecting and adding multiple existing vault folders in one flow via `DirectoryBrowserDialog.vue`, `AddExistingVaultDialog.vue` batch mode, and `POST /vaults/existing/batch` (`ExistingVaultController::batchStore()`).

On the desktop runtime (NativePHP / Electron, where `NativeDialogService::isAvailable()` returns `true` and `canBrowse` is `true`), users cannot select multiple existing folders:
1. `NativeDialogService::chooseDirectory()` configures `Native\Desktop\Dialog` with `['openDirectory', 'createDirectory']` without `'multiSelections'`, so `Dialog::open()` only ever returns a single path string (`$result[0] ?? null`).
2. `ExistingVaultController::browse()` calls `chooseDirectory()` and flashes only a single `pickedFolder` payload (`{ path, name }`).
3. `AddExistingVaultDialog.vue`'s `chooseFolder()` flash callback only inspects `pickedFolder` and forces `mode = 'single'`.
4. In batch mode, the "Choose different folders…" button in `AddExistingVaultDialog.vue` binds `@click="browserOpen = true"`, opening the web `DirectoryBrowserDialog` instead of dispatching through `onChooseFolderClick()` to the native OS dialog on desktop.

Desktop users who want to import a directory of existing Markdown vaults must currently add them one by one, creating feature parity drift between web and desktop.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Allow selecting one or multiple folders in a single native OS folder picker invocation when adding existing vaults on desktop.
- Extend `NativeDialogService` with `chooseDirectories(string $title, ?string $defaultPath = null): array` returning `list<string>` while keeping `chooseDirectory()` intact for single-folder callers (such as `StorageController::browse()`).
- Update `ExistingVaultController::browse()` to call `chooseDirectories()` and flash `pickedFolders` (plus `pickedFolder` when exactly one folder is selected, preserving compatibility).
- Update `AddExistingVaultDialog.vue` so `chooseFolder()` handles both single and multi-folder flash payloads (`pickedFolders` and fallback `pickedFolder`) and transitions cleanly between `'single'` and `'batch'` modes.
- Fix the batch-mode "Choose different folders…" button in `AddExistingVaultDialog.vue` to use `onChooseFolderClick()` (with `:disabled="browsing"`), so desktop opens the native multi-select picker while web continues opening `DirectoryBrowserDialog`.
- Add/update Pest tests covering `NativeDialogService::chooseDirectories()`, `ExistingVaultController::browse()`, and `ArchitectureTest.php` compliance.

### Out of Scope (Non-Goals)
- Adding multi-selection to single-path pickers (`StorageController::browse()`, `BackupRestoreController::browse()`, or `Settings` pages).
- Changing batch registration rules, overlap detection, indexing, or toast behavior in `ExistingVaultController::batchStore()`, `RegisterVaultBatchRequest`, or `VaultBatchResult`.
- Modifying `vendor/nativephp/desktop` or `nativephp/electron` packages.
- Adding drag-and-drop folder import or recursive subfolder auto-discovery.

---

## 3. User Personas & Stories
- **As a** desktop MDVault user with multiple existing Markdown folders,
  **I want to** select multiple folders in the native OS folder picker at once,
  **So that** I can review and register all of them as vaults in a single batch without adding each folder individually.
- **As a** desktop MDVault user reviewing a batch selection,
  **I want** clicking "Choose different folders…" to reopen the native OS folder picker instead of the web browser modal,
  **So that** the desktop experience stays native and consistent across single and batch modes.
- **As a** desktop MDVault user who selects only one folder (or removes batch folders down to one),
  **I want** the dialog to present the single-vault form with optional custom name and description fields,
  **So that** single-vault addition continues to work seamlessly with full metadata control.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Native Multi-Directory Picker (`NativeDialogService::chooseDirectories`) | Add `chooseDirectories(string $title, ?string $defaultPath = null): array` (`list<string>`) to `NativeDialogService`. Configure `Native\Desktop\Dialog` with `['openDirectory', 'createDirectory', 'multiSelections']`, set `defaultPath` only when non-null and `is_dir($defaultPath)`, return `[]` when `! $this->isAvailable()`, and normalize the `Dialog::open()` response into a deduplicated `list<string>` of non-empty strings. Keep `chooseDirectory()` public behavior unchanged. | Given `nativephp-internal.running` is false, When `chooseDirectories()` is called, Then it returns `[]` and sends no HTTP request. Given desktop runtime is active and Electron returns `['/a', '/b']`, When `chooseDirectories()` is called, Then it sends `properties` containing `openDirectory`, `createDirectory`, and `multiSelections` and returns `['/a', '/b']`. Given Electron returns `[]` or `null` (cancelled), Then it returns `[]`. Given `chooseDirectory()` is called, Then it still omits `multiSelections` and returns `?string`. |
| **FR-02** | Vault Browse Endpoint Multi-Folder Flash (`ExistingVaultController::browse`) | Update `ExistingVaultController::browse()` to call `$dialogs->chooseDirectories('Choose folders to add as vaults', $paths->rootPath())`. Abort with 404 when unavailable. When empty, redirect `back()` without flashing folder data. When non-empty, flash `pickedFolders` containing `list<array{path: string, name: string}>` built via `$vaults->suggestedNameFor($path)`. When exactly 1 folder is returned, also flash `pickedFolder` with that single item; when 2+ folders are returned, omit `pickedFolder`. | Given desktop runtime is inactive, When `POST /vaults/existing/browse` is called, Then HTTP 404 is returned. Given 2 folders are picked, When `browse()` handles the response, Then it redirects back with `pickedFolders` containing both `{ path, name }` items and `pickedFolder` missing, without creating any `Vault` records. Given 1 folder is picked, Then it flashes both `pickedFolders` (1 item) and `pickedFolder` (`{ path, name }`). Given the dialog is cancelled, Then neither `pickedFolders` nor `pickedFolder` is flashed. |
| **FR-03** | Desktop Single vs. Batch Mode Transition (`AddExistingVaultDialog.vue`) | Update `chooseFolder()`'s `onFlash` callback in `AddExistingVaultDialog.vue` to inspect `pickedFolders` (falling back to `pickedFolder`). If 1 folder is picked, set `mode = 'single'`, populate `form.path` and `form.name` (when `form.name` is empty), clear errors, and reset `batchForm`. If 2+ folders are picked, set `mode = 'batch'`, set `batchForm.paths` to the selected paths, clear errors, and reset `form`. If cancelled (no flash payload), leave current `mode` and form state untouched. | Given the dialog is open on desktop (`canBrowse = true`) in single mode, When the user selects 2+ folders in the native dialog, Then the dialog transitions to batch mode listing all selected folders with "Add N vaults". Given the user selects 1 folder, Then the dialog remains in (or switches to) single mode with `Folder path` and `Name` populated. Given the user cancels the native dialog, Then current `mode`, `form`, and `batchForm` remain unchanged. |
| **FR-04** | Native Re-Selection in Batch Mode ("Choose different folders…") | Change the batch-mode "Choose different folders…" button in `AddExistingVaultDialog.vue` from `@click="browserOpen = true"` to `@click="onChooseFolderClick"` and bind `:disabled="browsing"`. | Given `canBrowse` is `true` and the dialog is in batch mode, When the user clicks "Choose different folders…", Then `POST /vaults/existing/browse` is called (native OS dialog opens) and `DirectoryBrowserDialog` does not open; the button is disabled while `browsing` is `true`. Given `canBrowse` is `false`, When the user clicks "Choose different folders…", Then `DirectoryBrowserDialog` opens as before. Given the user re-selects 1 folder in batch mode, Then the dialog switches back to single mode with that folder populated. |
| **FR-05** | Batch Submission & State Cleanup Consistency | Ensure transitions between batch and single modes (`applySingleFolder`, `applyBatchFolders`, `removeBatchFolder`, `switchToSingle`, and closing the dialog) clear stale validation errors (`form.clearErrors()`, `batchForm.clearErrors()`) and that desktop batch submissions reuse `POST /vaults/existing/batch` (`ExistingVaultController::batchStore()`). | Given a batch submission previously returned a `paths` validation error, When the user chooses new folders, removes a folder, switches to single folder, or closes the dialog, Then stale `batchForm.errors` are cleared. Given valid multiple folders selected on desktop, When the user clicks "Add N vaults", Then `POST /vaults/existing/batch` registers valid vaults, indexes notes, flashes summary toast, and redirects according to existing batch rules. |
| **FR-06** | Architecture Boundary Compliance | Keep `Native\Desktop\Dialog` strictly encapsulated inside `App\Services\NativeDialogService` and keep services independent of HTTP-layer and `File` facade classes. | Given `tests/Unit/ArchitectureTest.php` runs, Then all architectural rules pass, including `native dialogs only via NativeDialogService`. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**: `ExistingVaultController::browse()` continues to enforce `abort_unless($dialogs->isAvailable(), 404)` outside the desktop runtime. All paths submitted in single or batch mode continue through `VaultService::register()` validation (absolute path, directory check, safe-folder guard, overlap check, writability check, valid name check).
- **Performance**: No filesystem traversal occurs in `NativeDialogService::chooseDirectories()` or `ExistingVaultController::browse()` beyond the existing `is_dir($defaultPath)` check and basename extraction (`suggestedNameFor`).
- **Accessibility & UX**: `AddExistingVaultDialog.vue` keeps a single root `<Dialog>` element. The batch-mode "Choose different folders…" button disables while `browsing` is in flight (matching the single-mode "Choose folder…" button) to prevent duplicate native OS dialogs.
- **Reliability & Data Integrity**: `NativeDialogService::chooseDirectories()` defensively handles `null`, non-array, empty-string, and duplicate entries from `Dialog::open()` (since Electron `dialog.showOpenDialogSync` returns `undefined` on cancel, deserializing as `null` in JSON). Browsing never writes to the filesystem or database.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (`^13.17`) / PHP 8.4
- Desktop Runtime: NativePHP Desktop (`nativephp/desktop` `^2.3`)
- Frontend: Inertia.js v3 (`@inertiajs/vue3` `^3.0.0`, `inertiajs/inertia-laravel` `^3.0`) + Vue 3 + Tailwind CSS v4
- Routing: Laravel Wayfinder (`@/routes/vaults/existing`)
- Testing: Pest 5 (`^5.2`)
- Static Analysis & Formatting: PHPStan/Larastan (Level 7), Laravel Pint (`vendor/bin/pint --dirty --format agent`), `npm run check` (`vp check`), `npm run types:check` (`vue-tsc --noEmit`)
- Database: SQLite

---

## 7. Risks & Assumptions
- **Assumption 1**: Electron's `dialog.showOpenDialogSync` supports `properties: ['openDirectory', 'createDirectory', 'multiSelections']` across Windows, macOS, and Linux, returning `string[]` when folders are chosen and `undefined` (JSON `null`) when cancelled (verified in `nativephp/electron/electron-plugin/src/server/api/dialog.ts` and `vendor/nativephp/desktop/src/Dialog.php`).
- **Assumption 2**: Desktop multi-select does not require modifying `ExistingVaultController::batchStore()`, `RegisterVaultBatchRequest`, or `VaultBatchResult`, as they already normalize both POSIX and Windows separators and handle up to 50 folders.
- **Risk 1 & Mitigation**: If `Dialog::new()->folders()->multiple()` were used, `Dialog::folders()` uses `array_diff($this->properties, ['openFile'])` which can produce non-sequential array keys if `multiple()` precedes `folders()`. Passing `->properties(['openDirectory', 'createDirectory', 'multiSelections'])` directly guarantees a clean 0-indexed list serialized as a JSON array.
- **Risk 2 & Mitigation**: Re-selecting 1 folder after entering batch mode could keep a stale `form.name` if `form` wasn't reset upon entering batch mode. Resetting `form` when entering batch mode ensures `form.name` is cleanly derived from the newly selected single folder.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
