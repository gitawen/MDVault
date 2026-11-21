# Implementation: Desktop Multi-Select for Existing Vaults

## Metadata
- **Feature Name**: Desktop Multi-Select for Existing Vaults
- **Feature ID**: desktop-multi-select-existing-vaults
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Add `NativeDialogService::chooseDirectories()` and shared helper | DONE | Extracted `openDirectoryDialog()`, refactored `chooseDirectory()`, added `chooseDirectories()` returning deduplicated non-empty `list<string>`. |
| T2 — Update `ExistingVaultController::browse()` for multi-folder selection | DONE | Calls `chooseDirectories()`, skips flash on `[]`, flashes `pickedFolders` (`{ path, name }[]`) and single-item `pickedFolder` for backwards compatibility. |
| T3 — Update `AddExistingVaultDialog.vue` for desktop multi-select & batch re-selection | DONE | Added `PickedFolder` type, `applySingleFolder()` / `applyBatchFolders()` helpers, `pickedFolders` + `pickedFolder` flash handling, error clearing across state transitions, and wired batch mode's "Choose different folders…" button to `onChooseFolderClick` with `:disabled="browsing"`. |
| T4 — Add and update automated tests | DONE | Added 6 test cases (7 dataset runs) for `chooseDirectories` in `NativeDialogServiceTest.php` and updated/added single, multi, and cancel browse tests in `ExistingVaultTest.php`. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| modified | `app/Services/NativeDialogService.php` | Added private `openDirectoryDialog()` helper, refactored `chooseDirectory()`, and added `chooseDirectories()`. |
| modified | `app/Http/Controllers/ExistingVaultController.php` | Updated `browse()` to call `chooseDirectories()` and flash `pickedFolders` (plus `pickedFolder` when count is 1). |
| modified | `resources/js/components/vaults/AddExistingVaultDialog.vue` | Added `PickedFolder`, updated `PickedFolderFlash`, extracted `applySingleFolder()`/`applyBatchFolders()`, updated `chooseFolder()` `onFlash` and `onFoldersSelected()`, added `clearErrors()` calls, and updated batch re-select button to `@click="onChooseFolderClick"` with `:disabled="browsing"`. |
| modified | `tests/Feature/Services/NativeDialogServiceTest.php` | Added tests covering `chooseDirectories()` availability check, `multiSelections` property, single/multi returns, cancellation (`[]`/`null`), filtering/deduplication, and `defaultPath`. |
| modified | `tests/Feature/Vaults/ExistingVaultTest.php` | Updated single-folder and cancel browse tests (`pickedFolder` + `pickedFolders`, `multiSelections` assertion) and added multi-folder browse test. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | passed (`{"tool":"pint","result":"passed"}`) |
| `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"; php artisan test --compact tests/Feature/Services/NativeDialogServiceTest.php tests/Feature/Vaults/ExistingVaultTest.php tests/Feature/Vaults/ExistingVaultBatchTest.php tests/Feature/Settings/StorageSettingsTest.php` | 49 passed, 183 assertions (6970ms) |
| `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"; php artisan test --compact tests/Unit/ArchitectureTest.php` | 24 passed, 2 pre-existing failures in untouched files (see details below) |
| `vendor/bin/phpstan analyse app/Services/NativeDialogService.php app/Http/Controllers/ExistingVaultController.php` | passed, 0 errors (full `vendor/bin/phpstan analyse` had 2 pre-existing errors in untouched files; see below) |
| `npm.cmd run types:check` | passed (`vue-tsc --noEmit`, exit code 0) |
| `.\node_modules\.bin\vp.cmd check --fix resources/js/components/vaults/AddExistingVaultDialog.vue` | passed (formatting completed, 0 warnings/lint errors in 1 file) |

### Failure & Environment Output Details
1. **Stale `bootstrap/cache/config.php` in workspace**: Running `php artisan test` without overriding `APP_CONFIG_CACHE` loads `bootstrap/cache/config.php` (dated `11/20/2026 8:46 am`), which hardcodes `C:/Users/cherw/AppData/Roaming/mdvault/database/database.sqlite` and fails with:
   ```text
   SQLSTATE[HY000]: General error: 10 disk I/O error (Connection: sqlite, Database: C:/Users/cherw/AppData/Roaming/mdvault/database/database.sqlite, SQL: select exists (select 1 from "main".sqlite_master where name = 'migrations' and type = 'table') as "exists")
   ```
   Running with `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"` (without deleting the existing cache file) restored `phpunit.xml` `:memory:` SQLite config and all 49 feature tests passed.
2. **Pre-existing `ArchitectureTest.php` failures (untouched files)**:
   ```text
   P\Tests\Unit\ArchitectureTest::__pest_evaluable_services_do_not_depend_on_the_HTTP_layer
   Expecting 'App\Services' not to use 'Illuminate\Http'. (app/Services/MediaService.php:9 uses Illuminate\Http\UploadedFile)

   P\Tests\Unit\ArchitectureTest::__pest_evaluable_vault_records_are_only_used_by_services__controllers__models_and_factories
   Expecting 'App\Models\Vault' not to be used on 'App\Support\VaultBatchResult'.
   ```
3. **Pre-existing `vendor/bin/phpstan analyse` errors (untouched files)**:
   ```text
   app/Http/Middleware/HandleInertiaRequests.php:51 - Cannot cast non-empty-array|non-falsy-string to string. (cast.string)
   app/Services/DirectoryBrowserService.php:287 - Parameter #1 $array of array_values is already a list, call has no effect. (arrayValues.list)
   ```
4. **Pre-existing `npm.cmd run check` (`vp check`) formatting issues (untouched files)**:
   ```text
   resources/js/pages/settings/Editor.vue
   resources/js/pages/settings/Mcp.vue
   tests/js/editor/codemirrorLivePreview.test.ts
   ```

---

## 4. Deviations from Plan
- **T3 (`chooseFolder()` callback)**: The prompt mentioned placing the flash handling inside `onSuccess`, whereas Inertia v3 passes flashed data to `onFlash: (flash) => ...` (already used in `AddExistingVaultDialog.vue` and sibling settings pages `Storage.vue` and `Backup.vue`). Implemented the `pickedFolders` / `pickedFolder` handling inside `onFlash`. No functional impact; `onFlash` is the intended Inertia v3 hook.

---

## 5. Notes for QA
- **Test environment**: Note the pre-existing `bootstrap/cache/config.php` in the workspace — QA should run `php artisan test` with `APP_CONFIG_CACHE` pointing away (or ask before running `php artisan config:clear`) so tests run against `:memory:` instead of the live desktop SQLite file.
- **Manual desktop check**: Verify in the Electron desktop runtime (`canBrowse === true`) that selecting 1 folder via the OS dialog populates single mode, selecting multiple folders opens batch mode, and clicking "Choose different folders…" in batch mode re-opens the native OS multi-folder dialog rather than `DirectoryBrowserDialog`.
- **Single-mode name preservation**: When in single mode with a user-edited `form.name`, picking a single folder via OS or web dialog preserves `form.name` (`if (!form.name)`), whereas coming from batch mode resets `form` first so `form.name` adopts the newly picked folder's name.

---

## 6. Fix Rounds

### Fix Round 1
- **Issue Addressed**: `QA-01`
- **File Modified**: `resources/js/components/vaults/AddExistingVaultDialog.vue` (`removeBatchFolder(index)`)
- **Change**: Moved `batchForm.clearErrors()` immediately after `batchForm.paths.splice(index, 1)` so stale batch validation errors are cleared unconditionally whenever any folder is removed from the batch list (including when 2+ folders remain), removing the redundant `batchForm.clearErrors()` calls in the `length === 1` and `length === 0` branches.
- **Verification**:
  - `.\node_modules\.bin\vp.cmd check --fix resources/js/components/vaults/AddExistingVaultDialog.vue`: passed (formatting completed; 0 warnings, 0 lint errors in 1 file).
  - `npm.cmd run types:check`: passed (`vue-tsc --noEmit`, exit code 0).
