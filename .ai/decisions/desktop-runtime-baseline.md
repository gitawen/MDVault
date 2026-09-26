# ADR: Desktop runtime baseline (NativePHP, SSR, offline)

- **Status**: Proposed (needs user approval A6 for `app_id`)
- **Date**: 2026-09-26
- **Phase**: Master Plan Phase 0 — Foundation (acceptance: "Application launches as desktop application")

## Context
- `nativephp/desktop` 2.3.1 is installed and set up: `config/nativephp.php`, `App\Providers\NativeAppServiceProvider` (a bare `Window::open()`), the Electron dependencies under `vendor/nativephp/desktop/resources/electron/node_modules`, a published `nativephp/electron/`, the `composer native:dev` script, and an existing `database/nativephp.sqlite`.
- Identity is still the placeholder: `app_id` `com.nativephp.app`, `APP_NAME=Laravel`, the default description, and the updater enabled by default with the `spaces` provider.
- When running natively, NativePHP forces `session.driver=file` and `queue.default=database`, rewrites the DB to its own SQLite file (`database/nativephp.sqlite` in debug), and exposes OS folders as disks (`documents`, `user_home`, …).
- Inertia SSR is enabled in `config/inertia.php`, and the `@inertiajs/vite` plugin auto-enables SSR. A packaged desktop app has no Node SSR server, and Tiptap (ProseMirror) depends on the DOM.
- Master Plan §5 requires the app to work with the Internet off. The starter kit's Welcome page loads a CDN stylesheet (rsms.me), and the sidebar and header link to external websites.

## Options Considered
1. **Leave the NativePHP/SSR configuration as scaffolded.** Wrong identity; the updater may try network access; wasted SSR attempts; external assets break offline use.
2. **Full packaging configuration now (icons, installer, signing, updater provider).** That is Phase 8 (Distribution) scope.
3. **Minimal runtime baseline (chosen).** Correct identity, a sensible main window, SSR off, updater off by default, no external runtime URLs.

## Decision
- **Identity**:
  - `APP_NAME=MDVault` (drives the window title and UI).
  - `nativephp.app_id` default `com.mdvault.app`, pending user approval. It is treated as permanent because it determines the installed app's data directory.
  - `nativephp.version` default `0.1.0` (pre-release).
  - Description "Local-first Markdown knowledge vault".
- **Main window** (`NativeAppServiceProvider::boot()`): `Window::open()` with title `config('app.name')`, 1280×800, minimum 960×600, `rememberState()`. It is covered by a Pest test using `Window::fake()`.
- **Updater**: `nativephp.updater.enabled` defaults to `false` (env-overridable). Phase 8 decides the distribution and update strategy.
- **SSR**: disabled in both layers, `inertia({ ssr: false })` in `vite.config.ts` and `'ssr.enabled' => false` in `config/inertia.php`; the `build:ssr` script is removed. MDVault is client-rendered inside Electron.
- **Offline UI**: no runtime `http(s)://` resources or navigation targets in hand-written frontend code. Fonts stay self-hosted via laravel-vite-plugin `@fonts`, which is resolved at build time.
- **Databases**: browser dev uses `database/database.sqlite`; native dev uses `database/nativephp.sqlite` (managed by `native:migrate` / `native:migrate:fresh`); tests use in-memory SQLite. NativePHP runs pending migrations on native startup.

## Consequences
- **Positive**: The app launches as "MDVault" with a usable window and no network dependency at runtime. There is one rendering path (client-side), with no SSR/DOM conflicts for Tiptap.
- **Negative / trade-offs**:
  - Desktop launch can only be fully verified manually (`composer native:dev`).
  - Browser dev (Herd) and native dev use different SQLite files, so data differs between them.
  - Changing `app_id` after users install the app would orphan their app data.
- **Follow-ups**:
  - Phase 1 `StoragePathService` should use NativePHP's `documents` disk / `NATIVEPHP_DOCUMENTS_PATH` when running natively, with a platform-neutral fallback in browser dev (§8).
  - Phase 8 revisits the updater, icons, installer (NSIS) and signing.
