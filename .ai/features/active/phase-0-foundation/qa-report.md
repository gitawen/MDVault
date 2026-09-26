# QA Report: Phase 0 — Foundation

## Metadata
- **Feature Name**: Phase 0 — Foundation (Master Plan §49)
- **Feature ID**: mdv-p0
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-09-26
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 Desktop launch | yes (window config) | `tests/Feature/NativeAppServiceProviderTest.php` (config assertions); `composer native:dev` window opening itself is a **manual** check per plan Risk 2 | ✅ (automated part); manual part not yet run — flagged for user, not a defect |
| FR-02 No authentication | yes | `tests/Feature/WorkspaceTest.php` (9-URL dataset → 404), `composer show laravel/fortify`/`laravel/passkeys` (not found), route:list, static grep for `Fortify`/`Auth::`/`auth` middleware | ✅ |
| FR-03 Clean SQLite schema | yes | `tests/Feature/DatabaseSchemaTest.php`, `WorkspaceTest` (`status.database`); confirmed directly via `laravel-boost` schema dump — only `sessions/cache/cache_locks/jobs/job_batches/failed_jobs/migrations` exist | ✅ |
| FR-04 Vue/Inertia shell | yes | `WorkspaceTest` (component + props), manual visual check pending (documented) | ✅ |
| FR-05 Tiptap renders | yes | `npm run build`/`types:check` (Tiptap compiles into `Workspace` chunk); no automated Vue component test for typing behaviour (matches plan — plan only requires build/types-check + manual) | ✅ |
| FR-06 Service architecture | yes | `tests/Feature/Services/SystemStatusServiceTest.php`, `tests/Unit/ArchitectureTest.php` (php/security presets pass with zero `ignoring()`) | ✅ |
| FR-07 Basic settings | yes | `tests/Feature/Settings/AppearanceSettingsTest.php` | ✅ |
| FR-08 Local-first runtime baseline | yes | Offline grep confirmed clean (only SVG `xmlns` + `useCurrentUrl.ts` localhost fallback); `config/inertia.php` `ssr.enabled=false`, `vite.config.ts` `inertia({ssr:false})`, `nativephp.updater.enabled` defaults false — verified by direct file read, **no dedicated Pest test asserts these config values** | ✅ (see QA-01, Low) |
| FR-09 Application identity | yes | `.env`/`.env.example` `APP_NAME=MDVault`; `config/nativephp.php` `app_id=com.mdvault.app`, `version=0.1.0` — verified by direct file read, **no dedicated Pest test asserts these config values** | ✅ (see QA-01, Low) |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact` (full suite) | 29 passed, 81 assertions, 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean, no output |
| `npm run build` | Succeeds; `Workspace` chunk includes Tiptap; `Appearance` chunk separate; no `build:ssr` script |
| `npm run check` (`vp check`) | **Fails on 113 files** — confirmed pre-existing (see QA-02, not blocking) |
| `php artisan route:list --except-vendor` | 2 rows (`/`, `settings`) — confirmed correct per deviation explanation (see below) |
| `php artisan route:list` (full) | 11 rows: `/`, `_boost/browser-logs`, `_inertia/devtools/*` (2), `_native/api/*` (2), `settings`, `settings/appearance`, `storage/{path}` (2), `up`. No Fortify/passkey/profile/security/dashboard routes. |
| `composer show laravel/fortify` / `laravel/passkeys` | Both "not found" — confirmed not installed |
| `rg -n "https?://" resources/js -g "!resources/js/{actions,routes,wayfinder}/**"` | Only `AppLogoIcon.vue` SVG `xmlns` and `useCurrentUrl.ts` `http://localhost` fallback — matches spec exactly |
| Schema dump (`laravel-boost database-schema`) | `cache, cache_locks, failed_jobs, job_batches, jobs, migrations, sessions` only — no `users`/`password_reset_tokens`/`passkeys` |
| `npm ls @tiptap/core @tiptap/vue-3 @tiptap/pm @tiptap/starter-kit` | All deduped at `3.31.3` |
| `php vendor/bin/pint --dirty --format agent` | Passed, no changes needed |
| Auth-remnant sweep (`Fortify`, `passkey`, `Auth::`, `auth` middleware, `config/auth.php`) | Only expected residue: `boost.json` still lists `fortify-development` skill (tooling config, not app code — QA-03, Low); test files reference `/two-factor-challenge`, `passkeys` only as **removed-URL assertions** (expected) |
| `bootstrap/app.php`, `bootstrap/providers.php` | Clean — no Fortify provider, no auth middleware group |

**Developer-reported deviations — assessed:**
1. **`Http::fake()` added to `NativeAppServiceProviderTest`** — **Accepted, correct.** Verified against `vendor/nativephp/desktop/src/Fakes/WindowManagerFake.php` and `Windows/Window.php`: the fake's `open()` returns a plain `Window` (not `PendingOpenWindow`), and `Window::title()` unconditionally does `$this->client->post('window/title', ...)` when `$this` is not a `PendingOpenWindow`. Without `Http::fake()` this is a real Guzzle call. The added fake doesn't weaken any assertion (`assertOpened`, `toArray()` matching) — it only neutralizes an unavoidable side effect of the vendor fake's design. No issue.
2. **`route:list --except-vendor` shows 2 rows, not 4`** — **Accepted, correct.** Confirmed via plain `route:list`: `settings/appearance` (`Route::inertia()` → `Inertia\Controller`) and `up` (framework health check) are both vendor-attributed actions and always were (the starter kit's original `appearance` route used the same `Route::inertia()` construct). No functional gap — no auth-era route remains.
3. **`npm run check` fails on ~110-113 untouched files** — **Confirmed pre-existing**, not introduced by this branch. `git diff HEAD -- vite.config.ts` shows the only change is `inertia() → inertia({ ssr: false })`; the `fmt.ignorePatterns` block (which lacks `docs/**`, `.claude/**`, `.agents/**`, `.gemini/**`, `nativephp/electron/**`, and — notably — `resources/js/{actions,routes,wayfinder}/**`, unlike the wider `lint.ignorePatterns`) is untouched. Since none of the 113 failing files were modified by this feature (confirmed via `git status`), and the config gap predates this branch, this is pre-existing tooling debt, not a Phase 0 regression. The scoped check on all files this feature authored/modified passes. Logged as QA-02 (Low, follow-up), not blocking.

Desktop launch (`composer native:dev`) — correctly listed as a manual check per the plan's own Risk 2 mitigation; not treated as a defect.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Low | MINOR | `config/nativephp.php`, `config/inertia.php` | FR-08/FR-09 config values (`nativephp.app_id`, `nativephp.version`, `inertia.ssr.enabled`, `nativephp.updater.enabled`) have no dedicated Pest assertion — only verified by manual file read this round. Values are currently correct. | A small config-assertion test (or extend `NativeAppServiceProviderTest`) to guard against regression in a later phase. | 0 |
| QA-02 | Low | MINOR | `vite.config.ts` (`fmt.ignorePatterns` vs `lint.ignorePatterns`) | `npm run check` fails formatting on 113 pre-existing/generated files (docs, `.claude/.agents/.gemini`, vendored `nativephp/electron/**`, and Wayfinder-generated `resources/js/actions|routes|wayfinder/**`) because `fmt.ignorePatterns` is narrower than `lint.ignorePatterns`. Confirmed pre-existing (config unchanged by this branch except the unrelated `ssr:false` line); every Wayfinder regen will keep triggering this. | Widen `fmt.ignorePatterns` to mirror `lint.ignorePatterns` (plus doc/agent/vendor paths) in a follow-up config task, not folded into Phase 0. | 0 |
| QA-03 | Low | MINOR | `boost.json` | Still lists the `fortify-development` Boost skill even though Fortify is fully removed from the app. Cosmetic tooling config, no functional effect. | Remove `fortify-development` from `boost.json` skills list in a follow-up. | 0 |
| QA-04 | Low | informational (no fix required) | `resources/js/components/AppSidebar.vue`, `resources/js/pages/settings/Appearance.vue` | Pre-existing starter-kit multi-root Vue templates (`<Sidebar>` + `<slot/>` as siblings; `<Head>`/`<h1>`/`<div>` as siblings) technically violate the "single root element" NFR, but neither file's structure was touched by this feature (only content/copy changed) — confirmed via `git diff`. Not a regression. | No action required for Phase 0; worth a cleanup pass whenever these files are next touched for a feature reason. | 0 |

**Severity/classification key**: see template. All four issues above are Low/MINOR, informational or trivial follow-ups — none block PASS.

---

## 4. Code Review Notes
- **Security & Authorization**: No app-level auth remains anywhere (`bootstrap/app.php`, `bootstrap/providers.php`, `AppServiceProvider`, `HandleInertiaRequests` all clean). `config/auth.php`/`config/fortify.php` correctly deleted; nothing references `config('auth.*')`. Matches ADR `local-app-without-authentication`.
- **Validation & Data Integrity**: `SystemStatusService::databaseIsConnected()`/`databaseDriver()` catch `\Exception` (covers `PDOException`/`QueryException`) and never throw — verified by the mocked-failure test. `sessions` migration keeps `user_id` nullable/indexed, no dangling FK constraint (no `->constrained()`), consistent with the plan.
- **Performance (N+1, indexes)**: `WorkspaceController` → `SystemStatusService::summary()` issues at most one `select 1`; no N+1 risk introduced in Phase 0.
- **Conventions (AGENTS.md, ADRs)**: `SystemStatusService` is `final`, suffixed `Service`, constructor-injected, no HTTP-layer dependency — enforced by `tests/Unit/ArchitectureTest.php` with zero `ignoring()` calls, matching ADR `service-layer-architecture`. `WorkspaceController` is thin (validates nothing needed, delegates to service, returns Inertia response).
- **Frontend (Inertia/Vue/Wayfinder)**: All new components (`Workspace.vue`, `StatusBar.vue`, `TiptapEditor.vue`) use Wayfinder route helpers (`workspace()`, `edit as editAppearance()`), single root elements, `<Head>` inside the root where applicable, and the Tiptap editable element carries `aria-label="Note editor"` per the NFR. Tiptap packages all resolve to `3.31.3` (verified via `npm ls`).

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/` (Level 4: route to `system-analyst` for final sign-off first, per workflow)
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: QA-01, QA-02, QA-03 (Low/MINOR, optional follow-ups — not required before completion)
- [ ] **FAIL — MAJOR** → System Analyst: none
