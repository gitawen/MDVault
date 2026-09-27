# Implementation: Phase 2: Vault Management

## Metadata
- **Feature Name**: Phase 2: Vault Management
- **Feature ID**: mdv-p2
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 2 (§8 — rename also renames the folder on disk; R1–R6)
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — `vaults` schema, model, enum, factory | DONE | `make:model`/`make:enum` landed at the correct paths this time (no doubling, unlike Phase 1). Both dev and native SQLite migrated. |
| T2 — Current-vault setting key | DONE | `SettingKey::CurrentVault` added; `GeneralController::edit` no longer exposes `group(General)` wholesale. |
| T3 — `Trash` boundary, `FileStorageService`, `StoragePathService::ensureRootReady()` | DONE | `isFilesystemRoot()` made tolerant of both separator styles (see Deviations) so it stays correct when called internally after `key()`'s forward-slash normalisation. |
| T4 — `VaultService` and `VaultOperationException` | DONE | `notWritable()`/`folderMissing()` take an explicit `$field` parameter instead of a fixed one (see Deviations — the plan's own per-call-site field annotations require it). 33+ scenarios in `VaultServiceTest`. |
| T5 — Vault HTTP layer, shared prop, stub page | DONE | |
| T6 — Add existing folder (C3 approved) | DONE | |
| T7 — Vaults management page and dialogs | DONE | |
| T8 — Sidebar vault list (single-root `AppSidebar`) | DONE | `SidebarGroupAction` is exported by `@/components/ui/sidebar`, so no fallback `Button` was needed. |
| T9 — Workspace current vault | DONE | |
| T10 — Quality gates and handover | DONE | See §3. |

---

## 2. Files Changed

### Created
| Path | Summary |
|---|---|
| `database/migrations/2026_09_27_132700_create_vaults_table.php` | `vaults` table per plan §2. |
| `app/Models/Vault.php` | `HasUuids` (`uniqueIds() = ['uuid']`) + `HasFactory`; `$hidden = ['id']`; casts `status` → `VaultStatus`; full `@property` PHPDoc. |
| `app/Enums/VaultStatus.php` | `Active = 'active'`, `Missing = 'missing'`. |
| `database/factories/VaultFactory.php` | Default: unique title-case name, non-existent temp path, `status` active; `missing()` state. |
| `app/Contracts/Trash.php` | `isAvailable()`, `moveToTrash()`. |
| `app/Support/NativeTrash.php` | `Shell::trashFile()` wrapper; no-op outside the desktop runtime. |
| `app/Services/FileStorageService.php` | Directory primitives, path comparison (`samePath`, `isSameOrInside`, `relativeTo`, `isFilesystemRoot`), trash-with-check. |
| `app/Services/VaultService.php` | Vault lifecycle: `all`, `create`, `register`, `rename`, `remove`, `open`, `close`, `current`, `refreshStatus`, `canTrash`, `suggestedNameFor`, `present`, `summaries`. |
| `app/Exceptions/VaultOperationException.php` | Named constructors with `field()`, per plan §4 table (two constructors take an explicit `$field` — see Deviations). |
| `app/Http/Controllers/VaultController.php` | `index/store/update/destroy/open/close`; private `attempt()` maps `VaultOperationException` → `ValidationException`. |
| `app/Http/Controllers/ExistingVaultController.php` | `store` (register), `browse` (desktop picker, flashes `pickedFolder`). |
| `app/Http/Requests/Vaults/{StoreVaultRequest,UpdateVaultRequest,DestroyVaultRequest,RegisterVaultRequest,VaultNameRules}.php` | Validation; `VaultNameRules` is the shared trait for the `name` rule chain (method-injected `StoragePathService`). |
| `routes/vaults.php` | The 8 routes in plan §2, static routes registered before `{vault:uuid}`. |
| `resources/js/types/vaults.ts` | `VaultStatus`, `VaultSummary`. |
| `resources/js/pages/vaults/Index.vue` | Management page: create/add-existing actions, a card per vault, open/rename/remove. |
| `resources/js/components/vaults/{CreateVaultDialog,AddExistingVaultDialog,RenameVaultDialog,RemoveVaultDialog,VaultStatusBadge}.vue` | Dialogs and badge, each single-root. |
| `resources/js/components/NavVaults.vue` | Sidebar vault group; single root `<SidebarGroup>`. |
| `tests/Feature/Models/VaultTest.php` | UUID stability, hidden `id`, status cast. |
| `tests/Feature/Services/FileStorageServiceTest.php` | 21 scenarios. |
| `tests/Feature/Support/NativeTrashTest.php` | Unavailable / available HTTP behaviour. |
| `tests/Feature/Services/VaultServiceTest.php` | 49 scenarios covering create/rename/remove/open/close/current/status/register/root-change isolation. |
| `tests/Feature/Vaults/VaultManagementTest.php` | 14 scenarios: index, store/update/destroy/open/close over HTTP, validation, UUID-only routing, shared `vaults` prop. |
| `tests/Feature/Vaults/ExistingVaultTest.php` | 9 scenarios: register happy/error paths, browse 404/picked/cancelled. |

### Modified
| Path | Summary |
|---|---|
| `app/Enums/SettingKey.php` | Added `CurrentVault = 'app.current_vault'` (string, general, default null). |
| `app/Http/Controllers/Settings/GeneralController.php` | `edit()` now returns `['check_external_changes' => ...]` instead of `group(General)`. |
| `app/Http/Controllers/WorkspaceController.php` | Injects `VaultService`; adds `currentVault` prop (`present()` or null). |
| `app/Http/Middleware/HandleInertiaRequests.php` | Constructor-injects `VaultService`; shares `vaults` lazily via `summaries()`. |
| `app/Providers/AppServiceProvider.php` | Binds `Trash::class` → `NativeTrash`. |
| `app/Services/StoragePathService.php` | Added `ensureRootReady()` (create-if-missing + probe + realpath, never writes settings). |
| `routes/web.php` | `require __DIR__.'/vaults.php';`. |
| `resources/js/components/AppSidebar.vue` | Real `NavVaults` instead of the placeholder group; trailing `<slot />` removed (single root, QA-04 remainder). |
| `resources/js/pages/Workspace.vue` | `currentVault` prop; vault header / missing-folder `Alert` / empty state. |
| `resources/js/types/index.ts`, `resources/js/types/global.d.ts` | Export `./vaults`; `sharedPageProps.vaults: VaultSummary[]`. |
| `tests/Pest.php` | Added `fakeTrash()`. |
| `tests/Unit/ArchitectureTest.php` | Added: Shell only in `NativeTrash`; `Vault` only in services/controllers/factory. |
| `tests/Feature/DatabaseSchemaTest.php` | `vaults` added to the present-tables dataset + column test; absence test renamed/reduced to `notes`/`vault_encryption`/`backups`. |
| `tests/Feature/Settings/GeneralSettingsTest.php` | Added `->missing('settings.current_vault')`. |
| `tests/Feature/Services/StoragePathServiceTest.php` | Added 3 `ensureRootReady()` scenarios. |
| `tests/Feature/WorkspaceTest.php` | `currentVault: null` on the default assertion; new restart-persistence test. |
| `resources/js/actions/**`, `resources/js/routes/**` | Regenerated via `wayfinder:generate --with-form`. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` (run as `php vendor/bin/pint …`) | `{"tool":"pint","result":"passed"}` on the final run (several intermediate auto-fixes along the way, e.g. import ordering). |
| `php artisan test --compact` (full suite) | `271 tests, 270 passed, 717 assertions, 1 skipped` (the skip is Phase 1's pre-existing Windows-only unwritable-directory case). |
| `vendor/bin/phpstan analyse` (run as `php vendor/bin/phpstan analyse`) | `{"tool":"phpstan","result":"passed","errors":0}` (one fix required, see Deviations). |
| `npm run types:check` | Clean (`vue-tsc --noEmit`, no output). |
| `npm run build` | Succeeds; `vaults/Index`, dialog and `Workspace` chunks rebuilt. |
| `npm run check` | `vp check --fix` needed once per new-file batch (import/attribute wrapping only); final runs: "All 50 files are correctly formatted" / "Found no warnings or lint errors in 43 files". |
| `php artisan route:list --path=vaults` | Exactly the 8 routes in plan §2 (C3 approved). |
| `rg -n "C:\\\\|/Users/|/home/" app` | One match, inside a PHPDoc example in `FileStorageService.php` (`` `C:\a\Work` is not "inside" `C:\a\Wo` ``) — not a hardcoded path used in logic. |
| `rg -n "->id\b\|'id'" app/Services/VaultService.php` | No match — no integer id in `present()`. |
| `rg -n "deleteDirectory\|rmdir\|unlink" app/Services` | Only `FileStorageService::deleteEmptyDirectory` (`@rmdir`). |
| `php artisan migrate --no-interaction` / `php artisan native:migrate --no-interaction` | `vaults` table created in both the dev and native SQLite databases. |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated after each route change; final run has no functional diff. |

---

## 4. Deviations from Plan
- **`VaultOperationException::notWritable()` and `::folderMissing()` take an explicit `string $field` parameter**, instead of the plan's literal one-argument signatures (`notWritable(string $path)`, `folderMissing(string $path)`). The plan's own §4 table marks their field as "given" (the same notation used for `overlapsVault`/`unsafeFolder`, which *do* take an explicit `$field`), and its prose assigns them different fields at different call sites — `register()`'s writability check needs field `path`, `create()`'s needs `name`, `open()`'s `folderMissing` needs `vault`, and `remove()`'s needs `move_to_trash`. A single hardcoded field per constructor cannot satisfy all of these, so both constructors were given an explicit `$field` parameter (exactly like the two constructors the table already shows this way), and every call site passes the field the plan's prose specifies. No behavioural gap: every call site's resulting field matches the plan text exactly.
- **`FileStorageService::isFilesystemRoot()` normalises separators before matching the UNC pattern**, so it correctly recognises a UNC root when called internally from `key()` (which has already converted the path to forward slashes) as well as when called directly with a native backslash path (as the plan's own test dataset does: `/`, `C:\`, `C:`, `\\srv\share` → true; `C:\x` → false — all still pass). Without this, a UNC root passed through `key()`'s forward-slash form would fail to be recognised as a root internally, which would silently break `key()`'s "no trailing-slash trim on a root" rule for that one path shape. Purely a robustness fix; the required public behaviour (the dataset) is unchanged.
- **`VaultService::all()` builds its return value with `array_values()` instead of `Collection::values()->all()`** (PHPStan: Larastan's stubs don't narrow `Collection::values()->all()` to `list<>`). Same runtime result, no behavioural change.
- **`VaultServiceTest`'s "DB failure after mkdir" scenario listens on `Vault::saving()` with an `$vault->exists` guard, instead of `Vault::updating()`** as the plan's prose literally says. Eloquent's `save()` fires the `saving` event unconditionally but only proceeds to fire `updating` (and run the update) when the model `isDirty()`. In this environment (and in general, whenever `realpath()` of a path built from an already-canonical root doesn't change the string — which is the common case with no symlinks/case differences), the final `$vault->update(['path' => canonical($target)])` call in `create()` is a no-op update with nothing dirty, so `updating` never fires and the intended "DB fails on the second write" scenario couldn't be exercised. Guarding a `saving` listener on `$vault->exists` reproduces the same scenario (a DB failure specifically on the second write, after the folder was already created) reliably regardless of whether the path string actually changes. The production code (`create()`'s try/catch/compensate structure) is unchanged; only the test's simulation mechanism differs from the plan's literal wording.
- **`Vault::factory()->create(['path' => ...])` fixtures in the "remove: trashing an unsafe folder" test create the target directory first via `File::ensureDirectoryExists()`** rather than `File::makeDirectory()` (which throws when the storage root's parent, `Documents`, already exists from an earlier `makeDirectory` call in the same test). Same end state (the directory exists), just idempotent.
- No scope changes. All ten tasks were implemented as specified; C1–C10 were applied exactly as approved (unregister-by-default + optional desktop trash never permanent delete, display-name-only rename, "Add existing folder" in scope, `path` authoritative / `relative_path` a hint, `app.current_vault` setting, case-insensitive portable names, empty-folder reuse, open-and-go-to-Workspace on create, no new dependencies).

---

## 5. Notes for QA
- **`assertInertiaFlash`/`assertInertiaFlashMissing`/`inertiaPage()`/`inertiaProps()`** are auto-registered `TestResponse` macros from `inertiajs/inertia-laravel` (confirmed in `vendor/inertiajs/inertia-laravel/src/ServiceProvider.php::registerTestingMacros()`), not something added in this phase — first real usage in this codebase is in `VaultManagementTest`/`ExistingVaultTest`. Worth a sanity check that they read as expected in your environment. Note that `AssertableInertia::toArray()` returns the **full envelope** (`component`/`props`/`url`/`version`/`flash`), not just the props — `inertiaPage()['props']['x']`, not `inertiaPage()['x']`.
- **`notWritable`/`folderMissing` signature change** (see Deviations) — please check this reads as faithful to the plan's intent (every call site's field matches the plan's prose) rather than as scope creep.
- **`FileStorageService::relativeTo()`** returns the remainder in the *path's own original case* using its `canonical()` form; on Windows this is whatever case the filesystem reports for the created/existing folder, which should always match what was just created (case is preserved by NTFS as-created) but hasn't been separately stress-tested for a folder created with different case by an external tool.
- **Manual desktop verification is required** (not exercised in this session — no native shell available). Per plan T10, with `composer native:dev`:
  1. Sidebar shows "Vaults" with the "Create a vault" empty state.
  2. Create "Work": `Documents\MDVault\Work` appears in Explorer; the Workspace shows "Work" in the header bar.
  3. Quit and relaunch: "Work" is still current (header bar shows it immediately).
  4. **(Superseded by Revision 2 — see M1–M5 below.)** ~~Rename it to "Office" via the Vaults page: the Explorer folder is still named `Work`; the header/sidebar/list all show "Office".~~
  5. Rename or delete the `Work` folder in Explorer: the sidebar and Vaults page show it as missing (triangle-alert icon, "Folder missing" badge); clicking Open shows an error toast; the Workspace header shows the destructive "folder can't be found" alert if it was current. Restore the folder: it becomes available again (status self-heals on next list/open).
  6. "Add existing folder" → "Choose folder…" → the picker fills the path field and pre-fills the name (folder basename) → Add → it opens and redirects to the Workspace.
  7. Remove with "Also move the folder to the Recycle Bin / Trash" checked: the folder appears in the Windows Recycle Bin and can be restored; the record disappears. Remove without the checkbox: the record disappears but the folder stays on disk untouched.
  8. With the folder open in VS Code/Explorer, removing with the Recycle Bin checkbox should either show the "close any programs using it" error (record kept) or succeed cleanly — the vault must never be unregistered while its folder still exists on disk (verify by checking the folder is actually gone before the record disappears, e.g. by watching Explorer).
- These were plan T10's 8 manual steps; QA should treat them as manual/user checks, not as defects, per the plan's test-scope note. **Per Revision 2's R6, step 4 above is replaced by the M1–M5 checks below.**

**Revision 2 manual checks (M1–M5, replacing T10 step 4 above), per §8.3 R6:**
- **M1**: Rename "Work" to "Office". In Explorer the folder is now `Office` and the notes are intact. The Workspace header and the sidebar show "Office" and the new path.
- **M2**: Open `Office` in Explorer or VS Code, then rename it to "Work" → error "Close any programs using it…"; the name and the folder are unchanged. Close them and retry → it succeeds.
- **M3**: Rename "Work" to "work" (letter case only) → the Explorer folder shows `work`.
- **M4**: Create a folder `Docs` next to the vault and try renaming the vault to "Docs" (or "docs") → "already exists" error.
- **M5**: Edit only the description of a vault whose folder is missing → saves; the name field is disabled.

---

## 6. Revision 2 — Rename also renames the folder on disk (§8, R1–R6)

### 6.1 Task Progress
| Task | Status | Notes |
|---|---|---|
| R0 (orchestrator) | DONE | ADR and cross-ADR follow-up already updated before this round started. |
| R1 — `FileStorageService` rename primitives | DONE | `exists`, `siblingPath`, `isSameDirectory`, `renameDirectory` added exactly per §8.2; `failFolderRenames()` helper added to `tests/Pest.php`. Binding on the FQCN `Illuminate\Filesystem\Filesystem::class` was sufficient — `app(FileStorageService::class)` receives the fake without needing a second bind on the `'files'` alias (confirmed by the "lock simulated" tests passing). |
| R2 — `VaultOperationException` constructors | DONE | `renameTargetExists`, `renameFailed`, `renameRollbackFailed`, `renameInterrupted` added, all field `name`. |
| R3 — `VaultService::rename()` rewrite | DONE | Steps 1–12 of §8.2 implemented in order, plus private `replaceLastSegment()`. PHPDoc rewritten. |
| R4 — HTTP toast | DONE | `VaultController::update` captures `$before`, compares after `rename()`, flashes the path-specific or plain toast. |
| R5 — `RenameVaultDialog.vue` copy and missing state | DONE | New `DialogDescription` copy; name `Input` disabled and a muted hint shown when `vault.status === 'missing'`. Submit button was already labelled "Save" from Revision 1, no change needed there. |
| R6 — Quality gates and handover | DONE | See §6.3 below. |

### 6.2 Files Changed (Revision 2)
| Path | Summary |
|---|---|
| `app/Services/FileStorageService.php` | Added `exists()`, `siblingPath()`, `isSameDirectory()`, `renameDirectory()`, and a private `attemptMove()` helper (`moveDirectory(..., overwrite: false)` wrapped in `try/catch \Throwable`). |
| `app/Services/VaultService.php` | `rename()` rewritten per §8.2 (checks → folder rename → check → DB update, with rename-back compensation on a DB failure). Added private `replaceLastSegment()`. PHPDoc rewritten to describe the new order and compensation. |
| `app/Exceptions/VaultOperationException.php` | Added `renameTargetExists`, `renameFailed`, `renameRollbackFailed`, `renameInterrupted`, all field `name`. |
| `app/Http/Controllers/VaultController.php` | `update()`: captures `$before = $vault->path`, flashes `"Vault renamed. Its folder is now {path}."` when the path changed, else `'Vault updated.'`. |
| `resources/js/components/vaults/RenameVaultDialog.vue` | New dialog copy warning that the folder is renamed too; name `Input` disabled plus a "folder is missing" hint when `vault.status === 'missing'`. |
| `resources/js/components/vaults/RemoveVaultDialog.vue` | QA-02: copy changed to "Your files stay on disk at {{ vault.path }}." |
| `tests/Pest.php` | Added `failFolderRenames(array $failOnCalls = [1]): object`, exactly as specified in the plan. |
| `tests/Feature/Services/FileStorageServiceTest.php` | Added the 7 scenario groups from R1 (happy path, refuse-existing-target dataset, case-only rename, lock on move 1, lock on move 2 of a case-only rename, `isSameDirectory` (3 cases), `siblingPath`). |
| `tests/Feature/Services/VaultServiceTest.php` | Replaced the 3 Revision-1 rename tests with the 18 scenarios from R3 (renumbered/renamed to describe the new behaviour: happy path, duplicate name, case-only, description-only/missing-safe, missing-folder refusal, target-exists dataset, cross-case sibling clash (`onlyOnLinux`), overlap with a missing vault's recorded path, unsafe folder, lock on first move, DB failure + rename-back success, DB failure + rename-back failure, folder added outside the root, folder added inside the root, basename-already-matches, current-vault path re-read, invalid-name dataset). |
| `tests/Feature/Vaults/VaultManagementTest.php` | Extended the update test to assert the folder actually moves and the new toast text; added description-only-toast, target-already-exists, and folder-deleted-then-renamed scenarios. |
| `tests/Feature/WorkspaceTest.php` | QA-01: the restart-persistence test's temp-dir cleanup moved into a `try { ... } finally { File::deleteDirectory($tmp); }` so it runs even if an assertion above it fails. |

### 6.3 Verification Performed (Revision 2)
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` (one earlier run auto-fixed an import order in `tests/Pest.php`; final run clean). |
| `php artisan test --compact tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/VaultServiceTest.php tests/Feature/Vaults/VaultManagementTest.php tests/Unit/ArchitectureTest.php` | `126 tests, 125 passed, 358 assertions, 1 skipped` (the skip is the pre-existing Phase-1 Windows-only case; see below for the second, Revision-2 skip that only appears in the full run). |
| `php artisan test --compact` (full suite, required because `tests/Pest.php` changed) | `303 tests, 301 passed, 827 assertions, 2 skipped` (skip #1: pre-existing Phase-1 Windows-only unwritable-directory case; skip #2: the new "a different directory whose name differs only in case is refused" test, `->onlyOnLinux()`, skipped because this session runs on Windows — matches the plan's own OS-gating for that scenario). |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | Clean (`vue-tsc --noEmit`, no output), re-checked after `npm run check --fix` touched `RenameVaultDialog.vue`. |
| `npm run build` | Succeeds; `Index`/dialog chunks rebuilt. |
| `npm run check` | First run flagged formatting-only issues in `RenameVaultDialog.vue` (line-wrap of the new copy); `vp check --fix` resolved it; final run: "All 50 files are correctly formatted" / "Found no warnings or lint errors in 43 files". |
| `rg -n "moveDirectory\|rename\(" app` | 4 matches: `FileStorageService.php:139` (a PHPDoc comment mentioning POSIX `rename()`), `FileStorageService.php:185` (the one real `$this->files->moveDirectory(...)` call, inside the new private `attemptMove()`), `VaultService.php:177` (the `rename()` method's own declaration), `VaultController.php:44` (the controller calling `$vaults->rename(...)`, i.e. `VaultService`'s own method — not a filesystem call). The grep is a substring match on `rename(` and unavoidably also matches call sites of `VaultService::rename()` itself, which the plan requires; the only **filesystem-level** move/rename call in `app/` is the single `moveDirectory` call in `FileStorageService::attemptMove()`. |
| `rg -n "moveDirectory\([^)]*true" app` | No matches — `overwrite` is always `false`. |
| `rg -n "deleteDirectory\|rmdir\|unlink" app/Services` | Only `FileStorageService::deleteEmptyDirectory`'s `@rmdir` — unchanged from Revision 1. |

### 6.4 QA-01 / QA-02 (Round 1 MINOR follow-ups)
- **QA-01** (`tests/Feature/WorkspaceTest.php` restart-persistence test): the inline `File::deleteDirectory($tmp)` at the end of the test body is now inside a `finally` block wrapping the whole test, so the temp directory is removed even if an assertion above it fails. Chosen over a file-scoped `afterEach` because this is the only test in the file that creates a temp dir (the other three don't touch the filesystem), so a `try/finally` keeps the cleanup next to the code that needs it without adding an `afterEach` that would run (harmlessly, but needlessly) for every other test in the file.
- **QA-02** (`resources/js/components/vaults/RemoveVaultDialog.vue`): copy changed from "Files in {{ vault.path }} are not deleted." to "Your files stay on disk at {{ vault.path }}." — matches the requirement's literal phrase while keeping the path mention QA asked to keep.

### 6.5 Deviations from Plan (Revision 2)
- None functional. One test-construction issue was found and fixed during development, not a deviation from the plan's specified behaviour: the first draft of the "rename-back failure" test (R3, scenario 12) called `$this->service->refreshStatus($vault->fresh())` in its final assertions using the **original** (non-faked) `VaultService`/`FileStorageService` pair from `beforeEach`, *after* the `Vault::saving()` listener that simulates "db down" was still registered. That final `refreshStatus()` call persists the newly-Missing status (a real `$vault->update()`), which re-triggered the same listener and threw uncaught, outside the `try/catch` around `rename()` — a test-harness ordering bug, not a production defect (confirmed by an isolated reproduction of `VaultService::rename()`'s compensation path in an ad hoc scratch test, which passed on the first attempt: 2 `moveDirectory` calls, `renameRollbackFailed` thrown, `Office` intact, `Work` never recreated). Fixed by calling `Vault::flushEventListeners()` right after the `try/catch`, before the assertions that call `refreshStatus()` again. `VaultService::rename()` itself required no changes for this.
- One dataset fixture in the new "target already exists" test (R3, scenario 6, "a file" case) used `File::ensureDirectoryExists(dirname($target))` instead of `File::makeDirectory(...)`, for the same reason already recorded in §4 for a Revision-1 fixture: the parent (`$this->root`) already exists from `create()`, and `makeDirectory()` without `$force` throws on an existing directory. Same end state, idempotent.
- **R3 scenario 18 (optional, `->onlyOnWindows()` real-lock test) was not added.** The plan explicitly allows deleting/omitting it if a real lock "can't be reproduced reliably" and notes manual step M2 covers it instead; the deterministic `failFolderRenames()`-based lock simulation (scenario 10) already covers the same code path (`renameDirectory` returning false → `renameFailed`), so a second, flakier mechanism (holding an `opendir`/`fopen` handle open across a rename on Windows, where lock semantics for open directory handles are inconsistent) was judged not worth the reliability risk. M2 in the final report covers the real-lock manual check.

### 6.6 Notes for QA (Revision 2)
- The `rg -n "moveDirectory|rename\(" app` grep's false positives (`VaultService::rename()`'s declaration and its one call site in `VaultController`) are expected and explained in §6.3 — please confirm you read them the same way (i.e. that the *only* line performing an actual filesystem move/rename is `FileStorageService.php:185`).
- `renameDirectory`'s case-only (temp-sibling) path is only exercised as a genuine two-step move on a case-insensitive filesystem (Windows/macOS). On this Windows dev machine it was verified to go through `.mdvault-rename-*` and leave no trace; on a case-sensitive filesystem (Linux CI, if any) the same test still passes but exercises the simpler direct-move branch instead, since `Work` and `work` are different, non-colliding paths there — this is called out inline in the test's own comment-free assertions and is consistent with the plan's "runs on every OS" framing for that scenario.
- `->onlyOnLinux()` test ("a different directory whose name differs only in case is refused") was not exercised in this session (Windows); it is skipped, not failing, in the full-suite run above.
- M1–M5 (desktop manual checks, plan §8.3 R6) were **not** exercised in this session — no native shell available, same constraint as Revision 1's manual steps. See the final report / user-facing summary for the exact steps.
- Round-1 QA-01 and QA-02 are addressed above; no other outstanding items from the Round-1 report remain.

## 7. Fix Rounds
*(none yet — this section is for future QA fix rounds against Revision 2, distinct from §6 above.)*
