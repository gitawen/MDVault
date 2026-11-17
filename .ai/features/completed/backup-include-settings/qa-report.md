# QA Report: Include Application Settings in Backups

## Metadata
- **Feature Name**: Include Application Settings in Backups
- **Feature ID**: `backup-include-settings`
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-11-17
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 (Portable Settings Classification) | yes | `tests/Feature/Backups/BackupSettingsTest.php` | ✅ |
| FR-02 (Export Settings into Manifest Format 3) | yes | `tests/Feature/Backups/BackupSettingsTest.php`, `BackupCreateTest.php`, `EncryptedBackupTest.php` | ✅ |
| FR-03 (Exclude Backup History) | yes | `tests/Feature/Backups/BackupSettingsTest.php` | ✅ |
| FR-04 (Strict Settings Validation) | yes | `tests/Feature/Backups/BackupSettingsTest.php` | ✅ |
| FR-05 (Backward Compatibility Formats 1 & 2) | yes | `tests/Feature/Backups/BackupSettingsTest.php`, `BackupInspectTest.php` | ✅ |
| FR-06 (Inspection Summary & Conflict Detection) | yes | `tests/Feature/Backups/BackupSettingsTest.php`, `BackupHttpTest.php` | ✅ |
| FR-07 (Selective & Settings-Only Restore) | yes | `tests/Feature/Backups/BackupSettingsTest.php`, `BackupHttpTest.php` | ✅ |
| FR-08 (Atomic Restore & Rollback Safety) | yes | `tests/Feature/Backups/BackupSettingsTest.php`, `BackupRestoreTest.php` | ✅ |
| FR-09 (Live UI & Theme Propagation) | yes | `tests/Feature/Backups/BackupSettingsTest.php` (`appearance` prop), `npm.cmd run types:check`, `npm.cmd run test:js` | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups tests/Feature/Settings` | 205 passed, 0 failed (1124 assertions) |
| `php artisan test --compact tests/Feature/Backups tests/Feature/Settings tests/Unit/ArchitectureTest.php` | 229 passed, 2 pre-existing failures in `tests/Unit/ArchitectureTest.php` (1191 assertions) |
| `vendor/bin/phpstan analyse` | 2 pre-existing errors in untouched lines; 0 errors in feature changes |
| `npm.cmd run types:check` | Passed (`vue-tsc --noEmit` exit code 0) |
| `npm.cmd run test:js` | Passed (20 test files, 293 tests) |

*Note on `npm run types:check` in PowerShell*: `npm` resolved to `npm.ps1` which is blocked by Windows PowerShell execution policy; `npm.cmd run types:check` was executed and passed.

*Failure output from `php artisan test --compact tests/Feature/Backups tests/Feature/Settings tests/Unit/ArchitectureTest.php` (pre-existing):*
```text
{"tool":"pest","result":"failed","tests":231,"passed":229,"assertions":1191,"duration_ms":53397,"failed":2,"failures":[{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_services_do_not_depend_on_the_HTTP_layer","file":"\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php","line":176,"message":"Expecting 'App\\Services' not to use 'Illuminate\\Http'.","trace":["C:\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php:176"]},{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_vault_records_are_only_used_by_services__controllers__models_and_factories","file":"\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php","line":5,"message":"Expecting 'App\\Models\\Vault' not to be used on 'App\\Support\\VaultBatchResult'.","trace":["C:\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php:5"]}]}
```

*Failure output from `vendor/bin/phpstan analyse` (pre-existing):*
```text
{"tool":"phpstan","result":"failed","errors":2,"error_details":{"C:\\Users\\cherw\\Herd\\MDVault\\app\\Http\\Middleware\\HandleInertiaRequests.php":[{"line":51,"message":"Cannot cast non-empty-array|non-falsy-string to string.","identifier":"cast.string"}],"C:\\Users\\cherw\\Herd\\MDVault\\app\\Services\\DirectoryBrowserService.php":[{"line":287,"message":"Parameter #1 $array (list<array{name: string, path: non-falsy-string}>) of array_values is already a list, call has no effect.","identifier":"arrayValues.list"}]}}
```

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Low | MINOR | `app/Exceptions/BackupException.php:166-169` | `BackupException::nothingSelected()` still reads `'Choose at least one vault to restore.'`, whereas `RestoreBackupRequest::withValidator()` reads `'Choose at least one vault or setting group to restore.'`. | Update `nothingSelected()` message to `'Choose at least one vault or setting group to restore.'` so direct service callers and HTTP callers match. | 0 |
| QA-02 | Low | MINOR | `resources/js/components/backups/RestoreBackupDialog.vue:49-58` | `load()` clears `actions` and `selectedGroups`, but does not call `form.clearErrors()`. If a previous restore submission failed validation and the user inspects another backup, stale `form.errors` remain visible until the next submit. | Call `form.clearErrors()` when `load()` starts (or on dialog close). | 0 |

---

## 4. Code Review Notes
- **Security & Authorization**: Verified that `SettingKey::isPortable()` strictly excludes `storage.root_path`, `storage.folder_name`, and `app.current_vault`; `BackupService::normalizeAndValidateSettings()` rejects injected non-portable keys and ignores unknown keys; `backups` table rows are never exported or restored; and `App\Models\Setting` is accessed solely via `SettingsService`.
- **Validation & Data Integrity**: Verified parity between `BackupService::validatePortableSettingValue()` and Settings Form Requests (enums, bounds, `SettingKey::SECURITY_AUTO_LOCK_CHOICES`, UTF-8/length/NUL-byte checks, `MarkdownService::assertValidFrontmatterYaml()`, non-empty template when enabled, CRLF/trailing-newline normalization). Float serialization (`JSON_PRESERVE_ZERO_FRACTION`) and epsilon comparison (`1e-9`) prevent false diffs. Combined vault + settings restores run inside one database transaction (`SettingsService::setMany()` after directory moves), and settings-only restores skip storage-root and staging checks.
- **Performance (N+1, indexes)**: `SettingsService::load()` memoizes settings in a single query and `setMany()` batches writes in one transaction. `inspect()` queries `Vault::query()->doesntExist()` once for `default_restore`.
- **Conventions (AGENTS.md)**: Exhaustive `match` expressions, explicit return types, curly braces on all control structures, single root elements in Vue SFCs, Wayfinder route imports (`@/routes/...`, `@/actions/...`), and `SettingKey::SECURITY_AUTO_LOCK_CHOICES` avoids `App\Services` -> `App\Http` dependency.
- **Frontend (Inertia/Vue/Wayfinder)**: `HandleInertiaRequests` lazily shares `'appearance'`, `global.d.ts` types it on `sharedPageProps`, `AppLayout.vue` watches it and calls `syncAppearance()`, and `RestoreBackupDialog.vue` binds accessible `<Label :for="...">` / `<Checkbox :id="...">` controls.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/` (optional Low follow-ups `QA-01`, `QA-02` documented for future cleanup if desired)

---

### Summary
- **Verdict**: PASS
- **Counts by severity**: Critical: 0, High: 0, Medium: 0, Low: 2 (`QA-01`, `QA-02`)
- **Routing**: Feature passes QA and may proceed to `.ai/features/completed/` (optional Low items `QA-01`, `QA-02` for Senior Developer if desired).