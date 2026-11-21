# QA Report: Desktop Multi-Select Existing Vaults

## Metadata
- **Feature Name**: Desktop Multi-Select Existing Vaults
- **Feature ID**: desktop-multi-select-existing-vaults
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-11-21
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 — Native Multi-Directory Picker (`NativeDialogService::chooseDirectories`) | yes | `tests/Feature/Services/NativeDialogServiceTest.php` | ✅ |
| FR-02 — Vault Browse Endpoint Multi-Folder Flash (`ExistingVaultController::browse`) | yes | `tests/Feature/Vaults/ExistingVaultTest.php` | ✅ |
| FR-03 — Desktop Single vs. Batch Mode Transition (`AddExistingVaultDialog.vue`) | yes | Code inspection + `npm.cmd run types:check` + `vp check` (no Vue unit test runner configured for components) | ✅ |
| FR-04 — Native Re-Selection in Batch Mode ("Choose different folders…") | yes | Code inspection + `npm.cmd run types:check` + `vp check` | ✅ |
| FR-05 — Batch Submission & State Cleanup Consistency | partial (edge case in `removeBatchFolder` when >=2 paths remain — see `QA-01`) | `tests/Feature/Vaults/ExistingVaultBatchTest.php` + code inspection of `AddExistingVaultDialog.vue` | ✅ |
| FR-06 — Architecture Boundary Compliance | yes | `tests/Unit/ArchitectureTest.php` (`--filter="native dialogs"` passed; 2 pre-existing unrelated failures noted below) | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"; php artisan test --compact tests/Feature/Services/NativeDialogServiceTest.php tests/Feature/Vaults/ExistingVaultTest.php tests/Feature/Vaults/ExistingVaultBatchTest.php tests/Feature/Settings/StorageSettingsTest.php tests/Unit/ArchitectureTest.php` | 73 passed, 2 failed (both pre-existing in untouched files inside `ArchitectureTest.php`; 250 assertions) |
| `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"; php artisan test --compact tests/Feature/Services/NativeDialogServiceTest.php tests/Feature/Vaults/ExistingVaultTest.php tests/Feature/Vaults/ExistingVaultBatchTest.php tests/Feature/Settings/StorageSettingsTest.php` | 49 passed, 0 failed (183 assertions, 2377ms) |
| `$env:APP_CONFIG_CACHE="bootstrap/cache/nonexistent-test-config.php"; php artisan test --compact tests/Unit/ArchitectureTest.php --filter="native dialogs"` | 1 passed, 0 failed (1 assertion) |
| `vendor/bin/phpstan analyse app/Services/NativeDialogService.php app/Http/Controllers/ExistingVaultController.php` | passed, 0 errors |
| `npm.cmd run types:check` (`vue-tsc --noEmit`) | passed, exit code 0 (`npm` `.ps1` shim blocked by Windows PowerShell policy; executed via `npm.cmd`) |
| `.\node_modules\.bin\vp.cmd check resources/js/components/vaults/AddExistingVaultDialog.vue` | passed (1 file formatted, 0 warnings, 0 lint errors; `npx` `.ps1` shim blocked by Windows PowerShell policy) |

### Failure Output Details (Pre-Existing in Untouched Files)
```text
{"tool":"pest","result":"failed","tests":75,"passed":73,"assertions":250,"duration_ms":8994,"failed":2,"failures":[{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_services_do_not_depend_on_the_HTTP_layer","file":"\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php","line":176,"message":"Expecting 'App\\Services' not to use 'Illuminate\\Http'.","trace":["C:\\Users\\cherw\\Herd\\MDVault\\vendor\\pestphp\\pest-plugin-arch\\src\\SingleArchExpectation.php:176"]},{"test":"P\\Tests\\Unit\\ArchitectureTest::__pest_evaluable_vault_records_are_only_used_by_services__controllers__models_and_factories","file":"\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php","line":5,"message":"Expecting 'App\\Models\\Vault' not to be used on 'App\\Support\\VaultBatchResult'.","trace":["C:\\Users\\cherw\\Herd\\MDVault/app\\Support\\VaultBatchResult.php:5"]}]}
```

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Medium | MINOR | `resources/js/components/vaults/AddExistingVaultDialog.vue:91-107` | In `removeBatchFolder(index)`, `batchForm.clearErrors()` is only called when `batchForm.paths.length` drops to `1` or `0`. If a previous batch submission set `batchForm.errors.paths` with 3+ selected folders and the user removes one folder (leaving 2+ folders in batch mode), the stale `paths` validation error remains displayed. | Call `batchForm.clearErrors()` unconditionally inside `removeBatchFolder(index)` whenever a folder is removed from the batch list. | 0 |

---

## 4. Code Review Notes
- **Security & Authorization**: `ExistingVaultController::browse()` enforces `abort_unless($dialogs->isAvailable(), 404)` outside the desktop runtime and performs no filesystem or database writes. Batch submissions continue through `RegisterVaultBatchRequest` (`min:1`, `max:50`, `distinct`) and `VaultService::register()`.
- **Validation & Data Integrity**: `NativeDialogService::chooseDirectories()` safely handles `null` (Electron `undefined` on cancel), non-array values, empty strings, non-string entries, and duplicate paths while preserving selection order. Explicit `->properties(['openDirectory', 'createDirectory', 'multiSelections'])` avoids `array_diff` key-preservation issues.
- **Performance (N+1, indexes)**: No database queries or directory scans are introduced in `NativeDialogService::chooseDirectories()` or `ExistingVaultController::browse()`.
- **Conventions (AGENTS.md, `.ai/rules/`)**: PHP changes use strict return types, PHPDoc `@return list<string>` / `@param list<string>`, curly braces on all control structures, and pass PHPStan Level 7 with 0 errors. `Native\Desktop\Dialog` remains encapsulated in `NativeDialogService` (passing `native dialogs only via NativeDialogService`). Note that `tests/Unit/ArchitectureTest.php` has two pre-existing baseline failures in untouched files (`app/Services/MediaService.php` and `app/Support/VaultBatchResult.php`).
- **Frontend (Inertia/Vue/Wayfinder)**: `AddExistingVaultDialog.vue` retains a single root `<Dialog>` element, uses Wayfinder route helpers (`batch`, `browse`, `store` from `@/routes/vaults/existing`), uses Inertia v3 `onFlash` cleanly with explicit TypeScript interfaces (`PickedFolder`, `PickedFolderFlash`), disables the batch re-selection button during `browsing`, and passes `vue-tsc` and `vp check`.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `AI/mdvault/features/completed/` (optional follow-up: fix `QA-01` in `AddExistingVaultDialog.vue:91-107`)
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: —
- [ ] **FAIL — MAJOR** → System Analyst: —

---

Verdict: **PASS** (0 Critical, 0 High, 1 Medium [`QA-01`], 0 Low).
Routing: Ready to move to completed; optional follow-up `QA-01` (MINOR) → Senior Developer if desired. No issues routed to System Analyst.
