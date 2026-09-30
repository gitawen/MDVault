# QA Report: Reset database (vault registry reset)

## Metadata
- **Feature Name**: Reset database (vault registry reset)
- **Feature ID**: mdv-p6-reset
- **QA Round**: 1
- **Plan Revision Verified**: Revision 1 (APPROVED)
- **Verdict**: **PASS**

---

## 1. Requirements Coverage

| FR | Requirement | Verified against | Result |
|---|---|---|---|
| FR-01 | `resetRegistry()` counts + deletes notes/vaults, forgets current vault, one transaction | `VaultService::resetRegistry()` (app/Services/VaultService.php:326-340); `VaultRegistryResetTest.php` | PASS |
| FR-02 | Never touches disk | No filesystem calls in `resetRegistry()`; no model observers on `Note`/`Vault` that could touch disk (checked — none exist); filesystem-snapshot test (nested folder, non-.md file, unregistered folder, `.mdvault-restore-abc` staging folder) | PASS |
| FR-03 | Settings kept except current vault | Only `SettingKey::CurrentVault` is forgotten; test asserts theme, `storage.root_path`/folder name, `editor.font_size` unchanged | PASS |
| FR-04 | Backup history kept | `backups` table has no FK to `vaults` (confirmed via migration `2026_09_30_013105_create_backups_table.php`) and is never referenced in `resetRegistry()`; test confirms 2 rows survive | PASS |
| FR-05 | Atomic | Single `$this->database->connection()->transaction()`; `RAISE(ABORT)` trigger test confirms rollback (vaults, notes, current-vault setting all unchanged after a forced `QueryException`) | PASS |
| FR-06 | Idempotent | Empty-registry test returns all-zero counts, no exception | PASS |
| FR-07 | Typed confirmation, server-enforced | `ResetDatabaseRequest` rule `['required','string','in:RESET']`; Laravel's `in` rule does exact non-strict string comparison, which is still case-sensitive for non-numeric strings — verified by an HTTP dataset test (missing/empty/`reset`/`RESETX`/`Reset`), all correctly rejected with rows intact | PASS |
| FR-08 | Result feedback (success/error toasts) | Controller flashes `type: success` with a message containing "No files were deleted", or `type: error` containing "Nothing was changed" on `QueryException`; both asserted via `session('inertia.flash_data')` in `ResetDatabaseHttpTest.php` | PASS |
| FR-09 | UI dialog (counts, kept/removed lists, active-vault warning, `RESET` gate, disabled when 0 vaults) | `ResetDatabaseDialog.vue` — single root `<Dialog>`, `:disabled="total === 0"` on the trigger, submit button gated on `form.confirmation !== 'RESET'`, active-vault warning rendered conditionally | PASS (manual visual/spacing check not independently run — see Follow-ups) |
| FR-10 | Restore-section missing-vault hint | `Backup.vue`: `missingVaultCount` computed from shared `vaults` prop, hint shown/hidden correctly | PASS |
| FR-11 | Fresh restore after reset (original names/UUIDs, custom root, byte-identical content) | `BackupResetRestoreTest.php`: full end-to-end scenario (missing → `resetRegistry()` → `inspect()` gives `new`/`restore` with original name → `restore()` recovers original vault/note UUIDs and identical file hash); separate test for a custom storage root; separate test documenting the pre-reset failure mode (`vaultAlreadyRegistered`, `(restored)` copy name) | PASS |

All FRs trace to at least one passing automated test. No missing-test gaps found.

---

## 2. Code Review Findings

- **`VaultService::resetRegistry()`** (app/Services/VaultService.php:326-340) matches the ADR and plan exactly: counts first, `Note::query()->delete()` before `Vault::query()->delete()` (explicitly not relying on the `notes.vault_id` FK's `cascadeOnDelete()`, confirmed present in `database/migrations/2026_09_27_161820_create_notes_table.php` but correctly not depended upon), then `$this->settings->forget(SettingKey::CurrentVault)`, all inside one `DatabaseManager` transaction. No `FileStorageService`/`StoragePathService`/raw filesystem calls, no `refreshStatus()` call. Docblock references the ADR.
- No model observers/events on `Note` or `Vault` exist that could introduce a hidden filesystem side effect on bulk delete (checked `app/Providers` and model files) — this makes the FR-02 guarantee structural, not just incidentally true.
- **Form Request / Controller**: `ResetDatabaseRequest` and `DatabaseResetController` match sibling classes' style (`BackupController`, `BackupRestoreController` — non-final classes, same pattern). `authorize()` returns `true`, consistent with the "local app, no authentication" ADR. Only `QueryException` is caught in the controller, matching the plan.
- **Route**: `DELETE settings/backup/database` → `settings.backup.database.destroy`, placed after the restore routes, correct `use` import style. `GET` correctly returns 405 (tested).
- **Wayfinder diff**: Confirmed via `git diff` that the only changes to previously-generated files are two additive lines (`resources/js/actions/App/Http/Controllers/Settings/index.ts`, `resources/js/routes/settings/backup/index.ts`), each importing/registering the new `DatabaseResetController`/`database` route. `git status` shows no other generated file under `resources/js/actions` or `resources/js/routes` differs from HEAD. The new `destroy()` action/route files include `.form` variants (`--with-form` was used), consistent with the rest of the codebase.
- **Vue components**: `ResetDatabaseDialog.vue` is single-root (`<Dialog>`), imports `destroy` from `@/routes/settings/backup/database` (Wayfinder, not a hardcoded URL), and structurally mirrors `RemoveVaultDialog.vue` (same `Dialog`/`DialogTrigger as-child`/`useForm().submit()` pattern). `Backup.vue`'s diff is minimal and additive (missing-vault hint + new section using the shared `vaults` Inertia prop, sourced from `HandleInertiaRequests` → `VaultService::summaries()`).
- **Dialog wording / missing-vault hint**: matches the plan's specified copy; the active-vault warning correctly lists active vault names and recommends "Add existing folder" / "Back up all vaults" per the ADR's risk mitigations. The "delete your notes" phrasing the requirements explicitly avoid is not present anywhere in the new copy.
- **Data integrity**: `backups` table has no FK to `vaults` (checked its migration directly) — the FR-04 guarantee is structural, not accidental.
- **Architecture rules**: `tests/Unit/ArchitectureTest.php` passes unchanged; `App\Models\Setting` still only used in `SettingsService`; `Vault`/`Note`/`Backup` models still only used in services/controllers/models/factories.

### Issues Found

| ID | Severity | Description | Classification | Fix Attempts |
|---|---|---|---|---|
| QA-1 | **Low / Minor** | `npm run check` (the project's Vue/JS formatter, `vp check`) reports formatting issues in the two feature-touched frontend files: `resources/js/components/backups/ResetDatabaseDialog.vue` and `resources/js/pages/settings/Backup.vue` (line-wrap only, e.g. `missingVaultCount` computed and two template paragraphs wrap a few characters wider than the formatter's preferred width). No functional or type impact — `npm run types:check` is clean and all tests pass. Confirmed via `vp check --fix` in a scratch run and reverted; the working tree is unchanged from the developer's original diff. | Developer (mechanical, run `npx vp check --fix` on the two files) | 0 |

No Critical, High, or Moderate issues found.

**Orchestrator note (2026-09-30):** QA-1 resolved as a Level 1 mechanical fix — `npx vp check --fix` applied to both files; `vp check` now reports both files correctly formatted with no lint errors.

---

## 3. Verification Performed

| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Services/VaultRegistryResetTest.php tests/Feature/Settings tests/Feature/Backups tests/Feature/Vaults tests/Feature/Services/VaultServiceTest.php tests/Unit/ArchitectureTest.php` | 283 tests, 282 passed, 1 skipped (pre-existing, unrelated), 0 failed |
| `php artisan test --compact` (full suite) | 761 tests, 750 passed, 11 skipped (pre-existing, unrelated), 0 failed |
| `vendor/bin/phpstan analyse` | 0 errors |
| `vendor/bin/pint --test --format agent` | 1 failure: `tests/Unit/ExampleTest.php` (`single_blank_line_at_eof`) — pre-existing file, untouched by this feature (confirmed via `git status`/`git log`), not attributable to this change |
| `npm run types:check` (`vue-tsc --noEmit`) | passed, no errors |
| `npm run check` (`vp check`, closest equivalent to `npm run lint`, which isn't configured) | Formatting issues in `ResetDatabaseDialog.vue` and `Backup.vue` — see QA-1 |

---

## 4. Verdict Rationale
Zero open Critical/High/Moderate issues. FR-01 through FR-11 are all implemented per the ADR and plan and are covered by passing tests, including an atomicity test using a real SQLite `RAISE(ABORT)` trigger and a genuine end-to-end reset→restore scenario recovering original UUIDs and byte-identical content. The filesystem-untouched guarantee (FR-02) is structurally verified (no observers, no filesystem calls in the reset path) in addition to the snapshot test. The Wayfinder regeneration incident mentioned in `implementation.md` was independently re-verified via `git diff` and is additive-only, as claimed. The one open issue (QA-1) is cosmetic formatting only, at Low/Minor severity, and does not block completion — it can be fixed with a one-line `vp check --fix` in the same PR or as a fast follow-up.

**Recommendation**: PASS. Per CLAUDE.md §4, this qualifies to move `.ai/features/active/reset-app-index/` to `.ai/features/completed/reset-app-index/`. QA-1 should still be routed to the Developer for a quick mechanical fix (or fixed before the folder is moved, at the orchestrator's discretion) since zero Moderate/High/Critical issues remain either way.

---

## Routing
- **Developer**: QA-1 (Low/Minor, mechanical — run `npx vp check --fix resources/js/components/backups/ResetDatabaseDialog.vue resources/js/pages/settings/Backup.vue`, verify no semantic change, re-run `npm run check`).
- **System Analyst**: none. No issue reached 3 fix attempts; no Major issue found.

Counts by severity: Critical 0, High 0, Moderate 0, Low/Minor 1 (QA-1).
