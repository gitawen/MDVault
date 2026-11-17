# Requirements: Include Application Settings in Backups

## Metadata
- **Feature Name**: Include Application Settings in Backups
- **Feature ID**: `backup-include-settings`
- **Author**: System Analyst
- **Created Date**: 2026-11-17
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault currently backs up and restores vaults, notes, directories, attachments, and encrypted vault headers (`format_version` 1 and 2), while explicitly excluding application settings (`ADR backup-archive-format`). When a user restores a backup on a new machine or after resetting local data, all preferences—such as Editor typography and frontmatter templates, Security auto-lock timeouts, Appearance theme, and General external-change detection—revert to defaults. Users want backups to preserve these portable application settings while continuing to exclude local backup history (`backups` table) and machine-specific storage paths (`storage.*`).

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Export all portable application settings (`Appearance`, `Editor`, `Security`, and portable `General` preference `app.check_external_changes`) into newly created backup archives (`scope: 'all'` and `scope: 'vault'`).
- Strictly exclude backup history (`backups` table), machine-local storage paths (`storage.root_path`, `storage.folder_name`), and transient workspace selection (`app.current_vault`) from both backup archives and restore mutations.
- Validate backed-up settings during `inspect()` and `restore()` using the same constraints enforced by the Settings Form Requests.
- Maintain full backward compatibility with older format 1 and format 2 backup archives (which contain no settings).
- Surface backed-up setting groups, conflict counts (differences vs. current settings), and safe defaults in `BackupInspection` and `RestoreBackupDialog.vue`.
- Allow selective per-group settings restore (`appearance`, `editor`, `general`, `security`), including restoring settings alone when all vaults are skipped.
- Apply restored settings atomically with vault commits and update live UI state (including `appearance` theme) immediately upon restore.

### Out of Scope (Non-Goals)
- Backing up or restoring backup history (`backups` table).
- Backing up or restoring machine-local storage configuration (`storage.root_path`, `storage.folder_name`) or active vault pointer (`app.current_vault`).
- Standalone settings-only backup creation when no active vault exists (`BackupService::create()` continues to require at least one active vault).
- Standalone JSON settings export/import outside backup `.zip` archives.
- Changing encrypted vault key custody (no passwords or key material are added to settings or manifest).

---

## 3. User Personas & Stories
- **As a** user migrating MDVault to a new device or recovering from data loss,
  **I want** my backups to include my Editor, Security, Appearance, and General settings (without backup history or old device paths),
  **So that** restoring a backup reproduces my full writing and security setup.
- **As a** user importing a vault backup onto an already-configured installation,
  **I want** to see whether the backup includes settings that differ from my current preferences and choose which setting groups (if any) to restore,
  **So that** my existing theme, editor, or security policies are never unexpectedly overwritten.
- **As a** user who accidentally misconfigured editor or security settings,
  **I want** to restore only my settings from a recent backup while skipping all already-registered vaults,
  **So that** I can recover my preferences without creating duplicate "(restored)" vault copies.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | **Portable Settings Classification** | Define an explicit, exhaustive key-level classification on `SettingKey` (`isPortable()`) and `SettingGroup` (`label()`, `isPortable()`). Portable keys: `appearance.theme`, all 9 `editor.*` keys, `security.auto_lock_minutes`, `security.lock_on_screen_lock`, and `app.check_external_changes`. Non-portable keys: `storage.root_path`, `storage.folder_name`, `app.current_vault`. | Given any `SettingKey` case, When `isPortable()` is called, Then the 13 portable preference keys return `true` and `StorageRootPath`, `StorageFolderName`, `CurrentVault` return `false`. |
| **FR-02** | **Export Settings into Manifest (Format 3)** | Bump `BackupService::FORMAT_VERSION` to `3`. Both `all` and `vault` backups write `"settings"` in `manifest.json` containing the effective value (`SettingsService::get()`) of every portable key sorted lexicographically, preserving float precision. | Given customized Editor, Security, Appearance, General, Storage, and CurrentVault settings plus records in `backups`, When `BackupService::create()` runs for all vaults or a single vault, Then `manifest.json` has `format_version: 3`, includes all 13 portable settings with effective values, and contains no `storage.*`, `app.current_vault`, or backup history. |
| **FR-03** | **Exclude Backup History** | Backup creation must never read or embed rows from the `backups` table into the ZIP or manifest; backup inspection and restore must never read, insert, update, or delete rows in `backups`. | Given existing records in `backups`, When a backup is created and later restored on an installation with different `backups` rows, Then the archive contains no backup history and the target's `backups` table remains unchanged by restore. |
| **FR-04** | **Strict Settings Validation** | `BackupService::validateArchive()` validates `"settings"` for `format_version >= 3`: requires a JSON object; rejects known non-portable keys (`storage.*`, `app.current_vault`); ignores unknown keys; and validates every present portable key against its Form Request rules (types, enums, integer/float ranges, allowed auto-lock choices, UTF-8, `<= 4000` chars, no NUL bytes, valid frontmatter YAML without bare `---`, non-empty template when enabled). | Given an archive with invalid setting types, out-of-range numbers, unknown enum values, invalid auto-lock minutes, bare `---` in `new_note_template`, empty enabled template, or injected `storage.root_path` / `app.current_vault`, When `inspect()` or `restore()` runs, Then `inspect()` returns `valid: false` with problems and `restore()` throws `BackupException::invalidBackup` without modifying DB or disk. |
| **FR-05** | **Backward Compatibility (Formats 1 & 2)** | `BackupService::validateArchive()` continues to accept `format_version` `1`, `2`, and `3`. Archives with `format_version` `1` or `2` without `"settings"` inspect with `backup.settings === null` and restore vaults identically to before. | Given a valid format-1 or format-2 backup without `"settings"`, When `inspect()` or `restore()` runs, Then inspection succeeds with `backup.settings === null` and vault restore succeeds; requesting `setting_groups` on a backup without settings fails with a clear error. |
| **FR-06** | **Inspection Summary & Conflict Detection** | `BackupInspection::$backup['settings']` reports per-group metadata (`group`, `label`, `count`, `differing`), `total_count`, `differing_count`, and `default_restore`. `default_restore` is `true` only when no vaults are registered AND all local portable settings currently equal their defaults; otherwise `false`. | Given a format-3 backup inspected on a fresh install vs. an installation with customized settings or registered vaults, When `inspect()` runs, Then `differing` counts match keys differing from current `SettingsService::get()`, and `default_restore` is `true` only on the fresh unconfigured state. |
| **FR-07** | **Selective & Settings-Only Restore** | `BackupService::restore()` and `RestoreBackupRequest` accept optional `setting_groups` (`appearance`, `editor`, `general`, `security`). Selected groups restore only their portable keys (keeping `storage.*`, `app.current_vault`, and `backups` intact, storing default matches as `null`/sparse, and normalizing `new_note_template` CRLF/trailing newlines). When all vaults are `Skip` and at least one setting group is selected, restore succeeds without requiring storage root or staging directories. | Given a format-3 backup, When user restores selected setting groups alongside vaults OR with all vaults set to `skip`, Then only the chosen groups' portable keys are updated, `storage.*` / `app.current_vault` / `backups` remain untouched, and if neither vaults nor setting groups are selected, validation/service refuses the request. |
| **FR-08** | **Atomic Restore & Rollback Safety** | When restoring vaults and settings together, `SettingsService::setMany()` executes inside the same DB transaction as vault/note/encryption commits after vault folder moves succeed. Any DB or move failure rolls back both vaults and settings. | Given a multi-vault + settings restore where the second vault's folder move or DB insert fails, When `restore()` throws, Then `settings` rows remain unchanged from before the call. |
| **FR-09** | **Live UI & Theme Propagation** | `HandleInertiaRequests` shares `appearance`, `AppLayout.vue` watches and syncs theme changes immediately via `syncAppearance()`, `Backup.vue` copy accurately describes included settings and excluded storage/backup history, and `RestoreBackupDialog.vue` renders per-group settings checkboxes, conflict counts, and settings-only restore capability. | Given a backup with `appearance.theme = 'dark'` restored via the Restore dialog while currently in `'light'` mode, When the Inertia response completes, Then the restored theme applies immediately to `document.documentElement` and settings pages reflect all restored preferences. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - No secrets, encryption keys, salts, nonces, passwords, or machine-local absolute paths may be written into `manifest.json` settings.
  - Untrusted archive settings must pass strict schema, type, range, and YAML validation before any DB write.
  - `App\Models\Setting` may only be accessed through `App\Services\SettingsService` (enforced by Pest arch rule in `tests/Unit/ArchTest.php`).
- **Performance**:
  - Reading/writing 13 settings via `SettingsService` uses a single memoized query / single transaction (`setMany`) with negligible overhead.
  - Settings-only restore skips archive file stream-extraction and storage-root probing.
- **Accessibility & UX**:
  - `RestoreBackupDialog.vue` setting group checkboxes use accessible `Label` bindings (`for`/`id`), keep a single root element per Vue component, and clearly highlight differing settings before overwrite.
- **Reliability & Data Integrity**:
  - Combined vault + settings restores execute inside one SQLite transaction (`BackupService::restore()`), leaving zero partial state on failure.
  - `BackupService` uses no raw filesystem functions and delegates archive I/O to `ArchiveService`.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13.33.0 (PHP 8.4)
- Frontend: Inertia.js v3 (`@inertiajs/vue3` ^3.0.0) + Vue 3 (^3.5.13) + Tailwind CSS v4 (^4.1.1)
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`)
- Testing: Pest 5 (^5.2)
- Static Analysis & Style: PHPStan level 7 (`vendor/bin/phpstan analyse`), Laravel Pint (`vendor/bin/pint --dirty --format agent`), `npm run types:check` (`vue-tsc --noEmit`)
- Database: SQLite (no schema migration required)

---

## 7. Risks & Assumptions
- **Assumption 1**: `app.check_external_changes` (group `General`) is a portable user preference safe to include alongside `Editor`, `Security`, and `Appearance`, whereas `app.current_vault` (also group `General`) is strictly local session state and must be excluded.
- **Assumption 2**: Format 1 and format 2 builds have not shipped externally (`encrypted-vault-backups.md`), so bumping `FORMAT_VERSION` from `2` to `3` while retaining read support for `1` and `2` is clean and safe.
- **Risk 1 & Mitigation**: JSON encodes whole floats like `2.0` as `2` (`int`), which strict float checks could reject. *Mitigation*: Encode with `JSON_PRESERVE_ZERO_FRACTION` on manifest serialization and accept `(is_int($v) || is_float($v)) && is_finite((float) $v)` during validation, comparing floats with epsilon tolerance (`1e-9`).
- **Risk 2 & Mitigation**: Restoring `appearance.theme` over Inertia SPA redirects would not re-render `app.blade.php`'s `data-appearance` attribute, leaving the old theme active until app restart. *Mitigation*: Share `appearance` in `HandleInertiaRequests` and sync it reactively in `AppLayout.vue`.
- **Risk 3 & Mitigation**: Existing tests (`BackupCreateTest.php`, `EncryptedBackupTest.php`) assert `format_version === 2` on newly generated backups. *Mitigation*: Update those exact assertions to `3` and add explicit backward-compatibility tests for format 1 and format 2.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)