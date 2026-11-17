# Plan: Include Application Settings in Backups

## Metadata
- **Feature Name**: Include Application Settings in Backups
- **Feature ID**: `backup-include-settings`
- **Author**: System Analyst
- **Created Date**: 2026-11-17
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Extend MDVault backups to `format_version = 3` so every backup (`all` and `vault` scope) serializes all 13 portable settings (`appearance`, `editor`, `security`, and `app.check_external_changes`) into `manifest.json`, while strictly excluding `storage.*`, `app.current_vault`, and the `backups` table (backup history). During inspection and restore, `BackupService` validates untrusted settings against Form Request constraints, detects differences from current settings, defaults to opt-in unless the installation is unconfigured, and supports per-group and settings-only atomic restores. Finally, `HandleInertiaRequests` and `AppLayout.vue` synchronize restored `appearance.theme` live, and `Backup.vue` / `RestoreBackupDialog.vue` present the new capabilities clearly.

---

## 2. Architecture & Design
- **Approach**: Store portable settings in `manifest.json` under `"settings"` (`SettingKey->value => effectiveValue`) via `SettingsService`, keeping all `Setting` access inside `SettingsService` and using `BackupService::validateArchive()` for unified validation across `create()`, `inspect()`, and `restore()`. Support `format_version` `1`, `2`, and `3` for full backward compatibility.
- **Alternatives Considered**:
  - Separate `settings.json` entry inside the ZIP — rejected because it adds Stage 7 file-accounting complexity without security or size benefit over `manifest.json`.
  - Including `storage.*` or `app.current_vault` — rejected because `storage.root_path` embeds absolute paths violating archive portability and `app.current_vault` is transient local workspace state.
  - All-or-nothing settings restore toggle — rejected in favor of per-group checkboxes (`appearance`, `editor`, `general`, `security`) so users can resolve group conflicts selectively.
- **Decision Records**: [`.ai/decisions/backup-settings-format.md`](.ai/decisions/backup-settings-format.md)

### Data Model Changes
None (`backups.format_version` and `settings` tables already support this design without migrations).

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Enum | `app/Enums/SettingKey.php` | Add `isPortable()`, `portableCases()`, `portableGroups()` |
| Enum | `app/Enums/SettingGroup.php` | Add `label()` and `isPortable()` |
| Exception | `app/Exceptions/BackupException.php` | Add `noSettingsInBackup()` factory (field `'setting_groups'`) |
| Support DTO | `app/Support/BackupInspection.php` | Document `settings` summary structure inside `$backup` |
| Support DTO | `app/Support/RestoreResult.php` | Add `settingsRestored` and `restoredSettingGroups` properties and update `summary()` |
| Service | `app/Services/BackupService.php` | Export settings in `create()`, validate `"settings"` in `validateArchive()`, summarize diffs/defaults in `inspect()`, apply selected groups & settings-only restore atomically in `restore()` |
| Form Request | `app/Http/Requests/Settings/RestoreBackupRequest.php` | Validate optional `setting_groups`, permit all-skip vaults when `setting_groups` is non-empty, provide `settingGroups()` |
| Controller | `app/Http/Controllers/Settings/BackupController.php` | Update backup success toast to note settings inclusion |
| Controller | `app/Http/Controllers/Settings/BackupRestoreController.php` | Pass `$request->settingGroups()` to `BackupService::restore()`; redirect `back()` for settings-only restore |
| Middleware | `app/Http/Middleware/HandleInertiaRequests.php` | Share `'appearance'` prop lazily on Inertia responses |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types | `resources/js/types/backups.ts` | Add `BackupSettingsGroupSummary`, `BackupInspectionSettings`, and `backup.settings` field |
| Types | `resources/js/types/global.d.ts` | Add `appearance: Appearance` to `InertiaConfig.sharedPageProps` |
| Composable | `resources/js/composables/useAppearance.ts` | Export `syncAppearance(value: Appearance)` helper |
| Layout | `resources/js/layouts/AppLayout.vue` | Watch `page.props.appearance` and call `syncAppearance()` |
| Page | `resources/js/pages/settings/Backup.vue` | Clarify UI copy that portable settings are included and storage paths / backup history are excluded |
| Component | `resources/js/components/backups/RestoreBackupDialog.vue` | Render per-group settings checkboxes, conflict counts, and settings-only restore validation/submission |

### Routes
No route changes required (`settings.backup.*` and `settings.backup.restore.*` already handle all actions).

---

## 3. Implementation Tasks

- [ ] **T1 — Classify portable settings and group labels on enums**
  - Files: `app/Enums/SettingKey.php`, `app/Enums/SettingGroup.php`
  - Details:
    - On `SettingGroup`: add `label(): string` (exhaustive `match ($this)` returning `'General'`, `'Storage'`, `'Editor'`, `'Appearance'`, `'Backup'`, `'Security'`) and `isPortable(): bool` (`true` for `Appearance`, `Editor`, `General`, `Security`; `false` for `Storage`, `Backup`).
    - On `SettingKey`: add `isPortable(): bool` with exhaustive `match ($this)`: `false` for `StorageRootPath`, `StorageFolderName`, `CurrentVault`; `true` for the other 13 cases (`AppearanceTheme`, 9 `Editor*`, `CheckExternalChanges`, `SecurityAutoLockMinutes`, `SecurityLockOnScreenLock`).
    - Add `public static function portableCases(): array` returning `list<self>` filtered by `isPortable()`, and `public static function portableGroups(): array` returning `list<SettingGroup>` (`[SettingGroup::Appearance, SettingGroup::Editor, SettingGroup::General, SettingGroup::Security]`) and `portableGroupValues(): array` returning `list<string>` of their backed values.
  - Covers: FR-01

- [ ] **T2 — Extend BackupException, BackupInspection, and RestoreResult DTOs**
  - Files: `app/Exceptions/BackupException.php`, `app/Support/BackupInspection.php`, `app/Support/RestoreResult.php`
  - Details:
    - In `BackupException`: add `public static function noSettingsInBackup(): self` returning `new self("This backup doesn't include settings.", 'setting_groups')`.
    - In `BackupInspection`: update PHPDoc on `$backup` to include `'settings' => ?array{groups: list<array{group: string, label: string, count: int, differing: int}>, total_count: int, differing_count: int, default_restore: bool}` (keeping top-level `toArray()` keys strictly `['valid', 'problems', 'backup', 'vaults']`).
    - In `RestoreResult`: add optional trailing constructor properties `public bool $settingsRestored = false` and `/** @var list<string> */ public array $restoredSettingGroups = []` (backward compatible with existing 3-arg callers). Update `summary()`:
      - Vaults + settings: `"Restored {$count} vault(s) ({$notes} note(s)) and settings."`
      - Vaults only: `"Restored {$count} vault(s) ({$notes} note(s))."` (unchanged)
      - Settings only (`$count === 0 && $this->settingsRestored`): `"Restored settings."`
      - Append any `$warnings` as before.
  - Covers: FR-05, FR-06, FR-07

- [ ] **T3 — Implement settings backup, validation, inspection, and restore in BackupService**
  - Files: `app/Services/BackupService.php`, `tests/Feature/Backups/BackupCreateTest.php`, `tests/Feature/Backups/EncryptedBackupTest.php`
  - Details:
    - Bump `public const FORMAT_VERSION = 3;`.
    - Inject `private readonly SettingsService $settings` and `private readonly MarkdownService $markdown` in `__construct(...)` **before** `$aggregateRatioAllowanceBytes = self::AGGREGATE_RATIO_ALLOWANCE_BYTES` (preserving the container test seam).
    - **Create (`create()`)**:
      - Build `$manifest['settings'] = $this->exportPortableSettings()` (all `SettingKey::portableCases()` sorted by `$key->value`, mapped to `$this->settings->get($key)`). Never read `storage.*`, `app.current_vault`, or `Backup` model rows.
      - Encode `$manifest` with `JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`.
    - **Validate (`validateArchive()`)**:
      - Accept `format_version` `1..self::FORMAT_VERSION` (`3`).
      - Add `normalizeAndValidateSettings(array $manifest, int $formatVersion, array &$problems): ?array`:
        - If `$formatVersion < 3`, return `null` (ignore `"settings"` on format 1/2 for backward compatibility).
        - If `$formatVersion >= 3`: require `array_key_exists('settings', $manifest)` and `is_array($raw = $manifest['settings'])` and `($raw === [] || ! array_is_list($raw))`; otherwise append problem and return `null`.
        - Iterate `$raw` (`$rawKey => $value`):
          - `$key = is_string($rawKey) ? SettingKey::tryFrom($rawKey) : null;`
          - If `$key === null`: ignore unknown key (forward compatibility).
          - If `! $key->isPortable()`: append problem `"The backup contains machine-specific setting \u{201c}{$rawKey}\u{201d}, which isn't allowed in a backup."` and continue.
          - Validate `$value` strictly per `$key`:
            - `AppearanceTheme`: `is_string($value) && Theme::tryFrom($value) !== null`
            - `EditorFontFamily`: `is_string($value) && EditorFontFamily::tryFrom($value) !== null`
            - `EditorDefaultView`: `is_string($value) && EditorDefaultView::tryFrom($value) !== null`
            - `EditorFontSize`: `is_int($value) && $value >= 12 && $value <= 24`
            - `EditorIndentSize`: `is_int($value) && $value >= 1 && $value <= 8`
            - `EditorLineHeight`: `(is_int($value) || is_float($value)) && is_finite((float) $value) && (float) $value >= 1.2 && (float) $value <= 2.2` -> normalize to `(float) $value`
            - `SecurityAutoLockMinutes`: `is_int($value) && in_array($value, UpdateSecuritySettingsRequest::AUTO_LOCK_CHOICES, true)`
            - Boolean keys (`EditorWordWrap`, `EditorShowLineNumbers`, `EditorNewNoteTemplateEnabled`, `CheckExternalChanges`, `SecurityLockOnScreenLock`): `is_bool($value)`
            - `EditorNewNoteTemplate`: `is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= 4000 && ! str_contains($value, "\0")`, check `$this->markdown->assertValidFrontmatterYaml(str_replace("\r\n", "\n", $value))` (catching `NoteOperationException` into `$problems`), normalize with `rtrim(str_replace("\r\n", "\n", $value), "\n")`.
        - Cross-field check: determine effective template enabled (`$normalized['editor.new_note_template_enabled'] ?? true`); if `true` and `array_key_exists('editor.new_note_template', $normalized)` and `trim((string) $normalized['editor.new_note_template']) === ''`, append problem.
        - Return `$normalized === [] ? null : $normalized`, and include `'settings' => $normalizedSettings` in the normalized manifest returned by `validateArchive()`.
    - **Inspect (`inspect()`)**:
      - Compute `settings` metadata from `$manifest['settings']`:
        - If `null`, set `$backup['settings'] = null`.
        - Otherwise group normalized settings by `SettingGroup` (in deterministic order: `Appearance`, `Editor`, `General`, `Security`, skipping groups with 0 keys in the manifest), computing `count` and `differing` (comparing against `$this->settings->get($key)` using `abs((float) $a - (float) $b) > 1e-9` for `SettingType::Float`, `!==` otherwise).
        - Compute `default_restore`: `true` iff `Vault::query()->doesntExist()` AND every `SettingKey::portableCases()` currently equals `$key->default()` (using float epsilon check for `Float`).
    - **Restore (`restore(string $path, array $actions, array $settingGroups = [])`)**:
      - Accept `/** @param list<SettingGroup> $settingGroups */` defaulting to `[]`.
      - Normalize/deduplicate `$settingGroups` to portable groups only (`$group->isPortable()`).
      - Validate `$archive` (`verifyHashes: false`).
      - Validate requested `$settingGroups`: if non-empty and `$manifest['settings'] === null`, throw `BackupException::noSettingsInBackup()`. Build `$settingsToRestore = []` for keys in `$manifest['settings']` whose `$key->group()` is in `$settingGroups`; if `$settingGroups !== [] && $settingsToRestore === []`, throw `BackupException::noSettingsInBackup()`.
      - Prepare vault plan (`$plan`); if `$plan === [] && $settingsToRestore === []`, throw `BackupException::nothingSelected()`.
      - Map each entry in `$settingsToRestore` to `null` when equal to `$key->default()` (epsilon check for `Float`) so `SettingsService::setMany()` maintains sparse storage.
      - **Settings-only branch (`$plan === []`)**: Do NOT call `ensureRootReady()`, `staleStagingDirectories()`, `freeSpace()`, `stagePlan()`, or `vaults->open()`. Execute `$this->settings->setMany($sparseSettings)` inside `$this->database->connection()->transaction(...)` and return `new RestoreResult([], $skippedNames, [], true, $restoredGroupValues)`.
      - **Vaults (+ optional settings) branch (`$plan !== []`)**: Run existing root, staging, and vault transaction steps. Inside the same `$this->database->connection()->transaction(...)` closure, after all vault DB rows and directory moves succeed, call `$this->settings->setMany($sparseSettings)` when `$sparseSettings !== []`. Return `RestoreResult` with `settingsRestored` and `restoredSettingGroups`.
    - Update the 3 existing `toBe(2)` `format_version` assertions in `tests/Feature/Backups/BackupCreateTest.php` (line 89) and `tests/Feature/Backups/EncryptedBackupTest.php` (lines 155, 237) to `toBe(3)`.
  - Covers: FR-02, FR-03, FR-04, FR-05, FR-06, FR-07, FR-08

- [ ] **T4 — Update RestoreBackupRequest, BackupController, and BackupRestoreController**
  - Files: `app/Http/Requests/Settings/RestoreBackupRequest.php`, `app/Http/Controllers/Settings/BackupController.php`, `app/Http/Controllers/Settings/BackupRestoreController.php`
  - Details:
    - In `RestoreBackupRequest`:
      - Add rules: `'setting_groups' => ['sometimes', 'array']`, `'setting_groups.*' => ['required', 'string', 'distinct', Rule::in(SettingKey::portableGroupValues())]`.
      - Update `withValidator()`: skip if `$validator->errors()->isNotEmpty()`; fetch `$settingGroups = $this->input('setting_groups', [])`; only add error on `'vaults'` (`'Choose at least one vault or setting group to restore.'`) when `$everySkipped && (! is_array($settingGroups) || $settingGroups === [])`.
      - Add `/** @return list<SettingGroup> */ public function settingGroups(): array` mapping `$this->validated('setting_groups', [])` via `SettingGroup::from(...)`.
    - In `BackupRestoreController::store()`:
      - Pass `$request->settingGroups()` as 3rd argument to `$backups->restore(...)`.
      - Redirect to `to_route('vaults.index')` when `$result->restored !== []`, or `to_route('settings.backup.edit')` when settings-only (`$result->restored === [] && $result->settingsRestored`).
    - In `BackupController::store()`:
      - Update success toast copy to `"Backup saved to {$result->path}. {$result->vaultCount} vault(s), {$result->noteCount} note(s), and settings."` (preserving skipped-vaults and not-recorded suffixes).
  - Covers: FR-07, FR-09

- [ ] **T5 — Share appearance via Inertia and synchronize theme live on client**
  - Files: `app/Http/Middleware/HandleInertiaRequests.php`, `resources/js/types/global.d.ts`, `resources/js/composables/useAppearance.ts`, `resources/js/layouts/AppLayout.vue`
  - Details:
    - In `HandleInertiaRequests::share()`: add `'appearance' => fn () => (Theme::tryFrom((string) $this->settings->string(SettingKey::AppearanceTheme)) ?? Theme::System)->value`.
    - In `resources/js/types/global.d.ts`: import `Appearance` from `@/types/ui` and add `appearance: Appearance;` to `InertiaConfig['sharedPageProps']`.
    - In `resources/js/composables/useAppearance.ts`: export `syncAppearance(value: Appearance): void` to update `appearance.value`, `document.documentElement.dataset.appearance`, and call `updateTheme(value)`.
    - In `resources/js/layouts/AppLayout.vue`: import `usePage`, `watch`, and `syncAppearance`; watch `() => page.props.appearance` (with `{ immediate: true }` or on change) to apply restored themes immediately after Inertia redirects.
  - Covers: FR-09

- [ ] **T6 — Update Backup/Restore frontend types and UI**
  - Files: `resources/js/types/backups.ts`, `resources/js/pages/settings/Backup.vue`, `resources/js/components/backups/RestoreBackupDialog.vue`
  - Details:
    - In `resources/js/types/backups.ts`: add `BackupSettingsGroupSummary`, `BackupInspectionSettings`, and `settings: BackupInspectionSettings | null` on `BackupInspection['backup']`.
    - In `resources/js/pages/settings/Backup.vue`: update CardDescription and destination info box copy to explain that backups include vaults plus portable preferences (Editor, Security, Appearance, General) and exclude storage paths, backup history, hidden `.git` folders, and logs.
    - In `resources/js/components/backups/RestoreBackupDialog.vue`:
      - Track reactive `selectedGroups: Record<string, boolean>` initialized on inspection success from `response.backup?.settings?.groups` using `response.backup.settings.default_restore`.
      - Update `canRestore` to `Object.values(actions).some((a) => a !== 'skip') || selectedSettingGroups.value.length > 0`.
      - Include `setting_groups: selectedSettingGroups.value` in `form` submission, and display `<InputError :message="form.errors.setting_groups" />`.
      - Render an "Application Settings" card when `state.inspection.backup?.settings` is non-null: show each group checkbox (`Appearance`, `Editor`, `General`, `Security`) with `:model-value`/`@update:model-value`, `X differ` vs `Matches current` indicator, a conflict warning when any checked group has `differing > 0`, and a note that storage paths and backup history are never overwritten.
      - When `state.inspection.backup?.settings === null`, show a subtle muted note that this older backup format does not include application settings.
  - Covers: FR-05, FR-06, FR-07, FR-09

- [ ] **T7 — Add comprehensive Pest test suite for settings in backups**
  - Files: `tests/Feature/Backups/BackupSettingsTest.php`
  - Command: `php artisan make:test --pest Backups/BackupSettingsTest --no-interaction`
  - Details: Write tests covering:
    1. Enum classification (`SettingKey::isPortable()`, `SettingGroup::isPortable()`, `label()`).
    2. Full backup and single-vault backup (`scope: 'all'` and `scope: 'vault'`) both include all 13 portable settings (`appearance`, `editor`, `security`, `app.check_external_changes`), preserve effective/default values and float values (`2.0`), and exclude `storage.root_path`, `storage.folder_name`, `app.current_vault`, and all `backups` table rows.
    3. Backward compatibility: format-1 and format-2 archives without `"settings"` inspect with `backup.settings === null` and restore vaults cleanly; requesting `setting_groups` against a format-1/2 archive fails cleanly.
    4. Validation failures in `inspect()` and `restore()`: non-object `settings`, injected `storage.root_path`/`storage.folder_name`/`app.current_vault`, invalid theme/font family/default view/auto-lock choice, out-of-range `font_size`/`indent_size`/`line_height`, wrong JSON types (`"true"` string for boolean), NUL byte / `> 4000` chars / bare `---` in `new_note_template`, empty template when enabled, empty template allowed when disabled, unknown future keys ignored safely.
    5. Inspection diffs & smart defaults: `differing` counts per group, `default_restore === true` on fresh unconfigured install, `default_restore === false` when vaults exist or any portable setting is non-default.
    6. Restore modes & conflicts: selective per-group restore updates only chosen groups; `storage.*`, `app.current_vault`, and `backups` rows are untouched; defaults restore sparsely (`SettingsService::has()` false when matching default); `new_note_template` CRLF/trailing-newline normalized.
    7. Settings-only restore (all vaults `Skip`, `setting_groups` non-empty): succeeds via service and HTTP (`POST settings/backup/restore` redirects back to `settings.backup.edit` with `"Restored settings."`), even when storage root does not exist on disk; all-skip + empty `setting_groups` fails validation; invalid group name (e.g. `storage`, `backup`) fails validation.
    8. Atomicity & rollback: DB or folder-rename failure during combined vault+settings restore leaves `settings` unchanged.
    9. Live Inertia `appearance` sharing: restoring `appearance.theme = 'dark'` updates shared `appearance` prop on Inertia responses.
    10. §55 full round trip with customized settings: backup -> `simulateFreshInstall()` -> inspect (`default_restore: true`) -> restore -> verify vaults, notes, and all 13 portable settings match while new `storage` root and empty `backups` history remain intact.
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-06, FR-07, FR-08, FR-09

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Backups/BackupSettingsTest.php` | Enum portability, manifest v3 export (all/single vault), exclusion of storage/current_vault/backup history, strict validation, v1/v2 backward compatibility, diffs & defaults, selective & settings-only restore, rollback atomicity, HTTP endpoints & live appearance prop, §55 settings round trip | FR-01 through FR-09 |
| `tests/Feature/Backups/BackupCreateTest.php` | Updated `format_version === 3`, archive creation & no absolute paths | FR-02, FR-03 |
| `tests/Feature/Backups/EncryptedBackupTest.php` | Updated `format_version === 3`, ciphertext-only backups with settings in manifest | FR-02, FR-03 |
| `tests/Feature/Backups/BackupInspectTest.php` | Archive validation pipeline & format 1 compatibility | FR-04, FR-05 |
| `tests/Feature/Backups/BackupRestoreTest.php` | Vault restore atomicity & rollback | FR-08 |
| `tests/Feature/Backups/BackupHttpTest.php` | HTTP inspect JSON top-level keys, restore validation & redirects | FR-05, FR-07 |
| `tests/Feature/Backups/BackupRoundTripTest.php` | End-to-end backup and restore | FR-02, FR-07 |
| `tests/Feature/Settings/*Test.php` | Existing General, Editor, Security, Appearance, Storage settings unaffected | FR-01, FR-09 |
| `tests/Unit/ArchTest.php` | Architecture rules (`Setting` only in `SettingsService`, no raw FS in `BackupService`) | NFR Security/Integrity |

**Test scope for QA**:
- `php artisan test --compact tests/Feature/Backups tests/Feature/Settings tests/Unit/ArchTest.php`
- `vendor/bin/phpstan analyse`
- `npm run types:check`

---

## 5. Risks & Mitigations
- **Risk**: `Setting` model accessed directly in `BackupService` would break Pest architecture rules (`tests/Unit/ArchTest.php`). — **Mitigation**: All settings reads/writes go strictly through `SettingsService`.
- **Risk**: Float representation differences in JSON (`2` vs `2.0` for `editor.line_height`) causing false diff counts or validation failures. — **Mitigation**: Serialize with `JSON_PRESERVE_ZERO_FRACTION`, validate `is_int($v) || is_float($v)`, and compare floats with `1e-9` epsilon.
- **Risk**: Injected `storage.root_path` or `app.current_vault` in a tampered manifest overwriting local filesystem configuration or active vault pointer. — **Mitigation**: Reject non-portable `SettingKey` entries in `validateArchive()` and filter restored keys strictly by `SettingKey::isPortable()`.
- **Risk**: Restored theme not appearing until app reload. — **Mitigation**: Share `appearance` in `HandleInertiaRequests` and sync reactively in `AppLayout.vue`.

---

## 6. Open Questions
None. All architectural decisions are resolved in `.ai/decisions/backup-settings-format.md`.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-17 | Initial plan | — |

---

## Final Summary
- **Feature**: `backup-include-settings`
- **Level**: Level 3 — Complex Development
- **Artifacts produced**:
  - `.ai/decisions/backup-settings-format.md`
  - `.ai/features/active/backup-include-settings/requirements.md`
  - `.ai/features/active/backup-include-settings/plan.md`
- **Open questions**: None (all edge cases resolved; plan status is `APPROVED`).
- **Recommended next step**: Save the three artifacts to their target paths verbatim, then delegate to `senior-developer` to implement `.ai/features/active/backup-include-settings/plan.md` (Revision 1).