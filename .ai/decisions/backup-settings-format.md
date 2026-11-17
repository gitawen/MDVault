# ADR: Portable application settings in backups (format 3)

- **Status**: Accepted (Level 3 analysis for `backup-include-settings`)
- **Date**: 2026-11-17
- **Feature**: `backup-include-settings`
- **Amends**: `backup-archive-format`, `backup-restore-semantics`, `settings-persistence`

## Context
- Format 1 (`backup-archive-format`) and format 2 (`encrypted-vault-backups`) intentionally excluded settings from backups (`Settings in backups: not included in format 1`).
- Users restoring a backup onto a new machine or after wiping local data lose their configured Editor preferences (typography, layout, new-note frontmatter template), Security policy (auto-lock timeout, screen-lock behavior), Appearance theme, and General external-change detection preference.
- At the same time, MDVault stores machine-local state in `settings` (`storage.root_path`, `storage.folder_name`, `app.current_vault`) and local snapshot history in the `backups` table. Backup archives must remain strictly portable (no absolute paths), secret-free, and free of local backup history.

## Options Considered
1. **Where to store settings in the ZIP archive**:
   - (a) A separate `settings.json` entry alongside `manifest.json` and `vaults/`. Requires special-casing Stage 7 entry-set accounting and separate hash/size validation outside vault `files`/`total_bytes`.
   - (b) **Inside `manifest.json` under a top-level `"settings"` object with `FORMAT_VERSION = 3` (chosen).** `manifest.json` is already bounded (`MANIFEST_MAX_BYTES`), JSON-depth-checked, CRC-verified by `ZipArchive::CHECKCONS`, and validated in `BackupService::validateArchive()` for `create()`, `inspect()`, and `restore()`. Portable settings total only a few kilobytes.
2. **Which settings to include**:
   - (a) Include every row in `settings` (`Setting::all()`). Rejected: leaks machine-specific absolute paths (`storage.root_path`), breaks cross-OS portability (`backup-archive-format`), and corrupts active vault state (`app.current_vault`).
   - (b) Include sparse non-default rows only. Rejected: restoring onto an already-customized installation would produce a partial hybrid because settings left at default on the source machine wouldn't reset modified keys on the target.
   - (c) **Export effective values of explicitly allow-listed portable keys via `SettingKey::isPortable()` and `SettingsService::get()` (chosen).** Includes `appearance.theme`, all 9 `editor.*` keys, `security.auto_lock_minutes`, `security.lock_on_screen_lock`, and `app.check_external_changes`. Excludes `storage.root_path`, `storage.folder_name`, `app.current_vault`, and the entire `backups` table (backup history).
3. **Backup scope (`all` vs `vault`)**:
   - (a) Include settings only in `scope = "all"` backups. Rejected: single-vault backups are often used to migrate a sole vault, and restore-time selection lets users skip settings when importing a vault into an existing installation.
   - (b) **Include portable settings in both `all` and `vault` backups (chosen).**
4. **Restore granularity & defaults**:
   - (a) Always overwrite all settings on every restore. Rejected: violates non-destructive restore semantics (`backup-restore-semantics`) when importing a vault into an existing configured installation.
   - (b) Single boolean opt-in ("restore all settings"). Doesn't resolve partial conflicts (e.g. wanting backed-up Editor/Appearance settings while preserving the current device's stricter Security auto-lock).
   - (c) **Per-group opt-in (`appearance`, `editor`, `general`, `security`) with conflict summary and smart defaults (chosen).** Defaults to checked (`default_restore: true`) only when the installation has no registered vaults and all portable settings are at defaults; otherwise defaults to unchecked (`default_restore: false`). Supports settings-only restore when all vaults are `skip`.

## Decision
- **Format Version**: Bump `BackupService::FORMAT_VERSION` from `2` to `3`. `validateArchive()` continues to accept `format_version` `1`, `2`, and `3`. Format 1 and 2 archives without `"settings"` remain valid and restore with `settings: null`.
- **Manifest Schema (format 3)**:
  - Add `"settings"` (JSON object keyed by `SettingKey->value`, sorted lexicographically, containing all portable keys from `SettingsService::get()`).
  - In `validateArchive()`:
    - For `format_version < 3`, `"settings"` is ignored (`null` in normalized manifest).
    - For `format_version >= 3`, `"settings"` must be present and be a JSON object (non-list associative array).
    - Known non-portable keys (`storage.root_path`, `storage.folder_name`, `app.current_vault`) are rejected if present.
    - Unknown keys are ignored within the same format version (forward compatibility) and never passed to `SettingsService`.
    - Every present portable key is validated strictly against the same constraints as the Settings Form Requests (types, enums, integer/float bounds, UTF-8, max 4000 chars, no NUL bytes, valid YAML frontmatter via `MarkdownService::assertValidFrontmatterYaml()`, and non-empty template when `editor.new_note_template_enabled` is `true`).
- **Backup History Exclusion**: `BackupService::create()` never reads from the `backups` table into the archive or manifest; `BackupService::restore()` never writes to or reads from the `backups` table.
- **Restore & Atomicity**:
  - `BackupService::restore(string $path, array $actions, array $settingGroups = [])` accepts optional `list<SettingGroup>` (default `[]` for backward compatibility).
  - When restoring both vaults and settings, `SettingsService::setMany()` runs inside the same DB transaction as the vault/note/encryption commits, after filesystem moves succeed. Values equal to `$key->default()` are stored as `null` (`forget`) to keep `settings` sparse.
  - When all vaults are `Skip` and `$settingGroups !== []`, `restore()` performs a settings-only restore without touching the storage root or staging folders.
- **Live Appearance Sync**: `HandleInertiaRequests` shares `appearance`, and `AppLayout.vue` watches it via `syncAppearance()` so a restored theme applies immediately across Inertia navigation without an application restart.

## Consequences
- **Positive**:
  - Full migrations (backup → fresh install → restore) preserve user preferences across Editor, Security, Appearance, and General alongside vaults and notes.
  - Existing format 1 and format 2 archives remain 100% inspectable and restorable.
  - Machine-local paths (`storage.*`), current workspace state (`app.current_vault`), vault encryption secrets, and backup history (`backups`) never leak into archives or overwrite target installations.
  - Users can resolve conflicts cleanly by selecting only specific setting groups or restoring settings without duplicating already-registered vaults.
- **Negative / trade-offs**:
  - Format 3 archives cannot be opened by older format-1/2 builds (rejected cleanly as "made by a newer version").
  - Existing tests asserting `format_version === 2` on newly created backups must be updated to `3`.