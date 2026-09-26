# Implementation: Phase 0 — Foundation

## Metadata
- **Feature Name**: Phase 0 — Foundation
- **Feature ID**: mdv-p0
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Application identity & desktop runtime baseline | DONE | Window config, SSR off, updater off by default, `app_id`/version/description set. |
| T2 — Service layer foundation | DONE | `SystemStatusService` + `SystemStatusServiceTest` + `ArchitectureTest` (php/security presets pass with no `ignoring()` needed). |
| T3 — Remove authentication; workspace routing, shell, basic settings | DONE | Steps A–F completed in order. See deviations below for two adjustments. |
| T4 — Tiptap editor renders in the Workspace | DONE | `@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/starter-kit` all resolve to `3.31.3`. |
| T5 — Quality gates & handover | DONE (with one noted pre-existing, out-of-scope failure) | See §3. |

---

## 2. Files Changed

### Created
| Path | Summary |
|---|---|
| `app/Services/SystemStatusService.php` | Reports app/version/runtime/database status; never throws. |
| `app/Http/Controllers/WorkspaceController.php` | Invokable; renders `Workspace` with `status`. |
| `database/migrations/0001_01_01_000000_create_sessions_table.php` | Renamed from the users migration; `sessions` only. |
| `database/factories/.gitkeep` | Keeps the now-empty factories folder. |
| `resources/js/pages/Workspace.vue` | Workspace page: Tiptap editor + status bar, single root. |
| `resources/js/components/StatusBar.vue` | Runtime/database/app status footer. |
| `resources/js/components/editor/TiptapEditor.vue` | Presentation-only Tiptap v3 (StarterKit) editor. |
| `resources/js/types/system.ts` | `SystemStatus` type. |
| `tests/Feature/NativeAppServiceProviderTest.php` | Window config test via `Window::fake()`. |
| `tests/Feature/Services/SystemStatusServiceTest.php` | Connected/disconnected/runtime cases. |
| `tests/Unit/ArchitectureTest.php` | php/security presets + service-layer conventions. |
| `tests/Feature/WorkspaceTest.php` | Guest workspace render + removed-URL dataset (404s). |
| `tests/Feature/Settings/AppearanceSettingsTest.php` | `/settings` redirect + appearance page render. |
| `tests/Feature/DatabaseSchemaTest.php` | Framework tables present; auth tables absent. |

### Modified
| Path | Summary |
|---|---|
| `.env`, `.env.example` | `APP_NAME=MDVault`. |
| `resources/js/app.ts` | Fallback app name `MDVault`; removed `AuthLayout`; simplified layout resolver (`settings/*` → `[AppLayout, SettingsLayout]`, else `AppLayout`). |
| `config/nativephp.php` | `version` `0.1.0`, `app_id` `com.mdvault.app`, description, `updater.enabled` default `false`. |
| `app/Providers/NativeAppServiceProvider.php` | `boot()` opens the main window (1280×800, min 960×600, title, `rememberState`); `phpIni()` PHPDoc array shape. |
| `config/inertia.php` | `ssr.enabled` → `false`. |
| `vite.config.ts` | `inertia({ ssr: false })`. |
| `package.json` | Removed `build:ssr` script; removed `@laravel/passkeys`/`vue-input-otp`; added Tiptap packages. |
| `bootstrap/providers.php` | Removed `FortifyServiceProvider`. |
| `app/Providers/AppServiceProvider.php` | Removed `Password::defaults` block and import. |
| `app/Http/Middleware/HandleInertiaRequests.php` | Removed shared `auth` prop. |
| `database/seeders/DatabaseSeeder.php` | Empty `run()`; removed `User` import. |
| `tests/TestCase.php` | Removed `skipUnlessFortifyHas()` and the Fortify import. |
| `routes/web.php` | `/` → `WorkspaceController` (`workspace`); requires `settings.php`. |
| `routes/settings.php` | Redirect `settings` → `/settings/appearance`; `appearance.edit` only. |
| `resources/js/components/AppSidebar.vue` | Logo/nav → `workspace()`; "Vaults" placeholder group; footer Settings link; removed `NavFooter`/`NavUser`. |
| `resources/js/components/NavMain.vue` | Added `label?: string` prop (default `'Navigation'`). |
| `resources/js/layouts/settings/Layout.vue` | Appearance-only nav; updated heading copy. |
| `resources/js/pages/settings/Appearance.vue` | Updated description copy. |
| `resources/js/types/global.d.ts` | Removed `Auth` import and `auth` shared-prop key. |
| `resources/js/types/index.ts` | Removed `./auth` export; added `./system` export. |
| `resources/css/app.css` | Added `@layer components` block for `.tiptap-content` (headings, lists, blockquote, code/pre, hr; dark-mode-safe via theme tokens). |

### Deleted
| Path | Summary |
|---|---|
| `app/Actions/Fortify/*`, `app/Concerns/*`, `app/Http/Controllers/Settings/*`, `app/Http/Requests/Settings/*`, `app/Models/User.php`, `app/Providers/FortifyServiceProvider.php`, `database/factories/UserFactory.php`, `config/fortify.php`, `config/auth.php` | Auth removal (A1/A3). |
| `database/migrations/2024_01_01_000000_create_passkeys_table.php`, `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | Dropped passkeys/2FA tables (A5). |
| `resources/js/pages/Welcome.vue`, `Dashboard.vue`, `auth/*` (6), `settings/Profile.vue`, `settings/Security.vue` | Auth/marketing pages. |
| `resources/js/layouts/AuthLayout.vue`, `layouts/auth/*` (3), `layouts/app/AppHeaderLayout.vue` | Auth layouts. |
| `resources/js/components/AppHeader.vue`, `NavUser.vue`, `NavFooter.vue`, `UserInfo.vue`, `UserMenuContent.vue`, `DeleteUser.vue`, `ManagePasskeys.vue`, `ManageTwoFactor.vue`, `PasskeyItem.vue`, `PasskeyRegister.vue`, `PasskeyVerify.vue`, `PasswordInput.vue`, `TwoFactorRecoveryCodes.vue`, `TwoFactorSetupModal.vue`, `TextLink.vue`, `PlaceholderPattern.vue`, `components/ui/input-otp/*` | Auth-only UI. |
| `resources/js/composables/useTwoFactorAuth.ts`, `useInitials.ts` | `useInitials` had no remaining importers once `AppHeader`/`UserInfo` were removed. |
| `resources/js/types/auth.ts` | No longer referenced. |
| `tests/Feature/Auth/*` (5), `tests/Feature/Settings/ProfileUpdateTest.php`, `SecurityTest.php`, `tests/Feature/DashboardTest.php`, `tests/Feature/ExampleTest.php` | Approved under A3. |
| Stale Wayfinder output for Fortify/passkeys/profile/security routes | Removed by `wayfinder:generate` after route changes. |

Composer: removed `laravel/fortify` (pulled `laravel/passkeys`, `bacon/bacon-qr-code`, `dasprid/enum` transitively). npm: removed `@laravel/passkeys`, `vue-input-otp`; added `@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/starter-kit` (all `3.31.3`, deduped — confirmed via `npm ls`).

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | Fixed 1 file (`NativeAppServiceProvider.php`, import order) on first run; clean on final run. |
| `php artisan test --compact` (full suite) | 29 passed, 81 assertions. |
| `vendor/bin/phpstan analyse` | 0 errors. |
| `npm run types:check` | Clean. |
| `npm run build` | Succeeds; `Workspace` and `Appearance` are the only page chunks; Tiptap bundles into the `Workspace` chunk. |
| `npm run check` | **Fails**, but only on ~110 files this feature never touched: repo docs (`docs/Masterplan.md`, `README.md`, etc.), `.claude/`/`.agents/`/`.gemini/` skill and agent files, and the vendored `nativephp/electron/**` sources. Root cause: `vite.config.ts`'s `fmt.ignorePatterns` (untouched by this plan) is narrower than `lint.ignorePatterns` and never excluded these paths — pre-existing, confirmed via `git diff --stat` showing zero changes to any flagged file. Scoped check on everything this task authored/edited (`npx vp check resources/js/components resources/js/pages resources/js/layouts resources/js/types resources/js/app.ts resources/css`) passes: "All 27 files are correctly formatted" / "no warnings or lint errors in 26 files". Left unfixed — see Deviations. |
| `php artisan route:list --except-vendor` | `/`, `settings` (2 rows). `settings/appearance` and `up` are Inertia/framework-vendor actions, so `--except-vendor` hides them too — confirmed via plain `php artisan route:list` (11 rows: `/`, `settings`, `settings/appearance`, `up`, plus Boost/Inertia-devtools/NativePHP internal/storage-disk routes). No Fortify/passkey/profile/security/dashboard routes remain. |
| `composer show laravel/fortify` / `composer show laravel/passkeys` | Both report "not found" — not installed. |
| Offline grep: `rg -n "https?://" resources/js -g "!resources/js/{actions,routes,wayfinder}/**"` | Only `AppLogoIcon.vue`'s SVG `xmlns` and `useCurrentUrl.ts`'s `http://localhost` fallback. |
| `database/database.sqlite` schema (browser) | `sessions`, `cache`, `jobs` present; `users`, `password_reset_tokens`, `passkeys` absent (also asserted by `DatabaseSchemaTest`). |
| `database/nativephp.sqlite` schema (native dev) | Same clean schema, confirmed directly against the SQLite file. |

---

## 4. Deviations from Plan
- **`NativeAppServiceProviderTest` adds `Http::fake()`.** The plan's test recipe (`Window::fake()->alwaysReturnWindows([...])`, then call `boot()`) doesn't work unmodified: the fake `WindowManager::open()` returns a plain `Native\Desktop\Windows\Window`, not a `PendingOpenWindow`. Calling `->title(...)` on that instance is *not* deferred — the base `Window` class immediately does `$this->client->post('window/title', ...)`, which is a real Guzzle HTTP call. Without an HTTP fake this throws `cURL error 7: Failed to connect to localhost:4000` (confirmed by running the test unmodified). Fix: `Http::fake()` before opening the window. The assertions (`Window::assertOpened('main')`, `toArray()` matching title/width/height/minWidth/minHeight/rememberState) are exactly as specified in the plan; only the HTTP side-effect of `->title()` needed neutralizing.
- **`npm run check` left failing on out-of-scope files** (see §3 for detail). Fixing it would mean reformatting the Masterplan, agent/skill docs, and vendored Electron sources — none of which are Phase 0 deliverables, none of which this plan authorized touching, and none of which regressed because of this work (`git diff` shows zero changes to any of them). Recommend a follow-up decision (widen `fmt.ignorePatterns` in `vite.config.ts` to match `lint.ignorePatterns`, or run a one-off repo-wide `check:fix` under its own review) rather than folding it into Phase 0.
- **`php artisan route:list --except-vendor` shows only 2 of the plan's expected 4 routes.** Not a functional gap — `settings/appearance` (`Route::inertia(...)` → `Inertia\Controller`) and `up` (framework health check) are both vendor-attributed actions, so `--except-vendor` filters them the same way it always did for `Route::inertia()` routes (the original starter kit's `settings/appearance` route used the identical construct). Verified via plain `route:list` that both exist and no auth-era route remains.

---

## 5. Notes for QA
- `tests/Unit/ArchitectureTest.php` passes the `php` and `security` presets with zero `ignoring()` calls — worth a spot-check that this still holds after any later-phase additions.
- `SystemStatusServiceTest`'s disconnected-database case mocks `DatabaseManager`/`Connection` directly rather than touching `database.default`, per the plan's caution about `RefreshDatabase` rollback hitting a broken connection — confirm this reasoning holds if the test suite's DB setup changes later.
- Manual verification only (cannot be automated): `composer native:dev` — window titled "MDVault" opens ~1280×800, resize is blocked below 960×600, the Workspace shows sidebar + editor + status bar reading "Desktop · sqlite · Connected", Settings → Appearance switches theme, and window size/position persist across restarts. **Not yet run by the developer or user; flagging for explicit user follow-up.**
- `resources/js/actions/**`, `resources/js/routes/**`, `resources/js/wayfinder/**` were regenerated by `wayfinder:generate --with-form`; diff is mechanical (stale Fortify/passkey/profile/security entries removed, `WorkspaceController` and `Native\Desktop` internal-route actions added).
- `.env` was updated directly (not just `.env.example`) since it is local-only and the user pre-approved this; not tracked by git so no diff appears in `git status`.

---

## 6. Fix Rounds
*(none yet)*
