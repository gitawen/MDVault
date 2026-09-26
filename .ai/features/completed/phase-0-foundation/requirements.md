# Requirements: Phase 0 — Foundation

## Metadata
- **Feature Name**: Phase 0 — Foundation (Master Plan §49)
- **Feature ID**: mdv-p0
- **Author**: System Analyst
- **Created Date**: 2026-09-26
- **Task Complexity**: Level 4 — Architectural
- **Status**: UNDER REVIEW (needs user approvals A1–A7 in `plan.md` §6)

---

## 1. Problem Statement
MDVault is currently an unmodified Laravel 13 Vue starter kit. It has Fortify login/registration, passkeys, 2FA, a `users` table, marketing pages that load assets from external hosts, and placeholder NativePHP identity. The Master Plan (§1, §5, §64) says v1 is a local-first, offline, single-user desktop app with **no user accounts or authentication server**. Phase 0 (§49) must set up the technical foundation (NativePHP runtime, SQLite, Vue/Inertia shell, Tiptap rendering, service architecture) so Phases 1–8 can build on it, without implementing any product feature early.

Master Plan §49 acceptance criteria:
1. Application launches as desktop application.
2. SQLite database works.
3. Vue/Inertia interface works.
4. Tiptap can render inside the application.
5. Basic Laravel service architecture exists.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Make NativePHP launch MDVault as a desktop window with the right identity (name, app ID, window size).
- Remove authentication completely (packages, routes, pages, tables, tests) so the app opens straight to its workspace (ADR `local-app-without-authentication`).
- Set a clean SQLite baseline schema with framework tables only.
- Establish routing (named routes + Wayfinder) and the application layout: sidebar, header, content area and status bar, following Master Plan §36.
- Install Tiptap and render an editable Tiptap editor (StarterKit) with demo content in the workspace.
- Establish the service layer convention (`app/Services/`), one working foundation service (`SystemStatusService`), and Pest architecture tests that enforce the layering (ADR `service-layer-architecture`).
- Basic settings: keep the existing Appearance (light/dark/system) page, available without login.
- Local-first baseline: no runtime requests to external hosts from the UI, SSR disabled, NativePHP updater off by default (ADR `desktop-runtime-baseline`).

### Out of Scope (Non-Goals)
- `settings` table, `SettingsService`, `StoragePathService`, storage root selection (Phase 1).
- `vaults` table/model, vault CRUD (Phase 2). The sidebar shows only an empty "Vaults" placeholder.
- `notes` table, `FileStorageService`, `FileHashService`, `VaultIndexService` (Phase 3).
- Markdown parse/serialize, editor toolbar, saving/autosave (Phase 4). The Phase 0 editor content is demo HTML and is never persisted.
- File watching, backup, encryption, packaging/distribution (Phases 5–8).
- Empty stub classes or interfaces for future services.
- Any sync/sharing/collaboration/account infrastructure (§45–47, §64).

---

## 3. User Personas & Stories
- **As a** local MDVault user, **I want to** launch MDVault as a desktop application, **so that** I can use it like any native note-taking app, with no browser or server setup.
- **As a** local MDVault user, **I want** the app to open directly to my workspace without logging in, **so that** a single-user offline app has no pointless account barrier.
- **As a** local MDVault user, **I want to** see a rich-text editor in the workspace, **so that** I can confirm the editing surface works (persistence comes in Phase 4).
- **As a** local MDVault user, **I want to** choose light, dark or system appearance, **so that** the app matches my environment.
- **As a** MDVault developer, **I want** an enforced service-layer convention, **so that** Phases 1–8 put filesystem, database and crypto logic in services, never in controllers or Vue.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Desktop launch | When NativePHP boots, `NativeAppServiceProvider::boot()` opens the main window titled with `config('app.name')` (MDVault), 1280×800 by default, minimum 960×600, remembering its state between launches. | Given the NativePHP runtime boots, When `NativeAppServiceProvider::boot()` runs, Then window `main` is opened with title `MDVault`, width 1280, height 800, minWidth 960, minHeight 600 and rememberState true. Given the user runs `composer native:dev`, Then a desktop window shows the Workspace (manual check). |
| **FR-02** | No authentication | All Fortify, passkey, 2FA, profile and password features are removed. The workspace and settings need no login. | Given no session, When GET `/`, Then 200 and the Workspace page renders. When GET any of `/login`, `/register`, `/forgot-password`, `/two-factor-challenge`, `/user/confirm-password`, `/dashboard`, `/settings/profile`, `/settings/security`, `/.well-known/passkey-endpoints`, Then 404. `laravel/fortify` and `laravel/passkeys` are not installed. |
| **FR-03** | SQLite works with a clean baseline schema | Migrations run on SQLite. The schema holds only framework tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations`). No `users`, `password_reset_tokens` or `passkeys`. The live connection is reported to the UI. | Given a freshly migrated SQLite DB, Then `sessions`, `cache`, `jobs` exist and `users`, `password_reset_tokens`, `passkeys` do not. When the Workspace loads, Then prop `status.database.driver` = `sqlite` and `status.database.connected` = true. |
| **FR-04** | Vue/Inertia application shell | The Workspace page renders through Inertia inside the app layout: sidebar (logo, "Workspace" nav item, empty "Vaults" group, "Settings" link in the footer), header with breadcrumbs, main content, and a status bar showing runtime, database status, app name and version. | Given GET `/`, Then the Inertia component is `Workspace` with a `status` prop of shape `{application, version, runtime, database:{driver, connected}}`. Visually (manual), the sidebar, header, editor area and status bar are visible. |
| **FR-05** | Tiptap renders | A reusable `TiptapEditor` Vue component (Tiptap v3 + StarterKit) renders editable demo content in the Workspace. Content is not saved. | Given the Workspace is open, Then a Tiptap editor shows the demo heading, paragraph and list, and typing changes the editor content (manual). `npm run build` and `npm run types:check` succeed with Tiptap installed. |
| **FR-06** | Service architecture exists | `app/Services/` holds application services. `SystemStatusService` reports app name, version, runtime (`desktop`/`browser`) and database driver/connectivity, and never throws on a DB failure. `WorkspaceController` gets it by constructor/method injection. Architecture tests enforce the conventions. | Given the arch tests run, Then every `App\Services` class is final and ends in `Service`; `App\Services` does not use `App\Http`, `Illuminate\Http` or `Inertia`; `App\Http` does not use the `File`/`Storage` facades or raw filesystem functions. Given the DB is unreachable, When `summary()` is called, Then `database.connected` is false and no exception escapes. Given `nativephp-internal.running` is true, Then `runtime` is `desktop`, otherwise `browser`. |
| **FR-07** | Basic settings | `/settings` redirects to `/settings/appearance`, which renders the existing appearance switcher (light/dark/system), with no login. The Settings nav lists only Appearance. | Given no session, When GET `/settings`, Then redirect to `/settings/appearance`. When GET `/settings/appearance`, Then 200 with component `settings/Appearance`. |
| **FR-08** | Local-first runtime baseline | The UI makes no requests to external hosts at runtime (no CDN fonts or stylesheets, no external nav links). Inertia SSR is disabled in both the Vite plugin and the Laravel adapter. The NativePHP updater is disabled by default. | Given the hand-written frontend sources (`resources/js` excluding generated `actions/`, `routes/`, `wayfinder/`), Then no `http(s)://` URL remains except SVG `xmlns` and the existing `http://localhost` fallback in `useCurrentUrl.ts`. `config('inertia.ssr.enabled')` is false. `config('nativephp.updater.enabled')` is false unless `NATIVEPHP_UPDATER_ENABLED=true`. |
| **FR-09** | Application identity | `APP_NAME=MDVault`; NativePHP `app_id` = `com.mdvault.app` (pending approval A6), description set, version default `0.1.0`; frontend title fallback `MDVault`. | Given the default env, Then `config('nativephp.app_id')` = `com.mdvault.app` and `config('nativephp.version')` = `0.1.0`; `.env.example` has `APP_NAME=MDVault`. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**: No application authentication (ADR `local-app-without-authentication`). When running natively, NativePHP's `PreventRegularBrowserAccess` middleware (secret cookie/header) blocks non-Electron clients from the local server. No secrets or keys are committed; `.env` stays local.
- **Performance**: The status check runs one `select 1` per Workspace request. No other queries in Phase 0.
- **Accessibility & UX**: Every Vue component has a single root element (`<Head>` goes inside the root). The editor's editable element has `aria-label="Note editor"`. The sidebar collapses (existing shadcn sidebar). Dark mode works on all new components.
- **Reliability & Data Integrity**: `SystemStatusService` degrades gracefully (reports disconnected rather than throwing). Migrations are the only schema source.
- **Quality gates**: Pint clean; PHPStan level 7 (`vendor/bin/phpstan analyse`) passes; `npm run types:check`, `npm run check` (vite-plus lint/format, warnings denied) and `npm run build` pass; the full Pest suite passes.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13.33 (PHP 8.4)
- Desktop runtime: NativePHP desktop 2.3.1 (Electron driver)
- Frontend: Inertia v3 (laravel 3.3.4 / vue3 3.7.1) + Vue 3.5 + Tailwind CSS v4.3 + shadcn-vue (reka-ui)
- Editor: Tiptap v3 (`@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/starter-kit` 3.31.3; the three must share a version)
- Routing: Laravel Wayfinder 0.1.21 (`@/actions/`, `@/routes/`; Vite plugin with `formVariants: true`)
- Testing: Pest 5.2 (+ arch plugin 5.0)
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`); frontend: vite-plus `vp check`
- Database: SQLite (`database/database.sqlite` in browser dev; `database/nativephp.sqlite` when running via NativePHP in debug mode; in-memory for tests)
- Master Plan: `docs/Masterplan.md` §49 (Phase 0), §5, §36, §40–42, §60, §64

---

## 7. Risks & Assumptions
- **Assumption 1**: "Basic settings" in §49 means the application-level configuration baseline (identity, runtime config) plus the existing client-side Appearance preference page. The persisted `settings` table and `SettingsService` are Phase 1 (§50 explicitly assigns them there). *User to confirm.*
- **Assumption 2**: There is no production user data. Editing the base migrations and wiping the local dev databases is acceptable (approval A5).
- **Assumption 3**: "Tiptap can render" means an editable editor with demo content. No Markdown and no persistence.
- **Risk 1 & Mitigation**: The project is not under version control, and Phase 0 deletes about 50 files. Mitigation: user runs `git init` and commits before implementation (approval A7).
- **Risk 2 & Mitigation**: Desktop launch can't be verified headlessly. Mitigation: automated test of the window configuration via `Window::fake()`, plus a documented manual `composer native:dev` check.
- **Risk 3 & Mitigation**: Removing Fortify in the wrong order breaks `package:discover` during `composer remove`. Mitigation: the plan orders code deletion before package removal.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified (DB unreachable, removed auth URLs, native vs browser runtime)
- [ ] Approved to proceed to Planning (`plan.md`) — pending user approvals A1–A7
