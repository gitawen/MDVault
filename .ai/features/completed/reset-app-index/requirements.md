# Requirements: Reset database (vault registry reset before a fresh restore)

## Metadata
- **Feature Name**: Reset database (vault registry reset)
- **Feature ID**: mdv-p6-reset
- **Master Plan Phase**: Phase 6, Backup and Restore (§55 acceptance: "Remove local data → Fresh application state → Import backup"). Related sections: §7 (detect missing vaults), §18, §38, §39 (Rebuild Index), §41 (`VaultService`), §43, §62; Rules 1, 2, 4, 5, 9, 10.
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 3, Complex Development (with ADR `database-reset-semantics`)
- **Status**: APPROVED (D1–D6 as recommended)

---

## 1. Problem Statement
The user deleted vault folders by hand. MDVault still lists every vault (FMTS, HRIS, KURTS, NEW TESTING, TESTING WITH NOTEPAD), each marked Missing. They want to start from a clean state and restore a backup.

Restore can't get them there today:
- `BackupService::inspect()` and `restore()` compare each backup vault's UUID with the registry. The missing vaults are still registered, so every vault shows as `exists`, and the default action is `skip`.
- Choosing `restore` fails with `vaultAlreadyRegistered`.
- "Restore as a copy" creates `FMTS (restored)` and so on, because a missing vault's name still counts as taken.

The only workaround is to remove each vault one at a time on the Vaults page.

The user asked for a `migrate:fresh`-style "Reset database" that clears the data so a backup can be restored fresh.

## 2. Goals & Non-Goals
### In Scope (Goals)
- A "Reset database…" action in Settings → Backup that clears MDVault's vault and note registry in one step, so a backup can then be restored with the original names and UUIDs.
- Explicit confirmation: a dialog in which the user types `RESET`, also checked on the server.
- The logic lives in a service (`VaultService`) and runs in one DB transaction. Nothing on disk is ever touched.
- Settings (except the current vault) and backup history are kept (D3, D4).
- Tests at service, HTTP and end-to-end restore level.

### Out of Scope (Non-Goals)
- Running `migrate:fresh` or any other schema operation from the app (D1).
- Deleting, trashing or moving any file or folder, including leftover `.mdvault-restore-*` staging folders.
- Resetting settings to their defaults (D3) or clearing backup history (D4), unless the user chooses otherwise.
- Automatic re-registration or relocation of vault folders (the separate, deferred "vault relocation" item).
- Replace-in-place restore. ADR `backup-restore-semantics` leaves this out of v1.
- Phase 7 `vault_encryption` handling. The table doesn't exist yet; see the ADR follow-up.

## 3. User Personas & Stories
- **As a** user whose vault folders were deleted outside MDVault,
  **I want to** reset MDVault's database in one action,
  **So that** I can restore my backup with the original vault names and identities, instead of copies named "(restored)".
- **As a** user,
  **I want** the reset to leave my files and preferences alone,
  **So that** a mistaken click never costs me notes or settings.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Registry reset service | `VaultService::resetRegistry()` deletes every `notes` row and every `vaults` row, and forgets `app.current_vault`. All of this happens in one DB transaction. It returns counts: vaults, missing vaults and notes. | Given 2 vaults (1 missing) with 3 notes and a current vault, when `resetRegistry()` runs, then the `vaults` and `notes` tables are empty, `app.current_vault` is unset, and the result is `{vaults: 2, missingVaults: 1, notes: 3}`. |
| **FR-02** | Never touches disk | The reset makes no filesystem calls. Vault folders, notes, other files, the storage root and staging folders all stay exactly as they were. | Given vault folders containing `.md` files, other files, nested folders and a `.mdvault-restore-*` folder, when the reset runs, then a before/after snapshot of every path and its SHA-256 under the temp root is identical. |
| **FR-03** | Settings kept (D3) | Only `app.current_vault` is removed. Every other `settings` row is unchanged. | Given a custom theme, storage root, folder name and editor font size, when the reset runs, then each still reads the same value through `SettingsService`. |
| **FR-04** | Backup history kept (D4) | `backups` rows are not touched. | Given 2 backup records, when the reset runs, then `backups` still has 2 rows, and Settings → Backup still lists them. |
| **FR-05** | Atomic | If any DB statement fails, nothing changes. | Given a DB failure while vaults are being deleted, when the reset runs, then an exception is raised, all vault and note rows remain, and `app.current_vault` is unchanged. |
| **FR-06** | Idempotent | Resetting an empty registry succeeds and reports zeros. | Given no vaults, when the reset runs, then it returns all-zero counts with no error. |
| **FR-07** | Typed confirmation (server) | `DELETE settings/backup/database` requires `confirmation` to be exactly `RESET` (case-sensitive). | Given `confirmation` missing, `reset` or `RESETX`, when the request is sent, then a validation error appears on `confirmation` and nothing is deleted. Given `RESET`, then the registry is cleared. |
| **FR-08** | Result feedback | On success: redirect to Settings → Backup with a success toast giving the counts and saying "No files were deleted". On a DB failure: an error toast saying "Nothing was changed". | Given a successful reset, then the response redirects to `settings.backup.edit`, and the flashed toast has type `success` and contains "No files were deleted". Given a DB failure, then the toast has type `error` and contains "Nothing was changed". |
| **FR-09** | UI: dialog | Settings → Backup gains a "Reset database" section with a destructive "Reset database…" button that opens a dialog. The dialog shows: the vault count and missing count; what is removed (the list of vaults and notes in MDVault); what is kept (files on disk, settings, backup history); and a warning when any vault is still active on disk. The confirm button stays disabled until the input equals `RESET`. The trigger is disabled when no vaults are registered (D5). | Given 5 missing vaults, when the dialog opens, then it shows "5 vaults (5 missing)", and the confirm button enables only after `RESET` is typed. Given 0 vaults, then the trigger is disabled. |
| **FR-10** | UI: restore hint | When any registered vault is missing, the Restore section tells the user that a backup of those vaults will be skipped or restored as copies, and suggests "Reset database" (or removing them on the Vaults page) first. | Given at least one missing vault, then the hint is visible. Given none, then it is hidden. |
| **FR-11** | Fresh restore after reset | After a reset, restoring a backup of the deleted vaults uses the default action `restore`, the original names (no "(restored)" suffix), the original vault and note UUIDs, the current storage root, and byte-identical content. | Given vaults backed up and then deleted from disk, when the database is reset and the backup is inspected, then each vault has state `new` and `restore_name` equal to its original name. When it is restored with the default actions, then each vault is active at `<root>/<name>` with its original UUIDs and identical file hashes. |

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - This is a local single-user app with no authentication (ADR `local-app-without-authentication`).
  - Only non-idempotent verbs are used (`DELETE`), so CSRF applies.
  - The typed confirmation is enforced on the server, not only in the UI.
  - No paths or file contents appear in logs. Only `report()` is called on an unexpected DB exception.
- **Performance**: two bulk `DELETE` statements plus one settings delete. There is no per-row model loading and no filesystem access.
- **Accessibility & UX**:
  - Single-root Vue components.
  - The dialog uses the shadcn-vue `Dialog` with a labelled input, and focus is trapped by the component.
  - The confirm button is `variant="destructive"`.
  - The wording avoids the phrase "delete your notes".
- **Reliability & Data Integrity**:
  - One transaction (FR-05).
  - The filesystem is never touched, so no filesystem/DB compensation is needed (Rule 10).
  - Notes are deleted explicitly before vaults, so correctness doesn't rely on the FK cascade being enabled.

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4); NativePHP desktop runtime (the DB is `nativephp.sqlite` in native, `database/database.sqlite` in browser dev). The reset behaves the same way in both.
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4 + shadcn-vue
- Routing: Laravel Wayfinder (`@/routes/settings/backup/database`)
- Testing: Pest 5
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`)
- Database: SQLite (metadata/index only). `SESSION_DRIVER=database` and `CACHE_STORE=database`, so the reset must never drop or clear `sessions`, `cache`, `jobs` or `migrations`.
- Arch rules apply: `App\Models\Setting` is used only in `SettingsService`; the `Vault` and `Note` models are used only in services, controllers, models and factories; services are `final` and don't use the HTTP layer.

## 7. Risks & Assumptions
- **Assumption**: the user wants every vault removed from the list (D2), not only the missing ones. Active vaults are only unregistered, and their folders stay on disk.
- **Risk: an active vault is reset by mistake.** Its UUID and note UUIDs are lost; re-adding the folder gives new ones. **Mitigation:** the dialog lists active vaults and suggests "Back up all vaults" first. Files are never touched, and "Add existing folder" brings the vault back.
- **Risk: an active vault's folder is still on disk, and the user then restores a backup of it.** Restore never merges, so it lands as "Name (restored)". **Mitigation:** the dialog warning explains this and recommends "Add existing folder" for vaults still on disk.
- **Risk: Phase 7 encryption metadata.** If it is kept only in SQLite, a reset would make encrypted vaults unreadable. **Mitigation:** an ADR follow-up requires Phase 7 to address this before `vault_encryption` exists.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [ ] Approved to proceed to Planning (`plan.md`). Waiting on D1–D6.
