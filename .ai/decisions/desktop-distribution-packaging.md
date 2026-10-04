# ADR: Desktop distribution and packaging baseline (Windows, NSIS, production hardening)

- **Status**: Proposed
- **Date**: 2026-10-04
- **Phase**: Master Plan Phase 8 — Distribution (`docs/Masterplan.md` §57)
- **Extends / Resolves Follow-ups from**: `desktop-runtime-baseline.md`

---

## Context
- MDVault v1 has completed Phases 0 through 7 (Foundation, Settings/Storage, Vault Management, Markdown Filesystem, Tiptap Editor, Filesystem Intelligence, Backup/Restore, and Encryption).
- Phase 8 is the final milestone in the v1 Master Plan: producing a distributable desktop application for the primary target platform: **Windows (x64)**.
- `nativephp/desktop` 2.3.1 is installed and integrated with Electron (`electron-builder`).
- In `desktop-runtime-baseline.md`, several items were explicitly deferred to Phase 8:
  1. Updater provider and configuration.
  2. Installer (NSIS) options and app data retention.
  3. Prebuild asset compilation pipelines.
  4. Packaging file cleanup and exclusion rules.
  5. Production security posture (`APP_DEBUG=false`, error leak prevention, devtools suppression).

---

## Options Considered

### 1. Auto-updater & Network Release Channel
- **Option A**: Configure remote updater via GitHub Releases or S3/Spaces with auto-check on startup.
- **Option B**: Keep updater disabled (`'enabled' => false`) for v1 standalone release; distribute standalone NSIS setup installers.
- **Chosen**: **Option B**. In accordance with Master Plan Rule 10 ("Don't prematurely implement synchronization") and ADR `desktop-runtime-baseline.md` ("The application should remain useful and fully functional without requiring Internet connectivity"), v1 is strictly local-first and offline. Users install updates manually by running updated installers, with no background network calls or analytics.

### 2. Application Data Retention on Uninstall
- **Option A**: Set `delete_app_data_on_uninstall => true`.
- **Option B**: Set `delete_app_data_on_uninstall => false`.
- **Chosen**: **Option B**. User vaults, note files, encryption keys, and SQLite application state must never be destructively wiped if a user reinstalls or upgrades MDVault.

### 3. Frontend Bundling in Build Pipeline
- **Option A**: Require developers to remember running `npm run build` manually before invoking `php artisan native:build`.
- **Option B**: Wire `npm run build` directly into `config/nativephp.php`'s `'prebuild'` array.
- **Chosen**: **Option B**. Automating `npm run build` in the prebuild hooks guarantees that production assets in `public/build` are always fresh, compiled, and synchronized with current components before electron-builder packages the bundle.

### 4. Build Exclusion & Production Secret Hygiene
- Development artifacts, automated test suites, documentation, and agent orchestration directories must not be included inside the distributed Electron package.
- `cleanup_exclude_files` will explicitly prune:
  - `tests/**`
  - `.ai/**`
  - `.agents/**`
  - `.gemini/**`
  - `.claude/**`
  - `docs/**`
  - Development dotfiles and config files (`phpunit.xml`, `phpstan.neon`, `pint.json`).
- `cleanup_env_keys` will strip sensitive internal keys, and packaged runtime will default `APP_DEBUG=false`.

---

## Decision
1. **Target**: Windows x64 NSIS installer (`MDVault-Setup-1.0.0.exe` / `mdvault-1.0.0-setup.exe`) and portable/unpacked distribution.
2. **Identity**:
   - `app_id`: `com.mdvault.app`
   - `name`: `MDVault`
   - `version`: `1.0.0` (matching v1 milestone)
   - `author`: `MDVault Team`
   - `copyright`: `Copyright © 2026 MDVault`
   - `description`: `Local-first Markdown knowledge vault`
3. **Packaging Configuration**:
   - Enable `npm run build` in `config/nativephp.php` `'prebuild'`.
   - Configure comprehensive exclusions in `config/nativephp.php` `'cleanup_exclude_files'`.
   - Maintain `delete_app_data_on_uninstall: false`.
4. **Production Security**:
   - Enforce `APP_DEBUG=false` for production builds so stack traces, environment details, and sensitive request payloads are never rendered in error screens.
   - DevTools are disabled by default in production.
5. **Distribution Validation**:
   - Execute test verification matrix on clean Windows environment: install, launch, vault CRUD, note editor, backup/restore, encryption unlock/lock, crash recovery, and restart persistence.

---

## Consequences
- **Positive**:
  - Clean, secure, and self-contained Windows executable and NSIS installer.
  - Zero accidental leak of development docs, tests, or AI artifacts into user machines.
  - Consistent build output that can be rebuilt deterministically with a single command (`php artisan native:build win x64`).
- **Negative / Trade-offs**:
  - Windows code signing requires an Azure or EV certificate which may produce Windows SmartScreen prompts on unsigned developer binaries unless signed.
  - Build times include compiling the full frontend bundle and packaging the PHP runtime and Electron shell.
