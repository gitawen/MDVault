# Implementation: Phase 1 — Storage and Settings

## Metadata
- **Feature Name**: Phase 1 — Storage and Settings
- **Feature ID**: mdv-p1
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Runtime config regression test (QA-01) | DONE | `nativephp.updater.enabled` already resolved to real `false`; no config fix needed. |
| T2 — `settings` schema, model and enums | DONE | `make:model`/`make:enum` initially wrote to wrong paths (see Deviations); moved and rewritten by hand. |
| T3 — `SettingsService` | DONE | 14-case test suite (27 tests incl. datasets), arch rules added. |
| T4 — `UserDirectories` / `SystemUserDirectories` | DONE | Pure unit tests, no Laravel bootstrap. |
| T5 — `StoragePathService` / `InvalidStorageRootException` | DONE | Unwritable-directory test skipped on Windows per plan. |
| T6 — `NativeDialogService` | DONE | Arch rule added; `Dialog::new()` per call as specified. |
| T7 — Appearance slice (server-side theme) | DONE | Cookie/`localStorage` removed; single-root page. |
| T8 — Storage slice | DONE | Text input + native dialog browse + reset. |
| T9 — Editor slice and Workspace preferences | DONE | `TiptapEditor` applies size/family/line-height/wrap. |
| T10 — General slice, settings index and navigation | DONE | Nav order General, Storage, Editor, Appearance. |
| T11 — Quality gates and handover | DONE | All commands below are clean. |

---

## 2. Files Changed

### Created
| Path | Summary |
|---|---|
| `app/Models/Setting.php` | Fillable `key/value/type/group`; `casts()` to `SettingType`/`SettingGroup`; `@property` PHPDoc (see Deviations). |
| `app/Enums/SettingType.php` | `String\|Integer\|Float\|Boolean\|Json`; `accepts()`, `serialize()`, `cast()`. |
| `app/Enums/SettingGroup.php` | `General\|Storage\|Editor\|Appearance\|Backup\|Security`. |
| `app/Enums/SettingKey.php` | 8 Phase-1 keys; `type()`, `group()`, `default()`, `field()`. |
| `app/Enums/Theme.php` | `Light\|Dark\|System`. |
| `app/Enums/EditorFontFamily.php` | `Sans\|Serif\|Mono`. |
| `database/migrations/2026_09_26_172743_create_settings_table.php` | `settings` table per §10 schema. |
| `app/Services/SettingsService.php` | Typed get/set/setMany/forget/has/group; request-memoised; fail-soft reads. |
| `app/Contracts/UserDirectories.php` | `documentsPath(): ?string`. |
| `app/Support/SystemUserDirectories.php` | Native disk → Windows/Unix env → null; runtime `getenv()`. |
| `app/Services/StoragePathService.php` | Default/effective root, normalize/isAbsolute, changeRoot, resetToDefault, summary. |
| `app/Exceptions/InvalidStorageRootException.php` | Named constructors with user-facing messages. |
| `app/Services/NativeDialogService.php` | `isAvailable()`, `chooseDirectory()` via `Dialog::new()`. |
| `app/Http/Controllers/Settings/{Appearance,Storage,Editor,General}Controller.php` | Thin edit/update (+ Storage `browse`/`destroy`). |
| `app/Http/Requests/Settings/{UpdateAppearanceRequest,UpdateStorageRootRequest,UpdateEditorSettingsRequest,UpdateGeneralSettingsRequest}.php` | Validation per plan §2 table. |
| `resources/js/types/settings.ts` | `StorageSettings`, `EditorFontFamily`, `EditorPreferences`, `GeneralSettings`. |
| `resources/js/pages/settings/{Storage,Editor,General}.vue` | New settings pages, single root each. |
| `tests/Feature/RuntimeConfigTest.php` | QA-01 regression (4 strict assertions). |
| `tests/Feature/Services/SettingsServiceTest.php` | 14 scenarios / 27 tests. |
| `tests/Unit/Support/SystemUserDirectoriesTest.php` | 6 pure-unit scenarios / 7 tests. |
| `tests/Feature/Services/StoragePathServiceTest.php` | 14 scenarios (1 skipped on Windows). |
| `tests/Feature/Services/NativeDialogServiceTest.php` | 4 scenarios. |
| `tests/Feature/Settings/StorageSettingsTest.php` | 11 scenarios. |
| `tests/Feature/Settings/EditorSettingsTest.php` | Defaults, valid patch, 8-case validation dataset. |
| `tests/Feature/Settings/GeneralSettingsTest.php` | Page, patch, invalid. |
| `tests/Feature/Settings/SettingsNavigationTest.php` | All 4 settings pages render their component. |

### Modified
| Path | Summary |
|---|---|
| `app/Providers/AppServiceProvider.php` | `scoped(SettingsService::class)`; bind `UserDirectories` → `SystemUserDirectories` (runtime `getenv()`, never config-cached). |
| `app/Http/Middleware/HandleAppearance.php` | Reads `appearance.theme` from `SettingsService` instead of the cookie. |
| `bootstrap/app.php` | `encryptCookies(except: ['sidebar_state'])` — dropped `appearance`. |
| `resources/views/app.blade.php` | `data-appearance` attribute on `<html>`; inline script reads the dataset instead of Blade-interpolating the cookie value. |
| `resources/js/composables/useAppearance.ts` | Server-backed: `readServerAppearance()`, PATCH via Wayfinder `update.url()`, `onError` reverts; `localStorage`/cookie code removed. |
| `resources/js/pages/settings/Appearance.vue` | Single root (QA-04), `theme` prop, breadcrumb via `@/routes/settings/appearance`. |
| `resources/js/layouts/settings/Layout.vue` | Nav: General, Storage, Editor, Appearance with lucide icons. |
| `resources/js/components/AppSidebar.vue` | Footer Settings link → `index` from `@/routes/settings`. |
| `resources/js/components/editor/TiptapEditor.vue` | Optional `preferences` prop → font-size/line-height style, font-family class, `tiptap-nowrap` class. |
| `resources/js/pages/Workspace.vue` | `editor: EditorPreferences` prop passed to `TiptapEditor`. |
| `app/Http/Controllers/WorkspaceController.php` | Injects `SettingsService`; adds `editor` prop. |
| `resources/css/app.css` | `.tiptap-nowrap .tiptap-content { white-space: pre; overflow-x: auto; }`. |
| `resources/js/types/index.ts` | Export `./settings`. |
| `routes/settings.php` | Full rewrite: `settings.index` → `/settings/general`; 4 controllers × edit/update, plus Storage `browse`/`destroy`. |
| `tests/Feature/DatabaseSchemaTest.php` | `settings` added to present tables; column check; `vaults/notes/vault_encryption/backups` absence guard. |
| `tests/Unit/ArchitectureTest.php` | 3 new rules: `Setting` only in `SettingsService`; `App\Enums` only enums; `Dialog` only in `NativeDialogService`. |
| `tests/Pest.php` | Added `fakeDocumentsDirectory()` helper. |
| `tests/Feature/Settings/AppearanceSettingsTest.php` | Rewritten for new route names, server-rendered theme, redirect to `settings.general.edit`. |
| `tests/Feature/WorkspaceTest.php` | Added `editor` prop assertions. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` (run as `php vendor/bin/pint …`, see Deviations) | Clean on final run (fixed import ordering along the way). |
| `php artisan test --compact` (full suite) | `129 tests, 128 passed, 345 assertions, 1 skipped`. The skip is the Windows-incompatible unwritable-directory case (`->skipOnWindows()`, as the plan specifies). |
| `vendor/bin/phpstan analyse` (level 7) | 0 errors (see Deviations for two fixes required). |
| `npm run types:check` | Clean. |
| `npm run build` | Succeeds; per-page chunks for `Storage`, `Editor`, `General`, `Appearance`. |
| `npm run check` | **Fully passes**: "All 42 files are correctly formatted" / "no warnings or lint errors in 35 files". (QA-02's vite `fmt.ignorePatterns` fix has already landed, per the task brief — unlike Phase 0 this needed no scoped workaround, only `vp check --fix` on the 2 new pages I had authored with non-canonical formatting.) |
| `php artisan route:list --path=settings` | Exactly 11 routes, matching plan §2. |
| `rg -n "C:\\\\|/Users/|/home/" app` | No matches — no hardcoded platform paths. |
| `rg -n "localStorage\|appearance=" resources/js` | No matches — no theme cookie/`localStorage` remnants. |
| `php artisan migrate --no-interaction` (browser dev DB) | `settings` table created. |
| `php artisan native:migrate --no-interaction` (native dev DB) | `settings` table created — done proactively so the desktop manual checks below have a ready schema. |

---

## 4. Deviations from Plan
- **`make:model Setting -m` and `make:enum Enums/X` wrote to the wrong paths.** With no pre-existing `app/Models/` or `app/Enums/` directory structure, `make:model Setting -m` created `app/Setting.php` (namespace `App\Setting`) instead of `app/Models/Setting.php`, and after the first `make:enum Enums/SettingType` succeeded at `app/Enums/SettingType.php`, the next three `make:enum` calls doubled the path to `app/Enums/Enums/*.php` (namespace `App\Enums\Enums`). Same for `make:interface Contracts/UserDirectories` → `app/Contracts/Contracts/UserDirectories.php`. In every case I moved the file to the plan's specified path and namespace by hand before writing its real content; the final paths/namespaces exactly match the plan (`App\Models\Setting`, `App\Enums\*`, `App\Contracts\UserDirectories`). No functional impact; flagging in case this Artisan behaviour recurs in later phases when a fresh subdirectory is being created for the first time.
- **PHPStan required two small code changes not spelled out in the plan** (plan §T11 only says "add array-shape PHPDocs rather than baseline entries"; I extended that guidance to two related cases):
  1. `StoragePathService::changeRoot()` — the plan's `! $files->makeDirectory(...) || ! $files->isDirectory(...)` check triggered `booleanOr.alwaysTrue`/`booleanNot.alwaysTrue`: PHPStan (Larastan's stubs) treats two calls to `Filesystem::isDirectory()` with the same argument as returning the same result even though `makeDirectory()` sits between them and actually mutates the filesystem. Fixed by re-checking with the raw `is_dir()` function for the post-creation check instead of a second `$this->files->isDirectory()` call — identical runtime behaviour (same underlying syscall), no suppression.
  2. `Setting` model — Larastan did not infer the `type`/`group` columns as `SettingType`/`SettingGroup` from the method-based `casts()` (as opposed to the older `protected $casts` array property), so `SettingsService::load()`'s `$row->type->value` failed with "Cannot access property $value on string." Fixed by adding accurate `@property SettingType $type` / `@property SettingGroup $group` PHPDoc to the model — this documents the real, framework-guaranteed runtime type (confirmed by the passing `SettingsServiceTest` round-trip assertions, e.g. `expect($row->type)->toBe($key->type())`), not a cast or suppression.
- **`vendor/bin/pint` had to be invoked as `php vendor/bin/pint`** in this shell (Git Bash on Windows) — the bare `vendor/bin/pint` shebang failed with `env: 'php': No such file or directory`. Functionally identical; noting it in case QA's shell has the same quirk.
- **General settings auto-save instead of a separate Save button.** The plan's UI description for General doesn't specify the exact save trigger ("Saved via useForm PATCH"). I implemented the "Detect changes made outside MDVault" checkbox to save immediately on toggle (no separate Save button), matching the instant-apply pattern already used by `AppearanceTabs`. I deliberately avoided combining `v-model` with an explicit `@update:model-value` listener on the same Checkbox (undefined event-merge ordering); instead I bind `:model-value` and handle `@update:model-value` explicitly.
- Everything else matches the plan; no scope changes.

---

## 5. Notes for QA
- `tests/Feature/Services/StoragePathServiceTest.php`'s unwritable-directory test is skipped on this (Windows) dev machine per the plan (`->skipOnWindows()`). It has never been exercised in this session — if QA has access to a Linux/macOS runner, running it there would be valuable additional coverage, though it is out of the plan's required scope for this OS.
- `SettingsService::load()` uses `Setting::query()->get(['key', 'value', 'type'])` exactly as specified; the model's new `@property` PHPDoc (see Deviations) is what makes this type-check under Larastan — worth a sanity check that future column-set changes keep the docblock accurate.
- `NativeDialogServiceTest` and `StorageSettingsTest`'s browse tests use `Http::fake()` before constructing the service/making the request, per the plan's caution that `Native\Desktop\Dialog`'s underlying `Client` captures fakes at construction and `Dialog::new()` must be called per-call (confirmed working).
- The `browsing` ref in `Storage.vue` sets a disabled state on "Choose folder…" for the duration of the `router.post`, but there is no loading spinner glyph swapped in (the `Spinner` component exists in the design system but I judged a disabled button sufficient here) — worth a UX look if the desktop dialog proves slow in practice.
- **Manual verification only (cannot be automated), via `composer native:dev`:**
  1. Settings shows General / Storage / Editor / Appearance in that order.
  2. Storage shows the default `…\Documents\MDVault` (the real Documents folder, including any OneDrive redirection).
  3. "Choose folder…" opens the native picker; picking a folder updates the path; Cancel changes nothing.
  4. Typing a relative path shows a validation error.
  5. Switching the theme to Dark, and setting editor font size 20 with wrap off, are reflected live in the Workspace editor.
  6. Quit and relaunch: the theme is dark at first paint (no flash), and the storage root and editor preferences are unchanged (both dev SQLite files were migrated this session so the schema is ready; no data has been seeded into them by this session beyond what test runs create and roll back).
  7. "Use default location" restores the default.
  - None of these seven have been run by the developer (no native shell available in this session) — this is the first thing the user should check before sign-off.

---

## 6. Fix Rounds
*(none yet)*
