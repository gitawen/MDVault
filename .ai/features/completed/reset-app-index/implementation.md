# Implementation: Reset database (vault registry reset)

## Metadata
- **Feature Name**: Reset database (vault registry reset)
- **Feature ID**: mdv-p6-reset
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Service method and result DTO | DONE | `RegistryResetResult` created via `make:class`; `VaultService::resetRegistry()` added exactly per plan (count → delete notes → delete vaults → forget current vault, in one transaction). |
| T2 — Form Request, controller and route | DONE | `ResetDatabaseRequest`, `DatabaseResetController::destroy`, route `settings.backup.database.destroy`. Wayfinder regenerated (`@/routes/settings/backup/database`). |
| T3 — UI dialog and Backup page | DONE | `ResetDatabaseDialog.vue` (new, single root) mirrors `RemoveVaultDialog.vue`'s structure; `Backup.vue` gained the missing-vault hint and the Reset database section. |
| T4 — ADR amendments | DONE | Added the specified bullets to `vault-removal-and-rename-semantics.md` and `backup-restore-semantics.md`. |
| T5 — Tests | DONE | Three new Pest files covering FR-01–FR-11 per plan §4. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| created | `app/Support/RegistryResetResult.php` | `final readonly` DTO: `vaults`, `missingVaults`, `notes`, `summary()`. |
| modified | `app/Services/VaultService.php` | Added `resetRegistry(): RegistryResetResult` (imports `Note`, `RegistryResetResult`). |
| created | `app/Http/Requests/Settings/ResetDatabaseRequest.php` | `confirmation` required, `in:RESET` (case-sensitive by plain string comparison), custom messages. |
| created | `app/Http/Controllers/Settings/DatabaseResetController.php` | `destroy()`: calls `resetRegistry()`, flashes a success/error toast, redirects to `settings.backup.edit` on success, `back()` on a `QueryException`. |
| modified | `routes/settings.php` | Added `DELETE settings/backup/database` → `settings.backup.database.destroy`. |
| created | `resources/js/components/backups/ResetDatabaseDialog.vue` | Dialog: vault/missing counts, kept-items list, active-vault warning, typed `RESET` confirmation, `useForm` submit to `destroy()`. |
| modified | `resources/js/pages/settings/Backup.vue` | Added `missingVaultCount`, the Restore-section hint, and the final "Reset database" section with `ResetDatabaseDialog`. |
| modified | `.ai/decisions/vault-removal-and-rename-semantics.md` | Added the T4 bullet under "Remove". |
| modified | `.ai/decisions/backup-restore-semantics.md` | Added the T4 bullet under "Consequences → Negative". |
| created | `tests/Feature/Services/VaultRegistryResetTest.php` | Service-level tests: counts, settings kept/forgotten, backup history kept, filesystem snapshot untouched, idempotent on empty registry, atomicity via a SQLite `RAISE(ABORT)` trigger. |
| created | `tests/Feature/Settings/ResetDatabaseHttpTest.php` | HTTP tests: success toast + redirect + empty tables; validation dataset (missing/empty/`reset`/`RESETX`/`Reset`); post-reset `settings.backup.edit` Inertia props; DB-failure error toast; `GET` → 405. |
| created | `tests/Feature/Backups/BackupResetRestoreTest.php` | End-to-end: missing vaults → `inspect()` gives `exists`/`skip` → `resetRegistry()` → `inspect()` gives `new`/`restore` with the original name → `restore()` recovers original UUIDs/content; custom storage root survives; without a reset, `restore` throws `vaultAlreadyRegistered` and `copy` gets the `(restored)` suffix. |
| generated | `resources/js/routes/settings/backup/database/index.ts`, `resources/js/actions/...` | Wayfinder output for the new route. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | clean (`{"tool":"pint","result":"passed"}`) |
| `php artisan test --compact tests/Feature/Services/VaultRegistryResetTest.php tests/Feature/Settings tests/Feature/Backups tests/Feature/Vaults tests/Feature/Services/VaultServiceTest.php tests/Unit/ArchitectureTest.php` | 283 tests, 282 passed, 1 skipped (pre-existing skip, unrelated) |
| `php artisan test --compact` (full suite) | 761 tests, 750 passed, 11 skipped (pre-existing skips, unrelated), 0 failed |
| `vendor/bin/phpstan analyse` | 0 errors |
| `npm run types:check` | passed, no output (vue-tsc clean) |
| `npm run lint` | not configured in `package.json`; skipped |

No failures to paste.

---

## 4. Deviations from Plan
- None in the application code. Implemented T1–T5 as specified, including the exact transaction order, the DTO shape, the Form Request rule (`in:RESET`, which is case-sensitive by plain string inequality — verified with a `Reset`/`reset`/`RESETX` dataset), and the UI copy from the plan.
- Toast-assertion mechanics: the plan didn't prescribe an exact test helper. `assertInertiaFlash()`'s macro only supports exact-match assertions (`assertSame`), so the "contains" checks required by FR-08 (message containing "No files were deleted" / "Nothing was changed") read the flashed data via the existing `session('inertia.flash_data')` pattern already used in `VaultManagementTest.php`, rather than the macro.
- Wayfinder regeneration flag: `vite.config.ts` runs the Wayfinder Vite plugin with `formVariants: true`, so every existing generated file includes `*Form`/`.form` helpers. The plan said to run `php artisan wayfinder:generate --no-interaction` "if the Vite plugin isn't running"; running it without `--with-form` stripped the form variants from every previously generated file (a large, unintended diff). This was caught before finishing and corrected by re-running with `php artisan wayfinder:generate --with-form --no-interaction`, which restored the pre-existing files exactly and left only the new `settings/backup/database` route/action files plus one additive line each in `resources/js/actions/.../Settings/index.ts` and `resources/js/routes/settings/backup/index.ts`. Confirmed via `git status`/`git diff` that no other generated file differs from HEAD.

---

## 5. Notes for QA
- The atomicity test (FR-05) uses `DB::statement("CREATE TRIGGER fail_vault_delete BEFORE DELETE ON vaults BEGIN SELECT RAISE(ABORT, 'boom'); END")` inside the RefreshDatabase-wrapped test transaction; SQLite savepoints handle the nested `resetRegistry()` transaction correctly, and the trigger is discarded with the outer test transaction — no manual cleanup needed and no interference with other tests confirmed by the full-suite run.
- `resetRegistry()` deliberately does not call `refreshStatus()`/`FileStorageService` per the ADR — the `missingVaults` count is read from the persisted `status` column as-is (informational only), not re-verified against disk at reset time. This matches FR-01's stated behaviour but is worth a manual sanity check if QA wants to confirm the count against a vault whose folder was deleted very recently without the app having reconciled status yet.
- Manual desktop check (`php artisan native:serve` → delete a vault folder → Reset database → restore) was not run by the Senior Developer (no desktop runtime in this environment); this is called out in the plan as a manual check, not an automated one.
- The dialog's active-vault warning text and the missing-vault hint on `Backup.vue` were written to match the plan's exact wording; QA should visually confirm wrapping/spacing given the longer sentence lengths.

---

## 6. Fix Rounds
*None yet.*
