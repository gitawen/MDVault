# Implementation: Include Application Settings in Backups

## Metadata
- **Feature Name**: Include Application Settings in Backups
- **Feature ID**: `backup-include-settings`
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Classify portable settings and group labels on enums | DONE | Added `label()` and `isPortable()` to `SettingGroup`; added `isPortable()`, `portableCases()`, `portableGroups()`, `portableGroupValues()`, and `SECURITY_AUTO_LOCK_CHOICES` to `SettingKey`. |
| T2 — Extend BackupException, BackupInspection, and RestoreResult DTOs | DONE | Added `BackupException::noSettingsInBackup()`, updated `BackupInspection` `$backup` PHPDoc shape, and added `settingsRestored` + `restoredSettingGroups` + updated `summary()` to `RestoreResult`. |
| T3 — Implement settings backup, validation, inspection, and restore in BackupService | DONE | Bumped `FORMAT_VERSION` to `3`, injected `SettingsService` and `MarkdownService`, exported sorted portable settings with `JSON_PRESERVE_ZERO_FRACTION`, validated `"settings"` in `validateArchive()`, summarized settings diffs & `default_restore` in `inspect()`, added selective & settings-only atomic restore in `restore()`, and updated the 3 `format_version` assertions in `BackupCreateTest.php` and `EncryptedBackupTest.php`. |
| T4 — Update RestoreBackupRequest, BackupController, and BackupRestoreController | DONE | Validated `setting_groups` in `RestoreBackupRequest`, allowed all-skip vaults when `setting_groups` is non-empty, passed `$request->settingGroups()` to `BackupService::restore()`, redirected settings-only restores to `settings.backup.edit`, and updated backup creation toast copy. |
| T5 — Share appearance via Inertia and synchronize theme live on client | DONE | Shared `appearance` lazily in `HandleInertiaRequests`, added `appearance` to `InertiaConfig['sharedPageProps']` in `global.d.ts`, exported `syncAppearance()` in `useAppearance.ts`, and watched `page.props.appearance` in `AppLayout.vue`. |
| T6 — Update Backup/Restore frontend types and UI | DONE | Added `BackupSettingsGroupSummary` and `BackupInspectionSettings` in `backups.ts`, updated `Backup.vue` copy, and added per-group settings selection, conflict indicators, warnings, and settings-only submission in `RestoreBackupDialog.vue`. |
| T7 — Add comprehensive Pest test suite for settings in backups | DONE | Created `tests/Feature/Backups/BackupSettingsTest.php` covering all 10 scenarios across FR-01 through FR-09. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| modified | `app/Enums/SettingGroup.php` | Added `label()` and `isPortable()`. |
| modified | `app/Enums/SettingKey.php` | Added `SECURITY_AUTO_LOCK_CHOICES`, `isPortable()`, `portableCases()`, `portableGroups()`, and `portableGroupValues()`. |
| modified | `app/Exceptions/BackupException.php` | Added `noSettingsInBackup()` factory method targeting field `'setting_groups'`. |
| modified | `app/Support/BackupInspection.php` | Updated `$backup` PHPDoc shape to include `settings` summary metadata. |
| modified | `app/Support/RestoreResult.php` | Added `settingsRestored` and `restoredSettingGroups` properties and updated `summary()`. |
| modified | `app/Services/BackupService.php` | Implemented format-3 settings export, strict validation, diff/default inspection summary, and atomic selective & settings-only restore. |
| modified | `app/Http/Requests/Settings/RestoreBackupRequest.php` | Added `setting_groups` validation rules, updated `withValidator()`, and added `settingGroups()`. |
| modified | `app/Http/Requests/Settings/UpdateSecuritySettingsRequest.php` | Aliased `AUTO_LOCK_CHOICES` to `SettingKey::SECURITY_AUTO_LOCK_CHOICES`. |
| modified | `app/Http/Controllers/Settings/BackupController.php` | Updated success toast copy to mention settings inclusion. |
| modified | `app/Http/Controllers/Settings/BackupRestoreController.php` | Passed `settingGroups()` to `BackupService::restore()` and redirected settings-only restores to `settings.backup.edit`. |
| modified | `app/Http/Middleware/HandleInertiaRequests.php` | Shared `'appearance'` prop lazily. |
| modified | `resources/js/types/backups.ts` | Added `BackupSettingsGroupSummary`, `BackupInspectionSettings`, and `backup.settings`. |
| modified | `resources/js/types/global.d.ts` | Added `appearance: Appearance` to `InertiaConfig.sharedPageProps`. |
| modified | `resources/js/composables/useAppearance.ts` | Exported `syncAppearance(value: Appearance)`. |
| modified | `resources/js/layouts/AppLayout.vue` | Watched `page.props.appearance` to call `syncAppearance()`. |
| modified | `resources/js/pages/settings/Backup.vue` | Updated backup/restore descriptions and destination info box copy. |
| modified | `resources/js/components/backups/RestoreBackupDialog.vue` | Added per-group settings checkboxes, conflict badges/warnings, legacy format note, and settings-only restore support. |
| modified | `tests/Feature/Backups/BackupCreateTest.php` | Updated expected `format_version` from `2` to `3`. |
| modified | `tests/Feature/Backups/EncryptedBackupTest.php` | Updated expected `format_version` from `2` to `3` in 2 tests. |
| created | `tests/Feature/Backups/BackupSettingsTest.php` | Added 10 Pest feature tests covering FR-01 through FR-09. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | Passed (`{"tool":"pint","result":"passed"}`) |
| `php artisan test --compact tests/Feature/Backups/BackupSettingsTest.php` | Passed (10 passed, 249 assertions) |
| `php artisan test --compact tests/Feature/Backups tests/Feature/Settings` | Passed (205 passed, 1124 assertions) |
| `php artisan test --compact tests/Feature/Backups tests/Feature/Settings tests/Unit/ArchitectureTest.php` | 229 passed, 2 pre-existing failures in `tests/Unit/ArchitectureTest.php` (pasted below) |
| `vendor/bin/phpstan analyse` | 2 pre-existing errors in untouched code (`HandleInertiaRequests.php:51`, `DirectoryBrowserService.php:287`); 0 errors in our new/modified lines (pasted below) |
| `npm.cmd run types:check` | Passed (`vue-tsc --noEmit` exit code 0) |
| `.\node_modules\.bin\vp.cmd check resources/js/components/backups/RestoreBackupDialog.vue resources/js/pages/settings/Backup.vue resources/js/composables/useAppearance.ts resources/js/layouts/AppLayout.vue resources/js/types/backups.ts resources/js/types/global.d.ts` | Passed (0 formatting/lint warnings or errors) |
| `npm.cmd run test:js` | Passed (20 test files, 293 tests) |

*Failure output from `php artisan test --compact tests/Feature/Backups tests/Feature/Settings tests/Unit/ArchitectureTest.php`:*
```text
{"tool":"pest","result":"failed","tests":231,"passed":229,"assertions":1191,"duration_ms":57528,"failed":2,"failures":[{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_services_do_not_depend_on_the_HTTP_layer","file":"\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php","line":176,"message":"Expecting 'App\\Services' not to use 'Illuminate\\Http'.","trace":["C:\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php:176"]},{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_vault_records_are_only_used_by_services__controllers__models_and_factories","file":"\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php","line":5,"message":"Expecting 'App\\Models\\Vault' not to be used on 'App\\Support\\VaultBatchResult'.","trace":["C:\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php:5"]}]}
```
*(Note: The two `ArchitectureTest` failures come from pre-existing files `app/Services/MediaService.php:9` (`use Illuminate\Http\UploadedFile;`) and `app/Support/VaultBatchResult.php:5`.)*

*Failure output from `vendor/bin/phpstan analyse`:*
```text
{"tool":"phpstan","result":"failed","errors":2,"error_details":{"C:\\Users\\cherw\\Herd\\MDVault\\app\\Http\\Middleware\\HandleInertiaRequests.php":[{"line":51,"message":"Cannot cast non-empty-array|non-falsy-string to string.","identifier":"cast.string"}],"C:\\Users\\cherw\\Herd\\MDVault\\app\\Services\\DirectoryBrowserService.php":[{"line":287,"message":"Parameter #1 $array (list<array{name: string, path: non-falsy-string}>) of array_values is already a list, call has no effect.","identifier":"arrayValues.list"}]}}
```

---

## 4. Deviations from Plan
- **Auto-lock choices constant location (T3)**: The plan specified referencing `UpdateSecuritySettingsRequest::AUTO_LOCK_CHOICES` inside `BackupService::validateArchive()`. Importing an `App\Http\Requests\...` class inside `App\Services\BackupService` violates the `ArchitectureTest.php` rule `services do not depend on the HTTP layer` (`not->toUse(['App\Http', ...])`). To keep a single source of truth without violating the architecture boundary, `SettingKey::SECURITY_AUTO_LOCK_CHOICES = [0, 5, 15, 30, 60]` was added on `App\Enums\SettingKey`, `UpdateSecuritySettingsRequest::AUTO_LOCK_CHOICES` was aliased to `SettingKey::SECURITY_AUTO_LOCK_CHOICES`, and `BackupService` validates against `SettingKey::SECURITY_AUTO_LOCK_CHOICES`.
- **Architecture test filename**: The plan references `tests/Unit/ArchTest.php`; the repository's actual file path is `tests/Unit/ArchitectureTest.php`.
- **`RestoreBackupRequest` & `BackupService::restore()` dead check cleanup**: Removed redundant `array_values()` around `array_map()` in `RestoreBackupRequest::settingGroups()` and removed the unreachable `if ($plan === [])` check after `$selectedVaults` was already verified non-empty in `BackupService::restore()` to satisfy PHPStan level 7.

---

## 5. Notes for QA
- Note that the repository has 2 pre-existing `tests/Unit/ArchitectureTest.php` failures (`MediaService` using `Illuminate\Http\UploadedFile` and `VaultBatchResult` using `App\Models\Vault`) and 2 pre-existing PHPStan errors (`HandleInertiaRequests.php:51`, `DirectoryBrowserService.php:287`), none introduced by this feature.
- Verify interactive behavior of `RestoreBackupDialog.vue` in the browser/desktop runtime:
  - Per-group checkboxes (`Appearance`, `Editor`, `General`, `Security`) auto-checked on a fresh unconfigured install (`default_restore: true`) and unchecked otherwise (`default_restore: false`).
  - Settings-only restore (all vaults `Skip`, at least one setting group checked) enables the Restore button and redirects back to Settings -> Backup with toast `"Restored settings."`.
  - Restoring `appearance.theme` immediately switches light/dark mode across the app without page reload.

---

## 6. Fix Rounds
*None yet.*
