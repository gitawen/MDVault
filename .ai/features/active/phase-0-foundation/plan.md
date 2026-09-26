# Plan: Phase 0 — Foundation

## Metadata
- **Feature Name**: Phase 0 — Foundation
- **Feature ID**: mdv-p0
- **Master Plan Phase**: **Phase 0 — Foundation (`docs/Masterplan.md` §49)**
- **Author**: System Analyst
- **Created Date**: 2026-09-26
- **Task Complexity**: Level 4 — Architectural
- **Requirements**: `requirements.md`
- **Status**: COMPLETE (user approved A1–A7 on 2026-09-26; QA round 1 PASS; Level 4 analyst sign-off 2026-09-27, conditional on user-verified desktop launch)

---

## 1. Summary
This plan implements Master Plan Phase 0. It turns the Laravel Vue starter kit into the MDVault foundation. NativePHP gets MDVault's identity and a configured main window. Authentication is removed entirely, so the app opens straight to a Workspace page. The SQLite schema becomes a clean baseline. The Inertia app shell follows Master Plan §36 (sidebar, header, content, status bar). Tiptap v3 renders an editable demo editor. A minimal, test-enforced service layer (`app/Services/`, first service `SystemStatusService`) proves the flow Vue → Inertia → Controller → Service → SQLite. No Phase 1+ behaviour is implemented.

---

## 2. Architecture & Design
- **Approach**:
  1. **Auth removal**: delete Fortify, passkeys, 2FA and the User model/tables. Every route is public; NativePHP's `PreventRegularBrowserAccess` guards the local server when running natively.
  2. **Service layer**: flat `app/Services/`, `final` `*Service` classes, constructor injection, no HTTP dependencies. Interfaces in `app/Contracts/` only when a real second implementation or test seam is needed (none in Phase 0). Enforced by Pest arch tests.
  3. **Desktop runtime baseline**: MDVault identity, main window config, SSR off, updater off, no external runtime URLs.
  4. **Tiptap**: a presentation-only `TiptapEditor.vue` (StarterKit) with demo content, never persisted.
- **Alternatives Considered**:
  - Keep Fortify with an auto-logged-in local user — rejected (fake account, dead code, contradicts §5/§64).
  - Keep Fortify installed but disable its features/routes — rejected (dead dependencies and tables).
  - Stub all 10 Master Plan services now — rejected (speculative signatures, not "don't implement major features yet").
  - Interfaces for every service — rejected (no second implementations yet).
  - Keep SSR — rejected (no Node SSR server in the packaged app; Tiptap is DOM-bound).
  - Additive "drop users" migration instead of editing base migrations — rejected (pre-release app; v1 migration history should be clean).
- **Decision Records**:
  - `.ai/decisions/local-app-without-authentication.md`
  - `.ai/decisions/service-layer-architecture.md`
  - `.ai/decisions/desktop-runtime-baseline.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `users` | remove (edit base migration) | — |
| `password_reset_tokens` | remove (edit base migration) | — |
| `passkeys` | remove (delete migration `2024_01_01_000000_create_passkeys_table.php`) | — |
| `users` 2FA columns | remove (delete migration `2025_08_14_170933_add_two_factor_columns_to_users_table.php`) | — |
| `sessions` | keep (moved into renamed migration `0001_01_01_000000_create_sessions_table.php`) | unchanged: `id` PK, `user_id` nullable indexed (the framework's database session handler still writes this column), `ip_address`, `user_agent`, `payload`, `last_activity` indexed |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | unchanged | — |

No Master Plan v1 tables (`settings`, `vaults`, `notes`, `vault_encryption`, `backups`) are created in Phase 0.

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Service (new) | `app/Services/SystemStatusService.php` | Reports app name, version, runtime (`desktop`/`browser`), DB driver and connectivity. Never throws on DB failure. |
| Controller (new, invokable) | `app/Http/Controllers/WorkspaceController.php` | Renders Inertia `Workspace` with `status` from `SystemStatusService`. |
| Provider (modify) | `app/Providers/NativeAppServiceProvider.php` | Opens the main window with the MDVault window configuration. |
| Provider (modify) | `app/Providers/AppServiceProvider.php` | Remove `Password::defaults` (no users). Keep `Date::use` and `prohibitDestructiveCommands`. |
| Middleware (modify) | `app/Http/Middleware/HandleInertiaRequests.php` | Stop sharing `auth`. Keep `name` and `sidebarOpen`. |
| Config (modify) | `config/nativephp.php`, `config/inertia.php` | Identity, updater default off; SSR off. |
| Config (delete) | `config/fortify.php`, `config/auth.php` | Auth removed; framework defaults suffice. |
| Removed | `app/Actions/Fortify/*`, `app/Concerns/*`, `app/Http/Controllers/Settings/*`, `app/Http/Requests/Settings/*`, `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, `database/factories/UserFactory.php` | Auth removal |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Page (new) | `resources/js/pages/Workspace.vue` | Workspace: `TiptapEditor` with demo content plus `StatusBar`. Single root. |
| Component (new) | `resources/js/components/editor/TiptapEditor.vue` | Presentation-only Tiptap v3 editor (StarterKit). Props `content: string`, `editable?: boolean` (default true). |
| Component (new) | `resources/js/components/StatusBar.vue` | Shows runtime, database status, app name and version from the `status` prop. |
| Type (new) | `resources/js/types/system.ts` | `SystemStatus` type matching the service's array shape. |
| Component (modify) | `resources/js/components/AppSidebar.vue` | Logo → workspace; nav "Workspace"; empty "Vaults" group; footer "Settings" link. Remove NavFooter/NavUser. |
| Component (modify) | `resources/js/components/NavMain.vue` | Add optional `label` prop (default `'Navigation'`) replacing hard-coded "Platform". |
| Layout (modify) | `resources/js/layouts/settings/Layout.vue` | Nav shows only Appearance; heading copy updated. |
| Page (modify) | `resources/js/pages/settings/Appearance.vue` | Copy: "Choose how MDVault looks on this device." |
| Entry (modify) | `resources/js/app.ts` | Layout resolver: `settings/*` → `[AppLayout, SettingsLayout]`, else `AppLayout`. Title fallback `MDVault`. |
| Types (modify) | `resources/js/types/global.d.ts`, `resources/js/types/index.ts` | Remove `auth`/`Auth`; export `system` types. |
| CSS (modify) | `resources/css/app.css` | Minimal `.tiptap-content` typography rules. |
| Removed | see T3 step B | Auth/account/marketing UI |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| GET | `/` | `workspace` | `WorkspaceController` (invokable) | web |
| GET | `/settings` | `settings` | `Route::redirect` → `/settings/appearance` | web |
| GET | `/settings/appearance` | `appearance.edit` | `Route::inertia('settings/Appearance')` | web |
| GET | `/up` | — | framework health | — |

All Fortify/passkey/profile/security/dashboard routes are removed.

---

## 3. Implementation Tasks
*Ordered. Each task leaves the application working. T3's sub-steps **must** run in the order written: deleting code before `composer remove` is needed because `package:discover` boots the app.*

- [ ] **T0 — Preconditions (user / orchestrator)**
  - Confirm approvals A1–A7 (§6).
  - User runs `git init && git add -A && git commit -m "Starter kit baseline before Phase 0"` (A7), so the deletions can be reviewed and reverted.
  - Covers: —

- [x] **T1 — Application identity & desktop runtime baseline**
  - Files:
    - `.env.example`: `APP_NAME=MDVault`. Also update the local `.env` `APP_NAME=MDVault`, or tell the user to.
    - `resources/js/app.ts`: `const appName = import.meta.env.VITE_APP_NAME || 'MDVault';`
    - `config/nativephp.php`:
      - `'version' => env('NATIVEPHP_APP_VERSION', '0.1.0')`
      - `'app_id' => env('NATIVEPHP_APP_ID', 'com.mdvault.app')` (A6)
      - `'description' => env('NATIVEPHP_APP_DESCRIPTION', 'Local-first Markdown knowledge vault')`
      - `'updater' => ['enabled' => env('NATIVEPHP_UPDATER_ENABLED', false), ...]` (rest unchanged)
    - `app/Providers/NativeAppServiceProvider.php` `boot()`:
      ```php
      Window::open()
          ->title((string) config('app.name'))
          ->width(1280)
          ->height(800)
          ->minWidth(960)
          ->minHeight(600)
          ->rememberState();
      ```
      Keep `phpIni()` returning `[]`. Add array-shape PHPDoc `@return array<string, string>`.
    - `config/inertia.php`: `'ssr' => ['enabled' => false, 'url' => 'http://127.0.0.1:13714']`
    - `vite.config.ts`: `inertia({ ssr: false })`
    - `package.json`: remove the `build:ssr` script.
  - Test: `php artisan make:test NativeAppServiceProviderTest --pest --no-interaction` → `tests/Feature/NativeAppServiceProviderTest.php`. Use `Window::fake()->alwaysReturnWindows([$window = new \Native\Desktop\Windows\Window('main')])`, call `(new NativeAppServiceProvider)->boot()`, then `Window::assertOpened('main')` and `expect($window->toArray())->toMatchArray(['title' => config('app.name'), 'width' => 1280, 'height' => 800, 'minWidth' => 960, 'minHeight' => 600, 'rememberState' => true])`.
  - Covers: FR-01, FR-08 (SSR/updater), FR-09

- [x] **T2 — Service layer foundation**
  - Command: `php artisan make:class Services/SystemStatusService --no-interaction` → `app/Services/SystemStatusService.php` (new folder, A4)
  - Details:
    ```php
    final class SystemStatusService
    {
        public function __construct(
            private readonly \Illuminate\Database\DatabaseManager $database,
            private readonly \Illuminate\Contracts\Config\Repository $config,
        ) {}

        /**
         * @return array{application: string, version: string, runtime: 'desktop'|'browser', database: array{driver: string, connected: bool}}
         */
        public function summary(): array;

        public function isRunningAsDesktopApp(): bool; // (bool) config 'nativephp-internal.running'

        public function databaseIsConnected(): bool;   // try { connection()->select('select 1'); return true; } catch (\Exception) { return false; }
    }
    ```
    - `application` = `config('app.name')`; `version` = `config('nativephp.version')`; `driver` = `$this->database->connection()->getDriverName()`. Wrap the driver lookup in the same failure handling: on exception return `config("database.connections.{default}.driver")` or `'unknown'`.
    - Catch `\Exception`, not `\Throwable`: `QueryException`, `PDOException` and `SQLiteDatabaseDoesNotExistException` are all `Exception`s. Document "health probe never throws" in PHPDoc.
    - No logging, no HTTP types.
  - Tests:
    - `php artisan make:test Services/SystemStatusServiceTest --pest --no-interaction` → `tests/Feature/Services/SystemStatusServiceTest.php`:
      1. Real SQLite: `connected` true, `driver` `sqlite`.
      2. Failure: build `new SystemStatusService($dbMock, config())` where a Mockery `DatabaseManager` returns a Mockery `Illuminate\Database\Connection` whose `select` throws `new \PDOException('unavailable')` and `getDriverName` returns `'sqlite'`. Assert `connected` false and no exception. **Do not** change `database.default` in the test: RefreshDatabase rollback would then hit the broken connection.
      3. `config(['nativephp-internal.running' => true])` → `runtime` `desktop`; default → `browser`.
    - `php artisan make:test ArchitectureTest --pest --unit --no-interaction` → `tests/Unit/ArchitectureTest.php`:
      ```php
      arch()->preset()->php();
      arch()->preset()->security();
      arch('services are final and suffixed with Service')
          ->expect('App\Services')->classes()->toBeFinal()->toHaveSuffix('Service');
      arch('services do not depend on the HTTP layer')
          ->expect('App\Services')->not->toUse(['App\Http', 'Illuminate\Http', 'Inertia']);
      arch('HTTP layer does not touch the filesystem directly')
          ->expect(['Illuminate\Support\Facades\File', 'Illuminate\Support\Facades\Storage',
                    'file_put_contents', 'file_get_contents', 'fopen', 'unlink', 'mkdir', 'rmdir', 'rename', 'copy', 'scandir'])
          ->not->toBeUsedIn('App\Http');
      ```
      If a preset flags existing starter code, fix it or use `->ignoring(...)` with a one-line reason, and record this in `implementation.md`.
  - Covers: FR-06

- [x] **T3 — Remove authentication; establish workspace routing, shell and basic settings**
  - **Step A — backend routes & controller**
    - Command: `php artisan make:controller WorkspaceController --invokable --no-interaction`
    - `WorkspaceController::__invoke(SystemStatusService $systemStatus): \Inertia\Response` → `Inertia::render('Workspace', ['status' => $systemStatus->summary()])`.
    - `routes/web.php`:
      ```php
      Route::get('/', WorkspaceController::class)->name('workspace');
      require __DIR__.'/settings.php';
      ```
    - `routes/settings.php` (replace contents):
      ```php
      Route::redirect('settings', '/settings/appearance')->name('settings');
      Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
      ```
      Remove the profile, security and password routes and the `.well-known/passkey-endpoints` route.
  - **Step B — delete auth code (backend + frontend) BEFORE removing packages**
    - Backend delete:
      - `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/ResetUserPassword.php` (remove the empty `app/Actions/`)
      - `app/Concerns/PasswordValidationRules.php`, `app/Concerns/ProfileValidationRules.php` (remove `app/Concerns/`)
      - `app/Http/Controllers/Settings/ProfileController.php`, `SecurityController.php` (remove dir)
      - `app/Http/Requests/Settings/*.php` (4 files; remove `app/Http/Requests/`)
      - `app/Models/User.php` (remove `app/Models/`; Phase 2 recreates it via `make:model`)
      - `app/Providers/FortifyServiceProvider.php`, and remove it from `bootstrap/providers.php`
      - `config/fortify.php`, `config/auth.php`
      - `database/factories/UserFactory.php` (add `database/factories/.gitkeep`)
    - Backend modify:
      - `app/Providers/AppServiceProvider.php`: remove the `Password::defaults(...)` block and the `Password` import.
      - `app/Http/Middleware/HandleInertiaRequests.php`: remove the `'auth'` key.
      - `database/seeders/DatabaseSeeder.php`: empty `run()` body; remove the `User` import.
      - `tests/TestCase.php`: remove `skipUnlessFortifyHas()` and the Fortify import. Leave an empty abstract class.
    - Frontend delete (then grep for leftover imports):
      - Pages: `pages/Welcome.vue`, `pages/Dashboard.vue`, `pages/auth/*` (Login, Register, ForgotPassword, ResetPassword, ConfirmPassword, TwoFactorChallenge), `pages/settings/Profile.vue`, `pages/settings/Security.vue`
      - Layouts: `layouts/AuthLayout.vue`, `layouts/auth/*` (3), `layouts/app/AppHeaderLayout.vue`
      - Components: `AppHeader.vue`, `NavUser.vue`, `NavFooter.vue`, `UserInfo.vue`, `UserMenuContent.vue`, `DeleteUser.vue`, `ManagePasskeys.vue`, `ManageTwoFactor.vue`, `PasskeyItem.vue`, `PasskeyRegister.vue`, `PasskeyVerify.vue`, `PasswordInput.vue`, `TwoFactorRecoveryCodes.vue`, `TwoFactorSetupModal.vue`, `TextLink.vue`, `PlaceholderPattern.vue`, `components/ui/input-otp/*`
      - Composables: `useTwoFactorAuth.ts`; `useInitials.ts` if no importers remain
      - Types: `types/auth.ts`
      - **Keep** generic components (`AlertError`, `InputError`, `Heading`, `Breadcrumbs`, `AppLogo*`, `AppShell`, `AppContent`, `AppSidebarHeader`, `AppearanceTabs`, all other `components/ui/*`).
    - Frontend modify:
      - `resources/js/app.ts`: remove the `AuthLayout` import. Layout resolver: `name.startsWith('settings/') ? [AppLayout, SettingsLayout] : AppLayout`.
      - `resources/js/types/global.d.ts`: remove the `Auth` import and the `auth` key from `sharedPageProps`.
      - `resources/js/types/index.ts`: remove `export * from './auth'`; add `export * from './system'`.
      - New `resources/js/types/system.ts`:
        ```ts
        export type SystemStatus = {
            application: string;
            version: string;
            runtime: 'desktop' | 'browser';
            database: { driver: string; connected: boolean };
        };
        ```
      - `resources/js/components/NavMain.vue`: add `label?: string` (default `'Navigation'`) and render it in `SidebarGroupLabel`.
      - `resources/js/components/AppSidebar.vue`:
        - Logo `Link` → `workspace()` (from `@/routes`).
        - `NavMain` with `label="Workspace"` and item `{ title: 'Workspace', href: workspace(), icon: NotebookPen }`.
        - A `SidebarGroup` labelled "Vaults" with muted text "No vaults yet".
        - `SidebarFooter`: `SidebarMenu` → `SidebarMenuItem` → `SidebarMenuButton as-child tooltip="Settings"` → `<Link :href="editAppearance()">` with the lucide `Settings` icon.
        - Remove the `NavFooter`/`NavUser` imports and external links.
      - `resources/js/layouts/settings/Layout.vue`: `sidebarNavItems` = Appearance only. Heading description: "Manage application preferences".
      - `resources/js/pages/settings/Appearance.vue`: description "Choose how MDVault looks on this device."
    - New `resources/js/components/StatusBar.vue`:
      - Prop `status: SystemStatus`. Single root `<footer>` (border-top, small muted text, flex, justify-between).
      - Left: runtime label ("Desktop" / "Browser") and database `"{driver} · Connected"` or `"Database unavailable"`, with a green or red dot. Right: `{application} v{version}`.
    - New `resources/js/pages/Workspace.vue` (initial version; T4 adds the editor):
      - Prop `status: SystemStatus`. Single root `<div class="flex min-h-0 flex-1 flex-col">`.
      - `<Head title="Workspace" />` goes **inside** the root.
      - `<main class="flex-1 overflow-auto p-4">` placeholder, then `<StatusBar :status="status" />`.
      - `defineOptions({ layout: { breadcrumbs: [{ title: 'Workspace', href: workspace() }] } })`.
  - **Step C — dependency removal (A1)**
    - `composer remove laravel/fortify --no-interaction`. Its `post-update-cmd` runs `boost:update` and `native:install --force --quiet --publish`; that needs internet for npm and may refresh `nativephp/electron/`, which is expected.
    - Verify `composer show laravel/passkeys` reports not installed. If it remains, report it; do not remove it silently.
    - `npm uninstall @laravel/passkeys vue-input-otp`
  - **Step D — database (A5)**
    - Rename `database/migrations/0001_01_01_000000_create_users_table.php` → `0001_01_01_000000_create_sessions_table.php`. Keep only the `sessions` schema; `down()` drops only `sessions`.
    - Delete `database/migrations/2024_01_01_000000_create_passkeys_table.php` and `database/migrations/2025_08_14_170933_add_two_factor_columns_to_users_table.php`.
    - `php artisan migrate:fresh --no-interaction` (browser dev DB)
    - `php artisan native:migrate:fresh --no-interaction` (NativePHP dev DB `database/nativephp.sqlite`). Fallback: delete `database/nativephp.sqlite*`; NativePHP recreates and migrates it on the next `native:run` in debug mode.
  - **Step E — regenerate & clear**
    - `php artisan optimize:clear`
    - `php artisan wayfinder:generate --with-form --no-interaction` (removes stale Fortify/passkey/profile route files)
    - `php artisan route:list --except-vendor` must list only `/`, `settings`, `settings/appearance` (and `up`).
  - **Step F — tests (A3)**
    - Delete `tests/Feature/Auth/AuthenticationTest.php`, `PasswordConfirmationTest.php`, `PasswordResetTest.php`, `RegistrationTest.php`, `TwoFactorChallengeTest.php`, `tests/Feature/Settings/ProfileUpdateTest.php`, `tests/Feature/Settings/SecurityTest.php`, `tests/Feature/DashboardTest.php`, `tests/Feature/ExampleTest.php`.
    - `php artisan make:test WorkspaceTest --pest --no-interaction`:
      - Guest GET `route('workspace')` → 200. `assertInertia`: component `Workspace`, `status.database.connected` true, `status.database.driver` `sqlite`, `status.runtime` `browser`, `status.application` `config('app.name')`, has `status.version`.
      - Dataset of removed URIs → `assertNotFound()`: `/login`, `/register`, `/forgot-password`, `/two-factor-challenge`, `/user/confirm-password`, `/dashboard`, `/settings/profile`, `/settings/security`, `/.well-known/passkey-endpoints`.
    - `php artisan make:test Settings/AppearanceSettingsTest --pest --no-interaction`: GET `/settings` redirects to `route('appearance.edit')`; guest GET `route('appearance.edit')` → 200, component `settings/Appearance`.
    - `php artisan make:test DatabaseSchemaTest --pest --no-interaction`: `Schema::hasTable` true for `sessions`, `cache`, `jobs`; false for `users`, `password_reset_tokens`, `passkeys`.
  - Covers: FR-02, FR-03, FR-04, FR-06 (controller → service), FR-07, FR-08 (external URLs removed)

- [x] **T4 — Tiptap editor renders in the Workspace (A2)**
  - Command: `npm install @tiptap/vue-3@^3.31.3 @tiptap/pm@^3.31.3 @tiptap/starter-kit@^3.31.3`. All three must resolve to the same version. `@floating-ui/dom` (peer) is already present via reka-ui.
  - New `resources/js/components/editor/TiptapEditor.vue` (new folder, A4):
    - `<script setup lang="ts">`, props `content: string`, `editable?: boolean` (default `true`).
    - `const editor = useEditor({ content: props.content, editable: props.editable, extensions: [StarterKit], editorProps: { attributes: { class: 'tiptap-content', 'aria-label': 'Note editor' } } })`. `useEditor` destroys the editor on unmount.
    - Template single root: `<div class="rounded-lg border bg-card p-4"><EditorContent :editor="editor" /></div>`.
    - Initial content only (no watcher, no emits). No filesystem or Markdown logic (Phase 4).
  - `resources/css/app.css`: add a small `@layer components` block for `.tiptap-content`:
    - `outline-none`, min height
    - h1/h2/h3 sizes and weights, paragraph spacing
    - `ul` `list-disc pl-6`, `ol` `list-decimal pl-6`
    - blockquote left border + muted text
    - inline `code` and `pre` with `bg-muted`, rounded, monospace
    - `hr` border
    - Use `@apply` with existing theme tokens; must work in dark mode.
  - `resources/js/pages/Workspace.vue`: replace the placeholder in `<main>` with `<TiptapEditor :content="demoContent" />`. `demoContent` is a constant HTML string: `<h1>Welcome to MDVault</h1>`, a paragraph saying this is a preview editor and changes are not saved yet, and a 3-item bullet list.
  - Covers: FR-05

- [x] **T5 — Quality gates & handover**
  - `vendor/bin/pint --dirty --format agent`
  - `php artisan test --compact`
  - `vendor/bin/phpstan analyse`
  - `npm run types:check`
  - `npm run check` (fix with `npm run check:fix` where it's formatting only)
  - `npm run build`
  - Offline grep: `rg -n "https?://" resources/js -g "!resources/js/{actions,routes,wayfinder}/**"` → only SVG `xmlns` and the `http://localhost` fallback in `useCurrentUrl.ts`.
  - Record in `implementation.md`: commands run, results, and anything left for manual verification.
  - **Manual (user)**: `composer native:dev`. Check that:
    - the window titled "MDVault" opens around 1280×800, and resizing below 960×600 is prevented;
    - the Workspace shows the sidebar, the editor accepts typing and the status bar reads "Desktop · sqlite · Connected";
    - Settings → Appearance switches the theme;
    - after closing and reopening, the window size and position are restored.
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/NativeAppServiceProviderTest.php` | Main window opened with MDVault title, 1280×800, min 960×600, rememberState | FR-01, FR-09 |
| `tests/Feature/WorkspaceTest.php` | Guest opens `/` → `Workspace` with correct `status` props (sqlite, connected, browser, app name, version) | FR-02, FR-03, FR-04, FR-06 |
| `tests/Feature/WorkspaceTest.php` | Removed auth/account URIs return 404 (dataset) | FR-02 |
| `tests/Feature/DatabaseSchemaTest.php` | Framework tables exist; `users`, `password_reset_tokens`, `passkeys` absent | FR-03 |
| `tests/Feature/Services/SystemStatusServiceTest.php` | Connected on SQLite; disconnected (no exception) when the connection throws; runtime desktop vs browser | FR-06, FR-03 |
| `tests/Unit/ArchitectureTest.php` | php + security presets; services final/suffixed/HTTP-free; HTTP layer has no direct filesystem access | FR-06 |
| `tests/Feature/Settings/AppearanceSettingsTest.php` | `/settings` redirects; appearance page renders for a guest | FR-07 |
| (build/static) `npm run build`, `npm run types:check`, `npm run check` | Tiptap + shell compile and type-check; no dangling auth imports | FR-04, FR-05, FR-08 |
| (manual) `composer native:dev` | Desktop launch, editor typing, theme switch, window state restored | FR-01, FR-04, FR-05, FR-07 |

**Test scope for QA**:
- `php artisan test --compact` (full suite; Phase 0 touches the whole app). It covers `tests/Unit/ArchitectureTest.php`, `tests/Feature/NativeAppServiceProviderTest.php`, `tests/Feature/WorkspaceTest.php`, `tests/Feature/DatabaseSchemaTest.php`, `tests/Feature/Services/SystemStatusServiceTest.php` and `tests/Feature/Settings/AppearanceSettingsTest.php`.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run check`, `npm run build`.
- `php artisan route:list --except-vendor` (expect only `/`, `settings`, `settings/appearance`, `up`).
- `composer show laravel/fortify` and `composer show laravel/passkeys` (expect not installed).
- The offline `rg` check from T5.
- QA cannot verify desktop launch headlessly; mark FR-01's manual part as "user-verified".

---

## 5. Risks & Mitigations
- **Risk**: No version control; about 50 deletions. — **Mitigation**: T0 git snapshot (A7).
- **Risk**: `composer remove laravel/fortify` fails in `package:discover` if Fortify-dependent app code still exists. — **Mitigation**: T3 Step B strictly before Step C.
- **Risk**: `composer remove` runs `native:install --force --publish` (npm install for Electron, needs network, refreshes `nativephp/electron/`). — **Mitigation**: expected; run online. `vendor:publish` without `--force` does not overwrite `config/nativephp.php` or `NativeAppServiceProvider`.
- **Risk**: Stale Wayfinder files or config cache reference removed routes. — **Mitigation**: T3 Step E (`optimize:clear`, `wayfinder:generate --with-form`, which cleans the output directories).
- **Risk**: Tiptap packages at different versions (exact-version peers). — **Mitigation**: install all three with the same `^3.31.3` range in one command; check `npm ls @tiptap/core`.
- **Risk**: `vp check` denies warnings with type-aware lint, so new Vue/TS may fail lint. — **Mitigation**: run `npm run check` before QA.
- **Risk**: Pest `security`/`php` presets flag starter code. — **Mitigation**: fix, or `->ignoring()` with a recorded reason.
- **Risk**: `@fonts` (laravel-vite-plugin bunny) needs network at build/dev time. — **Mitigation**: fonts are self-hosted into the build, so the runtime is offline-safe. Phase 8 packaging builds online. Noted, no change in Phase 0.
- **Risk**: Unauthenticated local HTTP server. — **Mitigation**: NativePHP `PreventRegularBrowserAccess` when running natively (ADR); browser dev via Herd is local-only.
- **Risk**: Desktop launch can't be automated. — **Mitigation**: window-config test via `Window::fake()` + manual checklist in T5.

---

## 6. Open Questions
*Approvals required before implementation. Recommended answer in bold.*
- [ ] **A1** — Remove dependencies: composer `laravel/fortify` (with transitive `laravel/passkeys`, `pragmarx/google2fa`, `bacon/bacon-qr-code`); npm `@laravel/passkeys`, `vue-input-otp`. **Approve.**
- [ ] **A2** — Add npm dependencies `@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/starter-kit` (^3.31.3). **Approve.**
- [ ] **A3** — Delete the existing tests (`tests/Feature/Auth/*` ×5, `Settings/ProfileUpdateTest.php`, `Settings/SecurityTest.php`, `DashboardTest.php`, `ExampleTest.php`) and the auth app files listed in T3 Step B. **Approve.**
- [ ] **A4** — New folders `app/Services/` and `resources/js/components/editor/`. **Approve.**
- [ ] **A5** — Edit the base migrations in place (instead of adding drop migrations) and wipe the local dev DBs (`database/database.sqlite`, `database/nativephp.sqlite`; they only contain starter test data). **Approve.**
- [ ] **A6** — NativePHP `app_id`. It is effectively permanent: it determines the installed app's data directory. **`com.mdvault.app`**, or provide your own reverse-domain ID.
- [ ] **A7** — Initialise git and commit a baseline before T3. **Approve (user runs it).**
- [ ] *(Non-blocking, confirm)* — "Basic settings" in Phase 0 = identity/runtime config + the existing Appearance page. The persisted `SettingsService` is Phase 1.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-26 | Initial plan | — |
| 1.1 | 2026-09-27 | Analyst sign-off | No design change. Plan erratum: `route:list --except-vendor` hides `Route::inertia` and `/up` routes (expect `/`, `settings` only; verify the rest via plain `route:list`). NativeAppServiceProviderTest requires `Http::fake()` alongside `Window::fake()`. Deferred: QA-01 → Phase 1 first task; QA-02 + QA-03 → Level 2 tooling chore before Phase 1 QA; QA-04 → Phase 2 (AppSidebar) / Phase 1 (Appearance page). |
