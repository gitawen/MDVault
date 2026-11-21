# Plan: Desktop Multi-Select Existing Vaults

## Metadata
- **Feature Name**: Desktop Multi-Select Existing Vaults
- **Feature ID**: desktop-multi-select-existing-vaults
- **Author**: System Analyst
- **Created Date**: 2026-11-21
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Extend MDVault's desktop (NativePHP / Electron) "Add existing folder" workflow to support selecting and adding multiple vault folders at once, matching the existing web multi-select batch flow. `NativeDialogService` gains a `chooseDirectories()` method enabling Electron's `multiSelections` property, `ExistingVaultController::browse()` flashes `pickedFolders` (plus `pickedFolder` for single-item compatibility), and `AddExistingVaultDialog.vue` handles multi-folder flashes and routes the batch-mode "Choose different folders…" button through `onChooseFolderClick()`.

---

## 2. Architecture & Design
- **Approach**:
  1. Keep `NativeDialogService::chooseDirectory()` unchanged in signature and behavior for single-picker consumers (`StorageController::browse()`). Add `chooseDirectories(string $title, ?string $defaultPath = null): array` (`list<string>`) and extract a small private `openDirectoryDialog()` helper inside `NativeDialogService` so dialog construction and `defaultPath` validation are not duplicated.
  2. In `NativeDialogService::chooseDirectories()`, pass `['openDirectory', 'createDirectory', 'multiSelections']` directly to `Dialog::new()->properties(...)` (avoiding `Dialog::folders()` key-preservation quirks) and normalize the `mixed` return value into a deduplicated `list<string>` of non-empty strings.
  3. Update `ExistingVaultController::browse()` to call `chooseDirectories('Choose folders to add as vaults', $paths->rootPath())`. Flash `pickedFolders` (`list<array{path: string, name: string}>`), and also flash `pickedFolder` when `count($folders) === 1` so existing single-folder consumers/tests remain compatible.
  4. Update `AddExistingVaultDialog.vue` to share `applySingleFolder()` and `applyBatchFolders()` helpers between web (`onFoldersSelected`) and desktop (`chooseFolder()` `onFlash`), handle `pickedFolders` (with `pickedFolder` fallback), clear stale validation errors on mode transitions/resets, and change the batch-mode "Choose different folders…" button from `@click="browserOpen = true"` to `@click="onChooseFolderClick"` with `:disabled="browsing"`.
- **Alternatives Considered**:
  - *Changing `chooseDirectory()` to accept a `$multiple` boolean and return `string|array|null`* — Rejected because union return types weaken static analysis (PHPStan Level 7) and complicate single-picker callers like `StorageController`.
  - *Removing `pickedFolder` flash completely* — Rejected because keeping `pickedFolder` when `count($folders) === 1` maintains backwards compatibility and matches existing expectations with minimal overhead.
  - *Adding a separate `/vaults/existing/browse-multiple` endpoint* — Rejected because `POST /vaults/existing/browse` is already dedicated to the "Add existing folder" dialog (`ExistingVaultController::browse`).
- **Decision Records**: None (local extension of existing service/flash pattern; no architectural boundary changes).

### Data Model Changes
None.

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Service | `app/Services/NativeDialogService.php` | Add `chooseDirectories()` returning `list<string>` with `multiSelections`; extract shared private `openDirectoryDialog()` helper; keep `chooseDirectory()` intact. |
| Controller | `app/Http/Controllers/ExistingVaultController.php` | Update `browse()` to call `chooseDirectories()` and flash `pickedFolders` (and `pickedFolder` when 1 folder is selected). |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Component | `resources/js/components/vaults/AddExistingVaultDialog.vue` | Handle `pickedFolders` (and `pickedFolder` fallback) in `chooseFolder()` `onFlash`; transition cleanly between `single` and `batch` modes; wire batch-mode "Choose different folders…" to `onChooseFolderClick` with `:disabled="browsing"`. |

### Routes
No route additions or changes (`POST /vaults/existing/browse` -> `vaults.existing.browse` -> `ExistingVaultController@browse`, `POST /vaults/existing/batch` -> `vaults.existing.batch` -> `ExistingVaultController@batchStore`).

---

## 3. Implementation Tasks
- [ ] **T1 — Add `NativeDialogService::chooseDirectories()` and shared helper**
  - Files: `app/Services/NativeDialogService.php`
  - Details:
    - Extract private helper `openDirectoryDialog(string $title, string $buttonLabel, array $properties, ?string $defaultPath): mixed` that checks `$this->isAvailable()`, constructs `Dialog::new()->title($title)->button($buttonLabel)->properties($properties)`, applies `defaultPath($defaultPath)` only when `$defaultPath !== null && is_dir($defaultPath)`, and returns `$dialog->open()`.
    - Refactor `chooseDirectory(string $title, ?string $defaultPath = null): ?string` to call `openDirectoryDialog($title, 'Select folder', ['openDirectory', 'createDirectory'], $defaultPath)` and return `is_string($result) && $result !== '' ? $result : null`.
    - Add `chooseDirectories(string $title, ?string $defaultPath = null): array` with `@return list<string>` PHPDoc, calling `openDirectoryDialog($title, 'Select folders', ['openDirectory', 'createDirectory', 'multiSelections'], $defaultPath)`. If `! is_array($result)`, return `[]`. Otherwise iterate and collect unique non-empty string entries into a 0-indexed `list<string>`.
  - Covers: FR-01, FR-06

- [ ] **T2 — Update `ExistingVaultController::browse()` for multi-folder selection**
  - Files: `app/Http/Controllers/ExistingVaultController.php`
  - Details:
    - Replace `$dialogs->chooseDirectory(...)` with `$dialogs->chooseDirectories('Choose folders to add as vaults', $paths->rootPath())`.
    - When `$chosen === []`, return `back()` without flashing.
    - Map `$chosen` into `$folders = array_map(fn (string $path): array => ['path' => $path, 'name' => $vaults->suggestedNameFor($path)], $chosen)`.
    - Call `Inertia::flash('pickedFolders', $folders);`.
    - If `count($folders) === 1`, also call `Inertia::flash('pickedFolder', $folders[0]);`.
    - Return `back()`.
  - Covers: FR-02

- [ ] **T3 — Update `AddExistingVaultDialog.vue` for desktop multi-select & batch re-selection**
  - Files: `resources/js/components/vaults/AddExistingVaultDialog.vue`
  - Details:
    - Update `PickedFolderFlash` type to include `pickedFolder?: PickedFolder` and `pickedFolders?: PickedFolder[]` where `PickedFolder = { path: string; name: string }`.
    - Extract `applySingleFolder(path: string, suggestedName?: string)` and `applyBatchFolders(paths: string[])`:
      - `applySingleFolder`: sets `mode.value = 'single'`, `form.path = path`, populates `form.name = suggestedName || suggestedNameFrom(path)` when `!form.name`, calls `form.clearErrors()`, `batchForm.reset()`, and `batchForm.clearErrors()`.
      - `applyBatchFolders`: sets `mode.value = 'batch'`, `batchForm.paths = [...paths]`, calls `batchForm.clearErrors()`, `form.reset()`, and `form.clearErrors()`.
    - Update `onFoldersSelected(paths: string[])` to delegate to `applySingleFolder(paths[0])` when `paths.length === 1` and `applyBatchFolders(paths)` when `paths.length > 1`.
    - Update `chooseFolder()` `onFlash` callback: read `pickedFolders` first (if non-empty: length 1 -> `applySingleFolder(pickedFolders[0].path, pickedFolders[0].name)`, length > 1 -> `applyBatchFolders(pickedFolders.map((folder) => folder.path))`), otherwise fall back to `pickedFolder` -> `applySingleFolder(pickedFolder.path, pickedFolder.name)`.
    - Also call `batchForm.clearErrors()` / `form.clearErrors()` in `removeBatchFolder()`, `switchToSingle()`, and `watch(open, ...)` when closing so stale errors never remain.
    - In the Batch Mode template, change the "Choose different folders…" `<Button>` from `@click="browserOpen = true"` to `@click="onChooseFolderClick"` and add `:disabled="browsing"`.
  - Covers: FR-03, FR-04, FR-05

- [ ] **T4 — Add and update automated tests**
  - Files: `tests/Feature/NativeDialogServiceTest.php`, `tests/Feature/Vaults/ExistingVaultTest.php`, `tests/Unit/ArchitectureTest.php` (verify only)
  - Details:
    - In `tests/Feature/NativeDialogServiceTest.php`, add tests for `chooseDirectories`:
      1. Returns `[]` and sends no HTTP requests when outside desktop runtime (`nativephp-internal.running` false).
      2. Sends `openDirectory`, `createDirectory`, and `multiSelections` in `properties` to `dialog/open` and returns all picked directory paths.
      3. Returns a single-item list when one directory is picked.
      4. Returns `[]` on cancellation for both `['result' => []]` and `['result' => null]` responses.
      5. Filters out empty strings, non-string values, and duplicate entries while preserving order.
      6. Sends `defaultPath` only when the directory exists on disk (`null` otherwise).
    - In `tests/Feature/Vaults/ExistingVaultTest.php`:
      1. Update the single-folder `browse` test to assert both `pickedFolder` and `pickedFolders` (single-item array) are flashed and `multiSelections` was sent in `properties`.
      2. Add a multi-folder `browse` test asserting `pickedFolders` flashes all `{ path, name }` entries in order, `pickedFolder` is missing (`assertInertiaFlashMissing('pickedFolder')`), `multiSelections` was sent, and no `Vault` records are created.
      3. Update the cancel `browse` test to assert both `pickedFolder` and `pickedFolders` are missing, and cover `['result' => null]` cancellation.
    - Run `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`, `npm run check`, `npm run types:check`, and the Pest test scope.
  - Covers: FR-01, FR-02, FR-05, FR-06

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/NativeDialogServiceTest.php` | `chooseDirectories` unavailable outside desktop runtime, multi-selection payload & `multiSelections` property, single-item return, `[]` and `null` cancellation, filtering invalid/duplicate entries, `defaultPath` existence check, plus existing `chooseDirectory` regression | FR-01 |
| `tests/Feature/Vaults/ExistingVaultTest.php` | `browse` 404 outside desktop runtime, single folder flashes `pickedFolders` + `pickedFolder`, multiple folders flash `pickedFolders` without `pickedFolder`, cancellation flashes neither (`[]` and `null`), plus existing single-vault store tests | FR-02 |
| `tests/Feature/Vaults/ExistingVaultBatchTest.php` | Existing regression coverage for `POST /vaults/existing/batch` (multi-vault registration, partial skips, full validation failure, single-item redirect) | FR-05 |
| `tests/Feature/Settings/StorageSettingsTest.php` | Regression coverage confirming single-folder `StorageController::browse()` (`chooseDirectory`) remains unaffected | FR-01 |
| `tests/Unit/ArchitectureTest.php` | Verifies `Native\Desktop\Dialog` is used only in `App\Services\NativeDialogService` and all service/HTTP boundary rules pass | FR-06 |

**Test scope for QA**:
- `php artisan test --compact tests/Feature/NativeDialogServiceTest.php tests/Feature/Vaults/ExistingVaultTest.php tests/Feature/Vaults/ExistingVaultBatchTest.php tests/Feature/Settings/StorageSettingsTest.php tests/Unit/ArchitectureTest.php`
- `vendor/bin/phpstan analyse`
- `npm run check`
- `npm run types:check`

---

## 5. Risks & Mitigations
- **Risk**: In Electron, cancelling `dialog.showOpenDialogSync` returns `undefined`, which serializes as `{}` (`json('result') === null`), unlike test mocks that return `['result' => []]`. — **Mitigation**: Guard `! is_array($result)` in `NativeDialogService::chooseDirectories()` and explicitly test both `['result' => []]` and `['result' => null]`.
- **Risk**: `Dialog::folders()->multiple()` in `nativephp/desktop` manipulates `$this->properties` via `array_diff`, risking non-sequential integer keys that serialize to JSON objects instead of arrays. — **Mitigation**: Explicitly call `->properties(['openDirectory', 'createDirectory', 'multiSelections'])` with a 0-indexed list.
- **Risk**: TypeScript/linter check (`vp check` with `denyWarnings: true` and `typeAware: true` + `vue-tsc --noEmit`) rejects loose `flash` casts or unused variables in `AddExistingVaultDialog.vue`. — **Mitigation**: Define explicit `PickedFolder` and `PickedFolderFlash` types and include `npm run check` and `npm run types:check` in QA test scope.

---

## 6. Open Questions
None.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-21 | Initial plan | — |
