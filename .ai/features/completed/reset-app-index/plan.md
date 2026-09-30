# Plan: Reset database (vault registry reset)

## Metadata
- **Feature Name**: Reset database (vault registry reset)
- **Feature ID**: mdv-p6-reset
- **Master Plan Phase**: **Implements an increment to Master Plan Phase 6, Backup and Restore (`docs/Masterplan.md` §55). Related sections: §7, §38, §39, §41, §43, §62; Rules 1, 2, 4, 5, 9, 10.**
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 3, Complex Development
- **Requirements**: `requirements.md`
- **Status**: **APPROVED** (2026-09-30): user approved D1–D6 as recommended.

---

## 1. Summary
A new `VaultService::resetRegistry()` does the following in one DB transaction:
- deletes every `notes` row, then every `vaults` row;
- forgets `app.current_vault`.

It never touches the filesystem, other settings, backup history, sessions, cache or migrations.

A `DELETE settings/backup/database` endpoint exposes it, guarded by a typed confirmation (`RESET`). Settings → Backup gains a "Reset database…" dialog, plus a hint when vaults are missing.

After a reset, the existing restore flow sees the backup's vaults as new and restores them with their original names and UUIDs. This is the §55 "fresh application state → import" path. A literal `migrate:fresh` was rejected (ADR `database-reset-semantics`).

---

## 2. Architecture & Design
- **Approach**: a service-level registry reset in `VaultService`, which owns the vault lifecycle and removal (§41). It uses bulk query deletes inside `$this->database->connection()->transaction(...)`. The current vault is forgotten through `SettingsService`. The HTTP layer stays thin, and a Form Request enforces the confirmation. The UI adds one component and small changes to `Backup.vue`.
- **Alternatives Considered**:
  - `Artisan::call('migrate:fresh', ['--force' => true])`: rejected. It drops `sessions` and `cache` mid-request (both use the database driver). It loses the storage root and folder name (so a restore goes to a different folder), backup history, and theme and editor settings. It is a schema operation run over HTTP and can't be tested cleanly.
  - Truncating every app table except `migrations`: rejected for the same settings and backup-history reasons (D3, D4). It also clears `sessions` and `cache`.
  - Bulk "remove missing vaults only": a valid, narrower option. It is offered as D2, with "all vaults" recommended because that is what the user asked for.
  - Looping `VaultService::remove()` over every vault: rejected. It makes N transactions (not atomic) and does a status-refresh write for each vault.
  - A new `ResetService`: rejected. It would be one method, and removing vaults is `VaultService`'s responsibility (§41).
- **Decision Records**: `.ai/decisions/database-reset-semantics.md` (new). Short amendments to `vault-removal-and-rename-semantics.md` and `backup-restore-semantics.md` in T4.

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| — | none | No migrations. No change to the `notes.content` column or anything else. |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| DTO (new) | `app/Support/RegistryResetResult.php` | `final readonly` class with `int $vaults`, `int $missingVaults`, `int $notes`, and a `summary(): string` method |
| Service (modify) | `app/Services/VaultService.php` | New `resetRegistry(): RegistryResetResult` |
| Form Request (new) | `app/Http/Requests/Settings/ResetDatabaseRequest.php` | `confirmation`: `required`, `string`, `in:RESET`, with message "Type RESET to confirm." |
| Controller (new) | `app/Http/Controllers/Settings/DatabaseResetController.php` | `destroy(ResetDatabaseRequest, VaultService): RedirectResponse` |
| Routes (modify) | `routes/settings.php` | One route (below) |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Component (new) | `resources/js/components/backups/ResetDatabaseDialog.vue` | Trigger button, dialog, typed confirmation, `useForm` submit |
| Page (modify) | `resources/js/pages/settings/Backup.vue` | New "Reset database" section at the bottom; missing-vault hint in the Restore section |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| DELETE | `/settings/backup/database` | `settings.backup.database.destroy` | `Settings\DatabaseResetController@destroy` | `web` (same group as the other settings routes) |

---

## 3. Implementation Tasks

- [ ] **T1: Service method and result DTO**
  - Files: `app/Support/RegistryResetResult.php` (new), `app/Services/VaultService.php`
  - Command: `php artisan make:class Support/RegistryResetResult --no-interaction`, then make it `final readonly` with a constructor promoting `int $vaults, int $missingVaults, int $notes`.
  - Details:
    - Add `public function resetRegistry(): RegistryResetResult` to `VaultService`. Its docblock should reference ADR `database-reset-semantics`.
    - Inside `$this->database->connection()->transaction(function () { ... })`:
      1. `$vaults = Vault::query()->count();`, `$missing = Vault::query()->where('status', VaultStatus::Missing)->count();` and `$notes = Note::query()->count();`
      2. `Note::query()->delete();` (explicitly first; don't rely on the FK cascade)
      3. `Vault::query()->delete();`
      4. `$this->settings->forget(SettingKey::CurrentVault);`
      5. Return the DTO.
    - **No** `FileStorageService`, `StoragePathService` or raw filesystem calls. No `refreshStatus()`, because it writes and does an `is_dir` check per vault. The stored status is informational only.
    - **Don't** touch `backups`, `settings` (other than the current vault), `sessions`, `cache`, `jobs` or `migrations`.
    - Exceptions propagate, and the transaction rolls back.
    - `RegistryResetResult::summary()` returns: `"Removed {n} vault(s) and {m} note record(s) from MDVault. No files were deleted."`
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-06

- [ ] **T2: Form Request, controller and route**
  - Files: `app/Http/Requests/Settings/ResetDatabaseRequest.php`, `app/Http/Controllers/Settings/DatabaseResetController.php`, `routes/settings.php`
  - Commands: `php artisan make:request Settings/ResetDatabaseRequest --no-interaction`, `php artisan make:controller Settings/DatabaseResetController --no-interaction`
  - Details:
    - Request:
      - `authorize()` returns `true`.
      - Rules: `'confirmation' => ['required', 'string', 'in:RESET']` (case-sensitive).
      - `messages()` maps `confirmation.required` and `confirmation.in` to "Type RESET to confirm."
    - Controller `destroy()`:
      - `try { $result = $vaults->resetRegistry(); } catch (QueryException $e) { report($e); Inertia::flash('toast', ['type' => 'error', 'message' => "The database couldn't be reset. Nothing was changed."]); return back(); }`
      - On success: `Inertia::flash('toast', ['type' => 'success', 'message' => $result->summary().' You can now restore a backup.']); return to_route('settings.backup.edit');`
    - Route: `Route::delete('settings/backup/database', [DatabaseResetController::class, 'destroy'])->name('settings.backup.database.destroy');` Place it after the restore routes and use the same `use` import style.
    - Run `php artisan wayfinder:generate --no-interaction` if the Vite plugin isn't running.
  - Covers: FR-07, FR-08

- [ ] **T3: UI dialog and Backup page**
  - Files: `resources/js/components/backups/ResetDatabaseDialog.vue` (new), `resources/js/pages/settings/Backup.vue`
  - Details:
    - `ResetDatabaseDialog.vue`:
      - Single root element. Mirror `RemoveVaultDialog.vue`: `Dialog`/`DialogTrigger as-child`/`DialogContent`/`DialogHeader`/`DialogFooter`, plus `Input`, `Label` and `InputError`.
      - Use `usePage().props.vaults` to compute `total`, `missing` and `active` (and the names of active vaults).
      - Trigger: `<Button variant="destructive">Reset database…</Button>`, `:disabled="total === 0"`.
      - Title: "Reset MDVault database?"
      - Description: "This removes N vault(s) (M missing) and their note records from MDVault's list."
      - List: "Kept: your files and folders on disk, your settings, your backup history."
      - When `active > 0`, a warning names those vaults: "These folders still exist on disk and will stay there. To bring them back afterwards, use Vaults → Add existing folder. Restoring them from a backup would create '(restored)' copies. Consider 'Back up all vaults' first."
      - Input `id="reset-database-confirmation"` with the label: "Type RESET to confirm".
      - `useForm({ confirmation: '' })`, submitted with `form.submit(destroy(), { preserveScroll: true, onSuccess: () => { open.value = false; form.reset(); } })`. `destroy` is imported from `@/routes/settings/backup/database`.
      - Confirm button: `variant="destructive"`, `:disabled="form.confirmation !== 'RESET' || form.processing"`, text "Reset database".
      - Reset the form when the dialog closes.
    - `Backup.vue`:
      - Compute `missingVaultCount` from `page.props.vaults`.
      - In the Restore section, when `missingVaultCount > 0`, show a muted paragraph: "N vault(s) are missing from disk. A backup of them would be skipped or restored as copies. Reset the database below (or remove them on the Vaults page) before restoring."
      - Add a final section: `<Heading variant="small" title="Reset database" description="Clear MDVault's list of vaults and notes so you can restore a backup from scratch. Files on disk are never deleted." />` followed by `<ResetDatabaseDialog />`.
  - Covers: FR-09, FR-10

- [ ] **T4: ADR amendments (documentation only)**
  - Files: `.ai/decisions/vault-removal-and-rename-semantics.md`, `.ai/decisions/backup-restore-semantics.md`
  - Details:
    - Removal ADR, under "Remove": add a bullet saying "Bulk: `VaultService::resetRegistry()` unregisters every vault in one transaction and never touches the filesystem (ADR `database-reset-semantics`)."
    - Restore ADR, under "Consequences → Negative": add "Registered-but-missing vaults block a same-UUID restore; the documented path is Settings → Backup → Reset database, then restore (ADR `database-reset-semantics`)."
  - Covers: traceability

- [ ] **T5: Tests** (see §4). Write them alongside T1–T3. Reuse the `fakeDocumentsDirectory()` and `writeVaultFiles()` helpers and the temp-dir pattern from `tests/Feature/Backups/BackupRestoreTest.php`.
  - Covers: FR-01 to FR-11

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Services/VaultRegistryResetTest.php` | Removes all vault and note rows and returns `{vaults: 2, missingVaults: 1, notes: 3}` | FR-01 |
| same | Forgets `app.current_vault`; theme, `storage.root_path`, `storage.folder_name` and `editor.font_size` are unchanged (read through `SettingsService`) | FR-01, FR-03 |
| same | `Backup::factory()` rows survive | FR-04 |
| same | Filesystem snapshot (every path under the temp root → SHA-256, including a nested folder, a non-`.md` file, an unregistered folder and a `.mdvault-restore-abc` folder) is identical before and after; the storage root still exists | FR-02 |
| same | An empty registry returns zeros with no exception | FR-06 |
| same | Atomicity: `DB::statement("CREATE TRIGGER fail_vault_delete BEFORE DELETE ON vaults BEGIN SELECT RAISE(ABORT, 'boom'); END")` makes it throw `QueryException`; note count and vault count unchanged; `app.current_vault` unchanged | FR-05 |
| `tests/Feature/Settings/ResetDatabaseHttpTest.php` | `DELETE settings.backup.database.destroy` with `RESET` → redirect to `settings.backup.edit`; flashed toast has `type` `success` and contains "No files were deleted"; tables empty | FR-07, FR-08 |
| same | Dataset: missing / `''` / `reset` / `RESETX` / `Reset` → `assertSessionHasErrors('confirmation')`; rows intact | FR-07 |
| same | After a reset, GET `settings.backup.edit` → `assertInertia`: shared `vaults` is `[]`, `backups` still lists the existing records | FR-04, FR-09 |
| same | DB failure (the trigger above) → toast `type` `error` containing "Nothing was changed"; rows intact | FR-05, FR-08 |
| same | `GET settings/backup/database` → 405 | FR-07 |
| `tests/Feature/Backups/BackupResetRestoreTest.php` | **The user's scenario, end to end:** create Work and Personal with notes → back up all → `File::deleteDirectory` both vault folders → `VaultService::all()` shows both Missing → `inspect()` gives `state` `exists`, default action `skip` → `resetRegistry()` → `inspect()` gives `state` `new`, `restore_name` equal to the original name, default `restore` → `restore()` with the default actions → both Active at `<root>/<name>`, original vault and note UUIDs, file hashes identical to the backup | FR-11 |
| same | A custom storage root set before the reset is still used by the restore (the vaults land under the custom root) | FR-03, FR-11 |
| same | Without a reset: `restore` on a registered UUID throws `vaultAlreadyRegistered`; `copy` gets the `Work (restored)` name (documents why the reset exists) | FR-11 (rationale) |
| `tests/Unit/ArchitectureTest.php` | Existing rules must still pass (Setting only in `SettingsService`; services are final and HTTP-free) | NFR |

**Test scope for QA**:
- `php artisan test --compact tests/Feature/Services/VaultRegistryResetTest.php tests/Feature/Settings tests/Feature/Backups tests/Feature/Vaults tests/Feature/Services/VaultServiceTest.php tests/Unit/ArchitectureTest.php`
- then the full `php artisan test --compact`
- `vendor/bin/pint --dirty --format agent`
- `vendor/bin/phpstan analyse`
- `npm run types:check`
- `npm run lint` if it is configured

**Manual check (desktop)**: `php artisan native:serve` → delete a vault folder in Explorer → Settings → Backup → Reset database… → restore the backup → the vault reappears with its original name.

---

## 5. Risks & Mitigations
- **Risk**: the user resets while active vaults exist and loses their UUIDs. **Mitigation**: the dialog lists active vaults and suggests backing up first. No files are touched, and "Add existing folder" recovers the vault.
- **Risk**: the restore after a reset collides with folders still on disk and produces "(restored)" names. **Mitigation**: the dialog warning (T3) and the existing restore semantics (no merge, by design).
- **Risk**: someone later "simplifies" the reset into `migrate:fresh`, or adds file deletion to it. **Mitigation**: the ADR, the service docblock, and the FR-02 filesystem-snapshot test.
- **Risk**: SQLite FK enforcement is off in some runtime, leaving orphan notes. **Mitigation**: notes are deleted explicitly before vaults.
- **Risk**: Phase 7 encryption metadata is lost on a reset or remove. **Mitigation**: ADR follow-up; Phase 7 must decide before `vault_encryption` ships.

---

## 6. Open Questions
- [x] **D1 Mechanism.** A service-level registry reset (**recommended**), or a literal `migrate:fresh` (not recommended: it breaks sessions and cache mid-request, and loses the storage root, settings and backup history)?
- [x] **D2 Scope.** Remove **all** vaults (**recommended**, matches the request), only missing vaults, or let the user choose?
- [x] **D3 Settings.** Keep all settings except the current vault (**recommended**), or also reset them to their defaults?
- [x] **D4 Backup history.** Keep it (**recommended**, so the user can restore from the Recent backups list), or clear it?
- [x] **D5 Availability.** Allow it whenever at least one vault is registered, with a hint when some are missing (**recommended**), or only when at least one vault is missing?
- [x] **D6 Label and confirmation.** A "Reset database…" button in Settings → Backup with typed `RESET` (**recommended**), or a different label or phrase?

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-30 | Initial plan | — |
