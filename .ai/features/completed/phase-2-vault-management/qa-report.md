# QA Report: Phase 2: Vault Management

## Metadata
- **Feature Name**: Phase 2: Vault Management
- **Feature ID**: mdv-p2
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-09-27
- **Verdict**: PASS

> Zero open Critical/High issues. Two Low-severity, MINOR follow-ups noted (test hygiene, one wording nit).

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 (schema, UUIDv7, hidden id) | yes | `tests/Feature/DatabaseSchemaTest.php`, `tests/Feature/Models/VaultTest.php` | ✅ |
| FR-02 (create) | yes | `tests/Feature/Services/VaultServiceTest.php` (create block), `tests/Feature/Vaults/VaultManagementTest.php` | ✅ |
| FR-03 (name rules) | yes | `VaultServiceTest` invalid-name dataset + duplicate-name tests | ✅ |
| FR-04 (existing target folder) | yes | `VaultServiceTest` empty/non-empty/file-target tests | ✅ |
| FR-05 (open) | yes | `VaultServiceTest` open tests, `VaultManagementTest` open test | ✅ |
| FR-06 (restart persistence) | yes | `VaultServiceTest` restart test, `WorkspaceTest` restart test | ✅ |
| FR-07 (close) | yes | `VaultServiceTest`, `VaultManagementTest` close tests | ✅ |
| FR-08 (rename, display-only) | yes | `VaultServiceTest` rename block, `VaultManagementTest` update test | ✅ |
| FR-09 (remove/unregister) | yes | `VaultServiceTest` remove block, `VaultManagementTest` destroy test | ✅ |
| FR-10 (remove + OS trash) | yes | `VaultServiceTest` trash tests, `FileStorageServiceTest`, `NativeTrashTest`, `VaultManagementTest` | ✅ |
| FR-11 (missing-folder detection/self-heal) | yes | `VaultServiceTest` status block, `VaultManagementTest` shared-prop test | ✅ |
| FR-12 (register existing folder, C3) | yes | `VaultServiceTest` register block, `tests/Feature/Vaults/ExistingVaultTest.php` | ✅ |
| FR-13 (sidebar vault list, single-root) | yes | Backend: shared-prop tests in `VaultManagementTest`; frontend: `npm run types:check` / `build` / `check` (project has no JS unit-test framework in any phase — consistent convention) | ✅ |
| FR-14 (vaults management page) | yes | `VaultManagementTest` index test | ✅ |
| FR-15 (Workspace current vault) | yes | `WorkspaceTest` (null + restart-persistence tests) | ✅ |
| FR-16 (storage-root change isolation) | yes | `VaultServiceTest` root-change-isolation test | ✅ |
| FR-17 (architecture: UUID routes, thin controllers, Shell only in NativeTrash) | yes | `tests/Unit/ArchitectureTest.php` (new arch rules), `route:list`, code review | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact` (full suite) | `271 tests, 270 passed, 717 assertions, 1 skipped` (skip is a pre-existing Windows-only Phase 1 case, unrelated) |
| `vendor/bin/phpstan analyse` (level 7) | `0 errors` |
| `npm run types:check` | clean, no output |
| `npm run build` | succeeds, all new chunks built |
| `npm run check` | "All 50 files are correctly formatted"; "no warnings or lint errors in 43 files" |
| `php artisan route:list --path=vaults` | exactly 8 routes, static routes (`close`, `existing`, `existing/browse`) registered before `{vault:uuid}` |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `rg -n "C:\\\\|/Users/|/home/" app` (re-verified) | one match only, inside a PHPDoc example in `FileStorageService.php`, not executable logic |
| `rg -n "deleteDirectory|rmdir|unlink" app/Services` (re-verified) | only `FileStorageService::deleteEmptyDirectory` (`@rmdir`) |
| `grep -rn "dd(|dump(|console.log|var_dump\|TODO\|FIXME"` in new PHP/Vue files | none |

No failures. No phpstan baseline entries added.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Low | MINOR | `tests/Feature/WorkspaceTest.php` (new restart-persistence test) | The new test creates a temp dir + `fakeDocumentsDirectory` and cleans up with an inline `File::deleteDirectory($tmp)` at the end of the test body instead of an `afterEach` hook, unlike every other vault/storage test in the suite (and unlike the plan's own stated convention: "every test that touches vaults or storage must use `beforeEach` … and `afterEach(File::deleteDirectory($tmp))`"). If an assertion above it fails, the temp directory under `sys_get_temp_dir()` is never removed. | Move the cleanup into an `afterEach` (scoped only to this test file, or via a per-test `try/finally`) so the temp dir is always removed regardless of assertion outcome. | 0 |
| QA-02 | Low | MINOR | `resources/js/components/vaults/RemoveVaultDialog.vue:50-52` | The requirements' UX guideline text is "says 'your files stay on disk'"; the shipped copy is "Files in {path} are not deleted." Same meaning, different wording — a cosmetic nit only, not a functional gap. | Optional: align wording with the requirement's literal phrase, or leave as-is (equivalent meaning) — purely a style/consistency call, no fix required for PASS. | 0 |

Both issues are Low/MINOR and do not block PASS. No Critical, High, Medium, or MAJOR issues found.

**Developer deviations from implementation.md — assessed, all accepted, no action needed:**
1. `VaultOperationException::notWritable()`/`::folderMissing()` take an explicit `$field` parameter instead of one hardcoded field. Verified every call site (`create()`→`name`, `register()`→`path`, `open()`→`vault`, `remove()`→`move_to_trash`) matches the plan's own prose exactly. Legitimate, not scope creep.
2. `FileStorageService::isFilesystemRoot()` normalises separators before the UNC regex. Verified the plan's exact test dataset (`/`, `C:\`, `C:`, `\\srv\share` → true; `C:\x` → false) still passes; pure robustness fix for internal callers that pass forward-slash-normalised paths.
3. `VaultService::all()` uses `array_values()` instead of `Collection::values()->all()` (Larastan type-narrowing only). No behavioural difference; confirmed by passing test `create: persists across a fresh scoped instance (restart)`.
4. `VaultServiceTest`'s "DB failure after mkdir" scenario listens on `Vault::saving()` guarded by `$vault->exists` rather than `Vault::updating()`, because the plan's literal `updating()` listener would not fire when `canonical()` is a no-op string change (no symlinks/case difference on this filesystem) — `save()` only fires `updating` when the model is dirty. Verified the test still exercises the intended "DB fails on the second write, after mkdir" path and asserts the folder is compensated away and no record exists. Production code is unaffected; this is a test-mechanism substitution only.
5. `File::ensureDirectoryExists()` vs `File::makeDirectory()` in one test fixture — idempotent, same end state, avoids an unrelated `makeDirectory` exception when a parent already exists.

---

## 4. Code Review Notes
- **Security & Authorization**: No auth (ADR-compliant, local single-user app). `vaults.existing.browse` correctly 404s outside the desktop runtime (`abort_unless($dialogs->isAvailable(), 404)`), verified by `ExistingVaultTest`'s "browse is not found outside the desktop runtime" test. Trash is refused outside the desktop runtime via `VaultOperationException::trashUnavailable()`, surfaced as a `move_to_trash` validation error — verified at both service and HTTP level.
- **Validation & Data Integrity**: Every mutating endpoint has a Form Request (`StoreVaultRequest`, `UpdateVaultRequest`, `DestroyVaultRequest`, `RegisterVaultRequest`), each backed by the shared `VaultNameRules` trait delegating to `StoragePathService::assertValidFolderName()` (single source of truth, reused from Phase 1). Traced every delete/remove path in `app/`: the only directory-removal call is `FileStorageService::deleteEmptyDirectory()` (guarded by `isEmptyDirectory()` first, `@rmdir`, and it's only ever invoked on a folder `create()` itself just made in the same failed operation), plus the OS-trash boundary (`NativeTrash`→`Shell::trashFile()`) which is Recycle-Bin/Trash, not permanent deletion, and is always followed by a `file_exists()` check before the DB record is deleted. No path can permanently delete a non-empty folder or bypass the OS trash. `create()` follows the DB-first/mkdir/verify order with `$createdHere`-gated compensation (empty-only `rmdir`), tested for both a DB failure after mkdir and a target-turned-into-a-file-after-insert scenario, both correctly rolling back the DB record and leaving no orphaned non-empty state. `remove()` with trash follows trash → `clearstatcache()` → existence check → DB delete, tested for success, silent failure (record + folder both kept), unavailability, a missing-vault target, and unsafe folders (storage root, Documents) — the record is never touched unless the folder is confirmed gone.
- **Performance**: `all()`/`summaries()` do one query plus one `is_dir()` per vault, matching the NFR; status writes only on change (`refreshStatus` compares before updating).
- **Conventions (CLAUDE.md, ADRs)**: `VaultService`/`FileStorageService` are `final`, constructor-injected, contain all FS/DB logic; controllers are thin and never touch `File`/`Storage` or raw FS functions (enforced by the pre-existing arch rule, unmodified and still passing). New arch rules correctly enforce Shell-only-in-`NativeTrash` and `Vault`-only-in-services/controllers/factory. Routes use `{vault:uuid}` with `whereUuid()`; an integer ID 404s (tested). No integer `id` appears in any prop (`$hidden = ['id']` on the model, plus a dedicated test asserting no `id` key in `present()`/shared props).
- **Frontend (Inertia/Vue/Wayfinder)**: Every new/edited component (`AppSidebar.vue`, `NavVaults.vue`, `vaults/Index.vue`, all four dialogs, `VaultStatusBadge.vue`, `Workspace.vue`) has a single root element. All route calls go through generated Wayfinder functions (`@/routes/vaults`, `@/routes/vaults/existing`), no hardcoded URLs found. Vue components are presentation-only — no path joining or filesystem logic in the frontend (the "folder is created inside: {storageRoot}" help text is display-only, matching the plan's explicit "no path joining in Vue" instruction). `TiptapEditor.vue` is confirmed unchanged.

---

## 5. Manual Checks (User, Desktop Only — Not Counted as Defects)
Per the plan's T10 and `implementation.md` §5, these require `composer native:dev` and cannot be exercised in this session:
1. Sidebar shows "Vaults" with the "Create a vault" empty state on first launch.
2. Create "Work": `Documents\MDVault\Work` appears in Explorer; Workspace header shows "Work".
3. Quit and relaunch: "Work" is still current.
4. Rename to "Office": the Explorer folder is still named `Work`.
5. Rename/delete the `Work` folder externally: sidebar/Vaults page show it missing; Open shows an error; restoring the folder makes it active again.
6. "Add existing folder" → "Choose folder…" → picker fills path + suggested name → Add.
7. Remove with "move to Recycle Bin" checked: folder appears in the Windows Recycle Bin and is restorable; the record disappears. Remove without it: record disappears, folder stays.
8. With the folder open in VS Code/Explorer, a Recycle-Bin removal either errors cleanly ("close programs using it", record kept) or succeeds cleanly — the vault must never be unregistered while its folder still exists on disk.

---

## 6. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/phase-2-vault-management/`
- Optional low-priority follow-ups for the Developer (not blocking): QA-01, QA-02.
- Nothing routes to System Analyst.

---

**Verdict: PASS.** Counts: 0 Critical, 0 High, 0 Medium, 2 Low (both MINOR, non-blocking). Routing: QA-01 and QA-02 → Senior Developer (optional, low priority, no re-QA round required unless the orchestrator wants them addressed). Recommend the orchestrator move the feature folder to `.ai/features/completed/` and ask the user to run `php artisan test --compact` plus the 8 manual desktop steps in §5.
