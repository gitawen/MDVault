# QA Report: Phase 1 — Storage and Settings

## Metadata
- **Feature**: Phase 1 — Storage and Settings (mdv-p1)
- **Round**: 1
- **Reviewer**: Senior QA Engineer
- **Date**: 2026-09-27
- **Plan Revision Reviewed**: Revision 1 (APPROVED)
- **Verdict**: **PASS**

---

## 1. Test Execution Summary
All commands run directly by QA on branch `phase-1-storage-settings` (uncommitted changes, baseline HEAD 1d318b5):

| Command | Result |
|---|---|
| `php artisan test --compact` | `129 tests, 128 passed, 345 assertions, 1 skipped` — skip is the Windows-incompatible unwritable-directory case (`->skipOnWindows()` in `StoragePathServiceTest`), exactly as the plan specifies. Re-ran `ArchitectureTest.php` in isolation: 8/8 passed. |
| `php vendor/bin/phpstan analyse` (level 7) | 0 errors |
| `npm run types:check` | Clean |
| `npm run check` (`vp check`) | "All 42 files are correctly formatted", "no warnings or lint errors in 35 files" |
| `npm run build` | Succeeds; per-page chunks for Storage/Editor/General/Appearance present |
| `php artisan route:list --path=settings` | Exactly 11 routes, matching plan §2 verbatim |
| `php vendor/bin/pint --dirty --format agent` | Clean (invoked via `php vendor/bin/pint` per the Git Bash shebang quirk noted in implementation.md) |
| `rg -n "C:\\\\|/Users/|/home/" app` | No matches |
| `rg -n "localStorage|appearance=" resources/js` | No matches (unrelated `SIDEBAR_COOKIE_NAME` hit in `ui/sidebar/utils.ts` is out of scope — sidebar state, not theme) |

No commands were skipped from the plan's required test scope.

---

## 2. Requirements Coverage
Traced every FR to code and to a passing automated test. All 13 FRs are implemented and covered:

| FR | Status | Evidence |
|---|---|---|
| FR-01 Settings store | PASS | `settings` migration/model/enums; `SettingsServiceTest` (14 scenarios, 27 tests) |
| FR-02 Persistence across restart | PASS | `SettingsServiceTest` "persists for a fresh service instance" (`forgetScopedInstances`) |
| FR-03 Settings UI | PASS | `SettingsNavigationTest`, redirect test in `AppearanceSettingsTest` |
| FR-04 Default storage location | PASS | `SystemUserDirectoriesTest` (7 tests) + `StoragePathServiceTest` defaults; no hardcoded paths (grep clean) |
| FR-05 Change root (text input) | PASS | `StoragePathServiceTest` + `StorageSettingsTest` (relative/file/empty rejected, valid persisted) |
| FR-06 Native dialog | PASS | `NativeDialogServiceTest` (4 tests) + `StorageSettingsTest` browse tests (success/cancel/invalid/404) |
| FR-07 Reset storage root | PASS | `resetToDefault` tests in both service and feature tests |
| FR-08 Never moves data | PASS | Explicit test: old file untouched, new root has no leftovers |
| FR-09 Server-side theme | PASS | `HandleAppearance` + Blade `data-appearance`/`dark` class; `AppearanceSettingsTest` asserts raw HTML; `useAppearance.ts` optimistic + `onError` rollback confirmed by code read |
| FR-10 Editor preferences | PASS | `EditorSettingsTest` (defaults, valid patch, 8-case validation dataset); `WorkspaceTest` confirms `editor` prop |
| FR-11 General settings | PASS | `GeneralSettingsTest` + `SystemStatusService` `status` prop |
| FR-12 Runtime config regression | PASS | `RuntimeConfigTest` (4 strict `toBe` assertions) |
| FR-13 Architecture guardrails | PASS | `ArchitectureTest.php`: `Setting` only in `SettingsService`, `App\Enums` only enums, `Native\Desktop\Dialog` only in `NativeDialogService`; no `ignoring()` calls added |

---

## 3. Scrutiny Areas (per QA brief)

**No note content in SQLite / no hardcoded platform paths / settings only via `SettingsService` / filesystem logic only in services**: Confirmed by code read and the architecture arch tests. `StorageController`/other controllers make zero direct filesystem calls (`changeRoot` delegates to `StoragePathService`). `rg` for hardcoded paths in `app/` is clean. `UserDirectories`/`SystemUserDirectories` uses runtime `getenv()`, deliberately not config-cached (documented in `AppServiceProvider`).

**`StoragePathService` validation**: `normalize()`, `isAbsolute()`, `changeRoot()` reviewed line-by-line against ADR `storage-root-resolution` — matches exactly. Test coverage includes relative paths (`relative/dir`, `./x`, `..`), Windows drive-relative (`\no-drive`, `onlyOnWindows`), existing file, path-under-a-file (uncreatable), unwritable directory (`skipOnWindows`), empty/whitespace, trailing separator, and "choosing the default path forgets the setting." Path-traversal segments (e.g. `..` inside an absolute path) are not specially rejected, but this is correct for a single-user, unauthenticated local app choosing its own storage folder — there is no privilege boundary being crossed, and the Master Plan/ADR do not call for blocking such input. Filesystem-then-DB ordering (`changeRoot` creates + probes before `$settings->set`/`forget`) matches §43 and the ADR; a failed DB write can only leave an empty created directory, as documented in the service's PHPDoc.

**Theme**: `HandleAppearance` reads `SettingsService` (fail-soft `Theme::tryFrom(...) ?? Theme::System`) and shares to Blade; `app.blade.php` renders `data-appearance` and `dark` class server-side with no flash (inline script only handles `system` → OS preference, pre-paint). `useAppearance.ts` applies immediately, reads from `document.documentElement.dataset.appearance`, PATCHes via Wayfinder `update.url()`, and rolls back `appearance.value`/dataset/`updateTheme` in `onError`. No `localStorage`/cookie code remains anywhere in `resources/js` (grep confirmed); `bootstrap/app.php` no longer excepts `appearance` from cookie encryption.

**Form Request validation**: Every settings PATCH/POST endpoint (`UpdateAppearanceRequest`, `UpdateStorageRootRequest`, `UpdateEditorSettingsRequest`, `UpdateGeneralSettingsRequest`) has a Form Request with rules matching plan §2 exactly. `settings.storage.browse` returns 404 in browser-mode (`abort_unless($dialogs->isAvailable(), 404)`), verified by `StorageSettingsTest::'browse is not found outside the desktop runtime'`.

**Vue components presentation-only**: Every new/modified page (`Appearance`, `Storage`, `Editor`, `General`) has a single root `<div class="space-y-6">`. `Storage.vue` does no path manipulation — it only echoes server-provided strings. `TiptapEditor.vue` applies `preferences` purely via computed style/class, no logic beyond presentation.

---

## 4. Developer-Reported Deviations — Assessed

1. **`make:model`/`make:enum` files moved to correct paths by hand.** Verified: no stray files remain under `app/Setting.php` or `app/Enums/Enums/*`; all classes live at the plan's exact paths/namespaces (`App\Models\Setting`, `App\Enums\*`, `App\Contracts\UserDirectories`). No functional impact. **Accepted.**
2. **Raw `is_dir()` in `StoragePathService::changeRoot`, plus `@property` PHPDoc on `Setting`.** Reviewed both: the `is_dir()` call replaces a second `$this->files->isDirectory()` call that Larastan (correctly, given PHPStan's static analysis can't see through the intervening `makeDirectory()` mutation) flagged as `alwaysTrue`; behaviour is identical (same syscall). The `@property SettingType $type` / `@property SettingGroup $group` PHPDoc documents the real Eloquent cast type so Larastan can type-check `$row->type->value` in `SettingsService::load()` — this is accurate documentation, not a suppression, and is exercised by the passing round-trip tests. **Accepted**, no defect.
3. **General checkbox auto-saves on toggle.** Reviewed `General.vue`: uses `:model-value`/`@update:model-value` (avoiding an undefined v-model/listener merge) to save immediately, consistent with the existing `AppearanceTabs` instant-apply pattern. Does not violate any FR (the plan only specified "Saved via useForm PATCH," not a specific trigger). **Accepted**, no defect.

---

## 5. Issues Found
**None.** No Critical, High, Moderate, or Minor defects were found in code review or test execution.

One cosmetic, non-blocking observation already flagged by the developer in `implementation.md` §5: the "Choose folder…" button in `Storage.vue` disables during the native dialog request but does not show a spinner glyph. This is a UX nicety, not a functional defect, and is not being logged as an issue (no FR requires a loading indicator beyond a busy/disabled state, which is present).

---

## 6. Manual Desktop Checks (User-Verified, Not QA Defects)
Per plan T11 and the QA brief, these require `composer native:dev` and cannot be automated or verified by QA:
1. Settings shows General / Storage / Editor / Appearance in that order.
2. Storage shows the default `…\Documents\MDVault` (real Documents folder, including any OneDrive redirection).
3. "Choose folder…" opens the native picker; picking a folder updates the path; Cancel changes nothing.
4. Typing a relative path shows a validation error.
5. Switching theme to Dark, and setting editor font size 20 with wrap off, are reflected live in the Workspace editor.
6. Quit and relaunch: theme is dark at first paint (no flash); storage root and editor preferences unchanged.
7. "Use default location" restores the default.

---

## 7. Verdict
**PASS** — zero open Critical/High/Moderate/Minor issues. All 13 functional requirements are implemented, correctly traced to passing automated tests, and consistent with all three new ADRs and the existing `service-layer-architecture` ADR. All required verification commands (full test suite, PHPStan, types:check, build, check/lint, route:list, greps) pass cleanly on this machine.

Per `CLAUDE.md` §4, this feature folder is ready to move from `.ai/features/active/phase-1-storage-settings/` to `.ai/features/completed/phase-1-storage-settings/`. The user should still run `php artisan test --compact` themselves and perform the 7 manual desktop checks in §6 above (via `composer native:dev`) before final sign-off, since those require the native runtime and were not exercised by either the developer or QA in this session.

## Routing
- No issues to route to `senior-developer`.
- No issues to route to `system-analyst`.
- No escalation triggered (no issue reached, or is at risk of reaching, 3 fix attempts).
