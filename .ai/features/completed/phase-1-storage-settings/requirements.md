# Requirements: Phase 1 — Storage and Settings

## Metadata
- **Feature Name**: Phase 1 — Storage and Settings
- **Feature ID**: mdv-p1
- **Master Plan Phase**: **Phase 1 — Storage and Settings (`docs/Masterplan.md` §50; also §8, §10, §35, §40–43, §60–62)**
- **Author**: System Analyst
- **Created Date**: 2026-09-27
- **Task Complexity**: Level 3 — Complex Development
- **Status**: UNDER REVIEW (approvals B1–B4 in `plan.md` §6)

---

## 1. Problem Statement
Phase 0 delivered the desktop shell, a tested service layer and a single client-side Appearance page. Theme lives in `localStorage` plus an `appearance` cookie. Nothing is persisted in SQLite, and there is no concept of where MDVault stores user data.

Phase 2 (Vault Management) needs two things first:
1. A persisted, central settings store (`SettingsService`, §10). It is the only way the app may read or write configuration.
2. A platform-appropriate storage root that the user can change (`StoragePathService`, §8). New vaults will be created under it.

The Master Plan's Phase 1 acceptance criteria require that the user can:
- view settings;
- change the storage root;
- change the theme;
- have settings persist after an application restart;
- get a platform-appropriate default storage location.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- A `settings` table (§10 schema), an Eloquent `Setting` model, and a typed `SettingsService` with code-defined keys, types, groups and defaults.
- `StoragePathService`, which covers:
  - the default root (`<Documents>/MDVault`) with no hardcoded platform paths;
  - resolving the effective root;
  - validating and changing the root (create if missing, write-probe);
  - resetting to the default.
- A native folder-picker (desktop only, via NativePHP `Dialog`) behind `NativeDialogService`. A text input works in every runtime.
- Settings UI (§35): **General**, **Storage**, **Editor**, **Appearance**.
- Theme (`appearance.theme`: light/dark/system) persisted in SQLite and applied at first paint from the server.
- Editor preferences (`editor.font_size`, `editor.font_family`, `editor.line_height`, `editor.word_wrap`, `editor.show_line_numbers`) are stored. The first four are applied to the Workspace demo editor.
- A General preference `app.check_external_changes` (stored; consumed in Phase 5) and a read-only About panel.
- Carry-overs from Phase 0:
  - QA-01: a runtime config regression test.
  - QA-04 (partial): single-root `settings/Appearance.vue`.
- Architecture guardrails, extending `tests/Unit/ArchitectureTest.php`.

### Out of Scope (Non-Goals)
- Vaults, notes, the `vaults`/`notes`/`vault_encryption`/`backups` tables (Phase 2+).
- Moving or copying any data when the storage root changes. There is nothing to move yet. See ADR `storage-root-resolution`.
- `appearance.accent_color`, `appearance.logo`, `appearance.favicon`, sidebar toggles (B4).
- Backup and Security settings sections (B3), which arrive with Phases 6 and 7.
- `backup.default_format` (Phase 6).
- Line numbers in Tiptap (Phase 4 decides); the value is stored only.
- Watching for external changes (Phase 5). Only the preference is stored.
- Migrating the old browser `localStorage`/cookie theme value (pre-release; B2).
- "Reveal in Explorer/Finder" and other shell integrations.
- QA-02 (vite fmt ignore patterns) and QA-03 (`boost.json`). These are handled separately.

---

## 3. User Personas & Stories
- **As a** local MDVault user, **I want to** see my settings grouped into General, Storage, Editor and Appearance, **so that** I can find and change preferences easily.
- **As a** user, **I want** MDVault to propose `Documents/MDVault` on my OS by default, **so that** my notes land somewhere sensible without setup.
- **As a** user, **I want to** choose a different storage folder, either by picking it in a native dialog or by typing a path, **so that** my vaults live where I want (for example, a synced or backed-up drive).
- **As a** user, **I want** invalid or unwritable folders rejected with a clear message, **so that** I don't configure a location that will fail later.
- **As a** user, **I want to** switch between light, dark and system themes, and have my choice survive restarts, **so that** the app always looks the way I chose.
- **As a** writer, **I want to** set editor font size, family, line height and wrapping, **so that** editing is comfortable.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Settings store | A `settings` table (`id`, `key` unique, `value` nullable text, `type`, `group`, timestamps). `SettingsService` is the only reader and writer. Keys are a closed, code-defined set (`SettingKey` enum) with a type, group and default. Unset keys return their default. Writes validate the value type. | Given an empty table, When `get(SettingKey::EditorFontSize)` is called, Then `16` is returned. When `set(...)` is given a value of the wrong type, Then an `InvalidArgumentException` is thrown and nothing is written. When a value is set, Then exactly one row exists for that key with the right `type` and `group`. |
| **FR-02** | Persistence across restart | Settings are stored in SQLite and read back by a fresh service instance or process. | Given a value was saved, When a new `SettingsService` instance (a new request or app process) reads it, Then the saved value is returned. Manual: restart the desktop app and the theme and storage root are unchanged. |
| **FR-03** | Settings UI | `/settings` redirects to General. The settings layout nav lists General, Storage, Editor, Appearance. Each page shows its current values. | Given any runtime, When the user opens `/settings`, Then they are redirected to `/settings/general`. Each of the four pages renders with its props. |
| **FR-04** | Default storage location | The default root is `<user Documents>/MDVault`: native Documents under NativePHP; `USERPROFILE`/`HOME` + `Documents` in browser dev; `storage/app/MDVault` as a last resort. It is not persisted, and it is not created just by viewing it. | Given NativePHP supplies a documents path `X`, Then the default is `X/MDVault`. Given no native path and `USERPROFILE=C:\Users\a` on Windows, Then the default is `C:\Users\a\Documents\MDVault`. Given `HOME=/home/a` on Linux or macOS, Then it is `/home/a/Documents/MDVault`. No `C:\` literal appears in application code. |
| **FR-05** | Change storage root (text input) | The user submits an absolute path. The service normalises it, rejects relative paths and existing files, creates the directory (recursively) if missing, probes writability, and persists the canonical path. | Given a non-existent absolute path under a writable parent, When submitted, Then the directory exists, `storage.root_path` equals its canonical path, and the page shows it. Given `relative/dir`, an existing file, or an uncreatable path, Then a validation error appears on `root_path` and the setting is unchanged. |
| **FR-06** | Choose storage root (native dialog) | In the desktop runtime a "Choose folder…" button opens the OS folder picker and applies the chosen folder, using the same validation as FR-05. The endpoint does not exist in the browser runtime. | Given desktop runtime and the user picks folder `F`, Then the root becomes `F`. Given the user cancels, Then nothing changes. Given the browser runtime, When `POST /settings/storage/browse` is called, Then the response is 404 and the button is hidden. |
| **FR-07** | Reset storage root | "Use default location" forgets the stored value. | Given a custom root, When reset, Then no `storage.root_path` row exists and the effective root equals the default. |
| **FR-08** | Changing root never moves data | Changing or resetting the root never moves, copies or deletes files or directories. It only records the location where future vaults will be created. | Given a file in the old root, When the root changes, Then the old file and directory are untouched. |
| **FR-09** | Theme persisted server-side | `appearance.theme` ∈ {light, dark, system}, default `system`. The server renders `data-appearance` and the `dark` class on `<html>` from the setting. Selecting a theme applies it immediately and persists it. The browser `localStorage`/cookie is no longer the source. | Given theme `dark` is saved, When any page is requested, Then the HTML has `data-appearance="dark"` and `class="dark"`. When `PATCH` has theme `blue`, Then it is rejected. |
| **FR-10** | Editor preferences | Stored: font size (int 12–24, default 16), font family (sans\|serif\|mono, default sans), line height (1.2–2.2, default 1.6), word wrap (bool, default true), show line numbers (bool, default false). The Workspace passes them to `TiptapEditor`, which applies size, family, line height and wrap. | Given valid input, When saved, Then values persist and the Workspace `editor` prop reflects them. Invalid values (for example size 40, family `comic`) produce validation errors. |
| **FR-11** | General settings | `app.check_external_changes` (bool, default true) is editable and stored. The General page shows About info (app name, version, runtime, database driver) from `SystemStatusService`. | Given the toggle is changed and saved, Then the value persists. The page props include `status`. |
| **FR-12** | Runtime config regression (QA-01) | Pest assertions guard the Phase 0 runtime baseline. | `nativephp.app_id === 'com.mdvault.app'`, `nativephp.version === '0.1.0'`, `nativephp.updater.enabled === false`, `inertia.ssr.enabled === false`. |
| **FR-13** | Architecture guardrails | Only `SettingsService` uses `App\Models\Setting`. Only `NativeDialogService` uses `Native\Desktop\Dialog`. `App\Enums` contains only enums. Existing Phase 0 rules still pass. | `tests/Unit/ArchitectureTest.php` passes with the new rules and no unjustified `ignoring()`. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - No authentication (ADR `local-app-without-authentication`).
  - Paths are validated server-side only; Vue does no path manipulation (§42).
  - The write probe uses `Str::random()` (the security preset forbids `uniqid`/`mt_rand`) and deletes its probe file.
  - The native dialog runs only when `nativephp-internal.running` is true.
- **Performance**:
  - One `select` of all settings rows per request (memoised in a request-scoped service).
  - No cross-request cache.
  - The storage summary does only `is_dir`/`is_writable` checks. The probe write happens only on change.
- **Accessibility & UX**:
  - Every Vue component has a single root element (fixes QA-04 for `Appearance.vue`).
  - Form controls have `<Label for>` bindings.
  - Validation errors use `InputError`. Success uses the existing flash toast (`Inertia::flash('toast', …)`).
  - The theme applies instantly with no flash of the wrong theme on load.
- **Reliability & Data Integrity**:
  - Filesystem and DB steps are explicit (§43): the directory is created and probed before the setting is written. A failed DB write can leave only an empty directory, which is harmless.
  - `setMany` runs in a DB transaction.
  - Corrupt or foreign stored values fall back to defaults.
  - A missing `settings` table (for example before migrations on first native boot) degrades to defaults instead of a 500 on read.
  - Tests never touch the real Documents folder: they bind a fake `UserDirectories` to a temp directory.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4); `nativephp/desktop` 2.3.x
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4 + shadcn-vue (existing `components/ui/*`: input, label, select, checkbox, button, card)
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`), regenerated with `--with-form`
- Testing: Pest 5 (`pest-plugin-laravel`; arch presets `php` + `security`)
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`); PHPStan level 7
- Database: SQLite. Browser dev uses `database/database.sqlite`, native dev `database/nativephp.sqlite`, tests `:memory:`.
- Inertia `testing.ensure_pages_exist = true`: every rendered page component must exist before its test passes.
- ADRs: `service-layer-architecture`, `desktop-runtime-baseline`, `local-app-without-authentication`, plus the new `settings-persistence`, `server-side-theme-persistence`, `storage-root-resolution`.

---

## 7. Risks & Assumptions
- **Assumption**: NativePHP always provides `NATIVEPHP_DOCUMENTS_PATH` when running natively (verified in `electron-plugin/src/server/php.ts`). This path already handles OneDrive redirection and XDG Documents.
- **Assumption**: In browser dev (Herd on Windows), PHP's environment exposes `USERPROFILE`. If not, the default falls back to `storage/app/MDVault` and the UI still shows it.
- **Risk**: The browser fallback `…\Documents` can differ from a OneDrive-redirected Documents folder. **Mitigation**: dev-only; the native runtime uses Electron's real path, and the user can change the root.
- **Risk**: The native dialog request blocks until the user picks a folder. **Mitigation**: the NativePHP client timeout is 1h; the PHP built-in server has no execution limit; the UI shows a busy state. Manual verification is required.
- **Risk**: `is_writable()` is unreliable on Windows ACLs. **Mitigation**: a real write probe on change.
- **Risk**: Browser and native dev use different SQLite files, so settings differ between them. This is expected (ADR `desktop-runtime-baseline`).

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified (relative path, file-as-root, uncreatable, unwritable, cancel dialog, missing table, corrupt value, browser vs desktop)
- [ ] Approved to proceed to Planning (`plan.md`) — pending user answers B1–B4
