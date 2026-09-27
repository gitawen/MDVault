# QA Report: Phase 2: Vault Management — Round 2 (Revision 2, §8)

## Metadata
- **Feature Name**: Phase 2: Vault Management
- **Feature ID**: mdv-p2
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Scope**: Plan Revision 2 (§8, rename also renames the folder on disk; tasks R1–R6), plus round-1 MINOR follow-ups QA-01/QA-02
- **Date**: 2026-09-27
- **Verdict**: PASS

> Zero open issues of any severity. Round-1 findings QA-01/QA-02 are confirmed fixed. Both developer deviations are assessed and accepted.

---

## 1. Requirements Coverage (Revision 2)
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-09 (revised): rename also renames the folder | yes | `VaultServiceTest` rename block (17 scenarios), `VaultManagementTest` (folder-move + toast test) | ✅ |
| FR-09a: failure/rollback semantics | yes | `VaultServiceTest`: lock-simulated failure, DB-failure-then-rename-back-success, DB-failure-then-rename-back-failure | ✅ |
| FR-09b: refusal conditions (missing folder, existing target incl. empty dir (D2), overlap, unsafe) | yes | `VaultServiceTest` scenarios 5, 6 (dataset incl. empty dir), 7, 8, 9; `FileStorageServiceTest` `renameDirectory` dataset | ✅ |
| D1 (folder always takes the new name) | yes | happy-path, outside-root, inside-root, basename-already-matches scenarios | ✅ |
| D2 (refuse existing target, including empty dir) | yes | `FileStorageServiceTest` "refuses an existing target" dataset (`empty directory` case); `VaultServiceTest` scenario 6 (`empty directory` case) | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/VaultServiceTest.php tests/Feature/Vaults/VaultManagementTest.php tests/Unit/ArchitectureTest.php` | `126 tests, 125 passed, 358 assertions, 1 skipped` (pre-existing Phase-1 Windows-only skip) — matches implementation.md exactly |
| `php artisan test --compact` (full suite) | `303 tests, 301 passed, 827 assertions, 2 skipped` (skip #1 pre-existing Phase-1; skip #2 the new `->onlyOnLinux()` cross-case-sibling test, expected on this Windows machine) — matches implementation.md exactly |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | Clean, no output |
| `npm run build` | Succeeds, new chunks built |
| `npm run check` | "All 50 files are correctly formatted"; "Found no warnings or lint errors in 43 files" |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan route:list --path=vaults` | Exactly 8 routes; static routes (`close`, `existing`, `existing/browse`) registered before `{vault:uuid}` |
| `rg -n "moveDirectory\|rename\(" app` | 4 matches: `FileStorageService.php:139` (PHPDoc comment), `FileStorageService.php:185` (the one real `moveDirectory` call, inside `attemptMove()`), `VaultService.php:177` (`rename()` method declaration), `VaultController.php:44` (calls `VaultService::rename()`, not a filesystem call). Only one actual filesystem move/rename call exists in `app/`. |
| `rg -n "moveDirectory\([^)]*true" app` | No matches — `overwrite` is always `false` |
| `rg -n "deleteDirectory\|rmdir\|unlink" app/Services` | Only `FileStorageService::deleteEmptyDirectory`'s `@rmdir` — unchanged from Revision 1 |
| `grep -rn "dd(\|dump(\|console.log\|var_dump\|TODO\|FIXME"` on the Revision-2 touched files | none |

No failures. No phpstan baseline entries added.

---

## 3. Issues
No open issues. Table intentionally empty.

| ID | Severity | Classification | Location | Description | Fix Attempts |
|---|---|---|---|---|---|
| — | — | — | — | none found | — |

**Round-1 carryover verification:**
- **QA-01** (`tests/Feature/WorkspaceTest.php`): confirmed fixed — the restart-persistence test now wraps the body in `try { ... } finally { File::deleteDirectory($tmp); }` (lines 29–44), so the temp directory is removed even on assertion failure.
- **QA-02** (`resources/js/components/vaults/RemoveVaultDialog.vue`): confirmed fixed — copy now reads "Your files stay on disk at {{ vault.path }}." (line 50–51), matching the requirement's phrasing.

**Developer deviations — assessed, both accepted, no action needed:**
1. **`Vault::flushEventListeners()` in the "rename-back failure" test** (`VaultServiceTest`, scenario 12, line 364). The test registers a `Vault::saving()` closure (guarded by `$model->exists`) to simulate a DB failure specifically on the update after a successful folder rename. After the exception is caught and asserted, the test still needs to call `$this->service->refreshStatus($vault->fresh())` to verify the vault self-heals to `Missing` — but that call performs a real `$vault->update()`, which would re-trigger the same "db down" listener and fail the test for an unrelated reason. `Vault::flushEventListeners()` deregisters the listener before that follow-up assertion. This is scoped entirely to this one test (Pest boots a fresh app/container per test, so no cross-test leakage), touches no production code, and no further `create()`/model-lifecycle-dependent calls happen afterward in the same test that would need the deregistered listeners (e.g. `HasUuids`'s `creating` listener). Confirmed the underlying assertions (folder content, `fresh()->path`, `Missing` status, `Exceptions::assertReported`) faithfully exercise the intended compensation path. Accepted as a sound test-construction fix, not a masked defect.
2. **Omission of the optional `->onlyOnWindows()` real-lock test (R3 scenario 18).** The plan explicitly permits deleting/omitting this test "if the lock can't be reproduced reliably," with manual step M2 as the fallback. `implementation.md` §6.5 documents the reasoning (opendir/fopen lock semantics on Windows are inconsistent) and the fallback (deterministic `failFolderRenames()` scenario 10 already exercises the same `renameDirectory` → `renameFailed` code path; M2 covers the real-OS-lock case manually). Accepted per the plan's own allowance.

---

## 4. Code Review — Data Safety Verification
All of the following were verified by direct code reading of `app/Services/FileStorageService.php`, `app/Services/VaultService.php`, `app/Exceptions/VaultOperationException.php`, and `app/Http/Controllers/VaultController.php`, cross-checked against §8.2 of the plan and confirmed by the passing tests listed above:

- **Order matches §8.2 exactly**: `VaultService::rename()` runs assertValidName → assertNameAvailable → (unchanged-name short-circuit, DB only) → compute `$to` → (basename-already-matches short-circuit, DB only) → `refreshStatus`/missing-check → `assertSafeFolder` → existing-target check → `assertNoOverlap` → `renameDirectory` → canonical path + `replaceLastSegment` → DB update last. **No filesystem work happens before step 5** (the two early-return branches touch only the DB via `$vault->update()`); the DB update is provably the last mutating call.
- **Compensation only renames back, never deletes**: the `catch` block in `rename()` calls `$this->files->renameDirectory($newPath, $from)` only; there is no `deleteDirectory`/`rmdir`/`unlink` anywhere in the compensation path, confirmed by the grep above.
- **`renameDirectory` never overwrites**: the pre-check `if ($this->exists($to) && ! $this->isSameDirectory($from, $to)) return false;` refuses a file, an empty directory, and a non-empty directory at the target — all three are in the `FileStorageServiceTest` dataset and the `VaultServiceTest` dataset. `moveDirectory` is only ever called with a hard-coded `false` third argument inside the private `attemptMove()` — confirmed by the `moveDirectory([^)]*true` grep returning no matches.
- **Case-only two-step rename can't lose the folder**: on a second-step failure, `attemptMove($tmp, $from)` restores the original name before returning `false`; test "case-only rename whose second step fails is restored, with no temp folder left" (`FileStorageServiceTest`) and the equivalent in `VaultServiceTest` (implicitly covered via `renameDirectory`'s own unit tests) confirm no `.mdvault-rename-*` leftover and the folder is back under its original name.
- **Description-only edit makes no filesystem calls**: `rename()`'s step 2 short-circuit (`if ($name === $vault->name)`) returns immediately after a DB-only `update()`. Verified functionally by the test that performs a description-only edit **after deleting the folder** and asserts success — proving the disk is never touched.
- **UUID and `app.current_vault` untouched**: every rename scenario in `VaultServiceTest` captures `$uuid` before the call and asserts it unchanged afterward; the dedicated "current vault stays current" test asserts the setting still equals the same UUID after a rename and that `current()->path` re-reads the new path correctly.
- **`relative_path` handling**: null stays null (folders added outside the root via "Add existing folder" — verified by the "renamed in place / stays outside the root" test asserting `relative_path` is still `null`); the last segment is replaced for a non-null value (verified by "a folder added inside the root updates relative_path", `Old`→`New`).
- **Error field routing is `name`**: all four new `VaultOperationException` constructors (`renameTargetExists`, `renameFailed`, `renameRollbackFailed`, `renameInterrupted`) hard-code the `'name'` field; every reused constructor call site in `rename()` (`folderMissing`, `unsafeFolder`, `overlapsVault`) passes `'name'` explicitly. Confirmed by direct reading and by every `VaultServiceTest` failure assertion checking `$e->field() === 'name'`.
- **Missing-vault UI state works**: `RenameVaultDialog.vue` disables the name `Input` and shows "The folder is missing, so only the description can be changed." when `vault.status === 'missing'`; the server independently enforces the same rule (a name change on a missing vault throws `folderMissing`), giving defense in depth. `VaultController::update` correctly distinguishes the "folder moved" toast from the plain "Vault updated." toast by comparing `$vault->path` before/after — both branches are tested at the HTTP layer.
- **No filesystem calls in the controller or Vue layer**: `VaultController::update` only calls `$vaults->rename(...)` and compares path strings (no `File`/raw FS calls); `RenameVaultDialog.vue`/`RemoveVaultDialog.vue` contain no path logic, only display strings using server-provided `vault.path`.
- **Single-root Vue components**: `RenameVaultDialog.vue` and `RemoveVaultDialog.vue` (the two files touched in Revision 2) each retain a single `<Dialog>` root.

---

## 5. Manual Checks (User, Desktop Only — Not Counted as Defects)
Per plan §8.3 R6, replacing T10 step 4, requiring `composer native:dev`:
- **M1**: Rename "Work" to "Office" — Explorer folder becomes `Office`, notes intact, Workspace header/sidebar show "Office" and the new path.
- **M2**: Open `Office` in Explorer/VS Code, rename to "Work" → error "Close any programs using it…"; name/folder unchanged; close programs and retry → succeeds.
- **M3**: Rename "Work" to "work" (case only) → Explorer shows `work`.
- **M4**: Create sibling folder `Docs`, try renaming the vault to "Docs"/"docs" → "already exists" error.
- **M5**: Edit only the description of a vault whose folder is missing → saves; name field disabled.

---

## 6. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/phase-2-vault-management/`
- No follow-ups for the Developer.
- Nothing routes to System Analyst.

---

**Verdict: PASS.** Counts: 0 Critical, 0 High, 0 Medium, 0 Low. No issue IDs to route — nothing goes to the Developer or the System Analyst. Recommend the orchestrator move the feature folder to `.ai/features/completed/` and ask the user to run `php artisan test --compact` plus the M1–M5 manual desktop checks in §5 (and the original 7 T10 manual steps not superseded by M1–M5, per `implementation.md` §5).
