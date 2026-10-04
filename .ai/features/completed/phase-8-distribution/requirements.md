# Requirements: Phase 8: Distribution

## Metadata
- **Feature Name**: Phase 8: Distribution
- **Feature ID**: mdv-p8
- **Master Plan Phase**: Implements **Phase 8, Distribution** (`docs/Masterplan.md` §57). Related sections: §5, §8, §36, §58, §61, §62; Rules 1–10.
- **Author**: System Analyst
- **Created Date**: 2026-10-04
- **Task Complexity**: Level 3 — Complex Development
- **Status**: DRAFT (Awaiting user review and approval)

---

## 1. Problem Statement
MDVault v1 development is complete across foundation, storage settings, vault management, filesystem notes, editor, external change detection, backup/restore, and encryption (Phases 0–7). However, users cannot currently run MDVault as a standalone desktop product without developer tooling (`composer`, `npm`, `php`, `npx`).

Phase 8 completes the delivery of MDVault v1 by turning the codebase into a production-hardened, self-contained desktop application package for Windows (x64) with an NSIS installer, clean standalone boot semantics, offline runtime assurance, and sanitized distribution archives.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Build a standalone distributable Windows installer (NSIS) and unpacked binary for MDVault v1 via NativePHP (`native:build win x64`).
- Ensure automatic frontend asset compilation (`npm run build`) in the build lifecycle (`prebuild`).
- Prune all non-production files, developer tools, test suites, `.ai/` records, and docs from the packaged bundle via `cleanup_exclude_files`.
- Enforce production security posture: `APP_DEBUG=false`, devtools suppressed by default, sensitive environment variables stripped (`cleanup_env_keys`), and no sensitive log exposure.
- Configure installer persistence semantics: user app data (vault registry, metadata, encryption metadata) is preserved across reinstalls and updates (`delete_app_data_on_uninstall: false`).
- Verify standalone runtime execution on Windows: fresh launch without active dev servers, vault operations, note editing, markdown serialization, backups, encryption unlock/lock, and window state restoration.

### Out of Scope (Non-Goals)
- Remote auto-updater infrastructure (GitHub Releases/S3 upload endpoints, auto-polling on boot) — explicitly deferred/disabled to honour the local-first, offline architectural rule.
- Code signing with paid EV/OV certificates (Azure Trusted Signing is supported by configuration if credentials are provided in `.env`, but unsigned local executable generation is the primary milestone requirement).
- Cross-platform distribution packaging (macOS DMG / Linux AppImage) — explicitly scoped for later phases as stated in Master Plan §57.
- Synchronization, cloud accounts, or remote multi-device sharing (v2+ roadmap).

---

## 3. User Personas & Stories
- **As an** end user on Windows,
  **I want to** download and run an installer (`MDVault-Setup-1.0.0.exe`),
  **So that** I can install MDVault to my computer with a desktop shortcut and launch it like any standard native desktop app.
- **As an** end user,
  **I want** MDVault to operate 100% offline without connecting to external servers or sending telemetry,
  **So that** my notes and knowledge vaults remain private and secure on my machine.
- **As an** end user,
  **I want** my notes and vaults to remain intact if I update or reinstall MDVault,
  **So that** I never lose data or encryption keys due to an installer action.
- **As a** developer/maintainer,
  **I want** `php artisan native:build win x64` to cleanly compile frontend assets and package the PHP runtime and Electron shell into a reproducible artifact,
  **So that** releases can be produced reliably without manual intermediate steps.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Production Build Configuration | Configure `config/nativephp.php` with official production identity: `version` (`1.0.0`), `app_id` (`com.mdvault.app`), author, copyright, and description. | Given `config/nativephp.php`, When inspected, Then version is `1.0.0`, `app_id` is `com.mdvault.app`, and updater is disabled. |
| **FR-02** | Automated Prebuild Asset Hook | Integrate `npm run build` into `config/nativephp.php` `prebuild` scripts. | Given a build invocation (`php artisan native:build`), When execution begins, Then frontend assets (`public/build`) are compiled before packaging begins. |
| **FR-03** | Distribution Bundle Sanitization | Configure `cleanup_exclude_files` to exclude tests, documentation, `.ai`, `.agents`, `.gemini`, `.claude`, and dev configs from the packaged app. | Given a packaged bundle in `nativephp/electron/dist`, When inspected, Then no `tests/`, `.ai/`, `docs/`, or dev configs exist inside the packaged application folder. |
| **FR-04** | Windows NSIS Installer Generation | Build a distributable Windows x64 setup executable via `php artisan native:build win x64`. | Given `php artisan native:build win x64`, When executed, Then an NSIS setup installer is generated in `nativephp/electron/dist` with desktop shortcut support and `delete_app_data_on_uninstall: false`. |
| **FR-05** | Production Runtime Security | Packaged app runs with `APP_DEBUG=false`, devtools disabled, and no error screens exposing sensitive stack traces. | Given the packaged executable, When launched, Then window boots with DevTools closed and no stack traces exposed on unexpected errors. |
| **FR-06** | Standalone Desktop Validation | Verify end-to-end workflow on Windows without dev servers: launch, vault creation, note editing, markdown serialization, backup/restore, and encrypted vault operations. | Given the installed desktop app, When executing vault CRUD, note editing, backup, and encryption lock/unlock, Then all operations succeed and state persists across restarts. |

---

## 5. Non-Functional Requirements
- **Security & Secret Hygiene**: Production `.env` in the package must have all secrets stripped; `APP_DEBUG=false`; no plaintext keys or note text written to logs.
- **Local-First & Offline**: Application boots and runs without any network connection; zero network calls made to external domains.
- **Data Safety**: `delete_app_data_on_uninstall` is `false`; database and vaults remain safe in user directory.
- **Reliability**: Window bounds and maximized state are preserved across restarts (`rememberState()`); interrupted conversions are recovered on boot.

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4)
- Desktop Runtime: NativePHP Desktop 2.3 + Electron (electron-builder)
- Target OS: Windows (win-x64)
- Frontend: Inertia v3 + Vue 3 + Tailwind CSS v4
- Testing: Pest 5 (`php artisan test`)
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`)

---

## 7. Risks & Assumptions
- **Risk**: Windows SmartScreen or antivirus may flag unsigned executables during initial download or install.
  - **Mitigation**: Document SmartScreen bypass for developer/preview testing ("More info" -> "Run anyway") and provide configuration hooks for Azure Trusted Signing / code signing in `.env`.
- **Risk**: Stale frontend assets packaged if build step is skipped.
  - **Mitigation**: Enforce `npm run build` in `prebuild` hooks so every build is guaranteed to compile latest assets.
- **Risk**: Large bundle size due to bundled PHP runtime and Electron.
  - **Mitigation**: Exclude dev dependencies, node_modules (pruned), tests, and documentation.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases and production hardening identified
- [x] Approved to proceed to Planning (`plan.md`) (User approved 2026-10-04)
