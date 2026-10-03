# ADR: Settings persistence (`settings` table and `SettingsService`)

- **Status**: Proposed (Phase 1 plan, pending user approval B1)
- **Date**: 2026-09-27
- **Phase**: Master Plan Phase 1 — Storage and Settings (§10, §50; acceptance: "Settings persist after application restart")

## Context
Master Plan §10 prescribes a key/value `settings` table (`id, key, value, type, group, timestamps`) accessed only through `SettingsService`, with no scattered queries. It lists example keys across the groups general, storage, editor, appearance, backup and security.

Phase 1 needs: storage root, theme, editor preferences and one general flag. Later phases add backup and security keys.

The app runs as:
- one local user;
- short-lived PHP requests (NativePHP's PHP server / Herd);
- optionally NativePHP queue workers (long-lived).

PHPStan runs at level 7. Settings must survive restarts and must never make the app unusable if the table is unavailable.

## Options Considered
1. **Free-form string keys, JSON-encoded values, defaults seeded by migration.** Simple, but typos create silent new keys. Defaults are duplicated in the DB and changing a default needs a data migration. Values are untyped for PHPStan.
2. **One wide `settings` row / JSON document.** Contradicts §10 and makes per-key groups and types meaningless.
3. **A code-defined key registry (enum) with typed string storage, sparse rows and code defaults (chosen).**
4. **Plus a cross-request cache (Laravel cache store).** Adds invalidation paths (native vs browser DB, workers) to save one indexed query on a table of about 10 rows. Rejected.

## Decision
- **Schema**: `settings(id, key string(100) unique, value text null, type string(20), group string(30) indexed, timestamps)`. No UUID: settings are local configuration, not portable entities (§12 applies to vaults and notes).
- **Registry**: `App\Enums\SettingKey` (string-backed; the value is the dotted key, e.g. `editor.font_size`). Each case defines `type()` (`SettingType`: string, integer, float, boolean, json), `group()` (`SettingGroup`) and `default()`. Only keys a phase actually uses are added; later phases add cases. There are no arbitrary keys.
- **Storage format**: values are serialised to strings by type (`'1'/'0'`, numeric strings, raw strings, JSON for arrays), so rows stay human-readable. `type` and `group` are denormalised onto the row for introspection and future export/backup.
- **Sparse rows**: a row exists only once a user changes a setting. Unset keys resolve to the enum default. `set(key, null)` equals `forget(key)` (revert to default). Migrations never seed settings.
- **API**: `get`, typed `string`/`integer`/`float`/`boolean`, `set`, atomic `setMany` (validate all, then one transaction), `forget`, `has`, and `group(SettingGroup)` → `field => value` with defaults merged.
- **Type safety**:
  - Writes reject wrong types with `InvalidArgumentException`; this is a programmer error, and user input is validated earlier by Form Requests.
  - Reads never throw. A mismatched `type`, an uncastable value or a null value returns the default.
- **Caching**: the service memoises all rows once per instance. It is registered as `scoped` in `AppServiceProvider` (fresh per request and per queue job) and invalidates its memo on every write. No cross-request cache.
- **Failure mode**: reads catch `QueryException` (e.g. table missing before first-boot migrations), `report()` it, and fall back to defaults. Writes propagate exceptions.
- **Enforcement**: Pest arch rule: `App\Models\Setting` may only be used in `App\Services\SettingsService`.

## Consequences
- **Positive**:
  - A single, typed, testable entry point.
  - Typos are impossible (enum).
  - Defaults change in code without migrations.
  - The DB contains only real user choices, which is ideal for future backup/export.
  - Robust against a missing or corrupt table.
- **Negative / trade-offs**:
  - Adding a setting means editing the enum (intended).
  - Validation ranges live in Form Requests, not the registry. The two must be kept consistent per key.
  - `json` type is defined but unused in Phase 1.
- **Follow-ups**:
  - Phase 5 consumes `app.check_external_changes`.
  - Phase 5: `app.check_external_changes` enables only the automatic focus/interval checks; note-open verification, the save guard and manual Re-index always run.
  - Phase 6 adds no `backup.*` keys (H3, H7); settings are not included in backup format 1. Phase 7 adds `security.*`.
  - If settings export/backup is added, it should read through `SettingsService`.
- Phase 4 Revision 4 adds `editor.new_note_template_enabled` (boolean, default true) and `editor.new_note_template` (string, default in code). An empty template is refused while enabled, because `null` means "revert to default".

## Amendment (Phase 7, 2026-10-03)
- Follow-up: Phase 7 adds `security.auto_lock_minutes` and `security.lock_on_screen_lock`.
