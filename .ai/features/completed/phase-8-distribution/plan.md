# Plan: Phase 8: Distribution

## Metadata
- **Feature Name**: Phase 8: Distribution
- **Feature ID**: mdv-p8
- **Author**: System Analyst
- **Created Date**: 2026-10-04
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: COMPLETE

---

## 1. Summary
Phase 8 delivers the standalone, production-hardened desktop distribution of MDVault v1 for Windows (x64). We configure the NativePHP distribution pipeline to automatically compile frontend assets, sanitize the packaged bundle against non-production assets and sensitive environment keys, generate an NSIS installer with safe data-retention defaults, enforce production security posture (`APP_DEBUG=false`), and verify the application end-to-end against the Master Plan release criteria.

---

## 2. Architecture & Design
- **Approach**:
  - Leverage `nativephp/desktop` 2.3 with its embedded Electron + PHP runtime pipeline.
  - Wire frontend asset compilation directly into `config/nativephp.php`'s `'prebuild'` hook (`npm run build`), guaranteeing that assets in `public/build` are always fresh when packaging.
  - Extend `'cleanup_exclude_files'` to rigorously strip development, testing, documentation, and agent folders from the packaged Electron application bundle.
  - Retain user application data across reinstalls (`delete_app_data_on_uninstall: false`).
  - Disable auto-updaters by default to strictly adhere to the offline, local-first architecture.
- **Alternatives Considered**:
  - *Manual build step*: Relying on developers to run `npm run build` before `native:build` was rejected as error-prone.
  - *Background auto-updater*: Rejected for v1 to prevent unwanted network calls, maintaining 100% offline trustworthiness.
- **Decision Records**:
  - `.ai/decisions/desktop-runtime-baseline.md` (accepted foundation)
  - `.ai/decisions/desktop-distribution-packaging.md` (Phase 8 packaging baseline)

### Data Model Changes
*None. Phase 8 operates purely at the packaging, build configuration, and distribution layer.*

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Config | `config/nativephp.php` | Production application identity, prebuild hook, build exclusions, updater configuration |
| Test | `tests/Feature/PackagingConfigurationTest.php` | Automated regression test verifying production configuration, build hooks, and exclusion patterns |

### Frontend Components
*None modified. Frontend build artifacts are generated into `public/build/` via `npm run build`.*

### Routes
*None added or modified.*

---

## 3. Implementation Tasks

- [ ] **T1 — Production Packaging & Exclusion Configuration**
  - Files: `config/nativephp.php`
  - Details:
    - Update `version` to `env('NATIVEPHP_APP_VERSION', '1.0.0')`.
    - Update `author` to `env('NATIVEPHP_APP_AUTHOR', 'MDVault')`.
    - Update `copyright` to `env('NATIVEPHP_APP_COPYRIGHT', 'Copyright © 2026 MDVault')`.
    - Enable `'npm run build'` in `'prebuild'`.
    - Add to `'cleanup_exclude_files'`:
      - `'tests'`, `'tests/**'`
      - `'.ai'`, `'.ai/**'`
      - `'.agents'`, `'.agents/**'`
      - `'.gemini'`, `'.gemini/**'`
      - `'.claude'`, `'.claude/**'`
      - `'docs'`, `'docs/**'`
      - `'phpunit.xml'`, `'phpstan.neon'`, `'pint.json'`, `'components.json'`
    - Confirm `delete_app_data_on_uninstall` remains `false`.
  - Covers: [FR-01, FR-02, FR-03]

- [ ] **T2 — Packaging Configuration Tests & Linting**
  - Files: `tests/Feature/PackagingConfigurationTest.php`
  - Command: `php artisan make:test --pest PackagingConfigurationTest --no-interaction`
  - Details:
    - Assert `config('nativephp.version') === '1.0.0'`.
    - Assert `config('nativephp.app_id') === 'com.mdvault.app'`.
    - Assert `config('nativephp.updater.enabled') === false`.
    - Assert `config('nativephp.prebuild')` contains `'npm run build'`.
    - Assert `config('nativephp.cleanup_exclude_files')` contains tests, `.ai`, `docs`, and config exclusions.
    - Run Pint formatting (`vendor/bin/pint --dirty --format agent`).
  - Covers: [FR-01, FR-02, FR-03, FR-05]

- [ ] **T3 — Execute Windows Distribution Build (`native:build win x64`)**
  - Files: `nativephp/electron/dist/`
  - Command: `php artisan native:build win x64 --no-interaction`
  - Details:
    - Triggers the complete build pipeline: prebuild asset compilation, Electron dependency resolution, PHP binary extraction, file copy & pruning, and electron-builder NSIS packaging.
    - Verify generation of the NSIS setup installer (`mdvault-1.0.0-setup.exe` or `MDVault-1.0.0-setup.exe`) and unpacked binaries in `nativephp/electron/dist/`.
  - Covers: [FR-04, FR-05]

- [ ] **T4 — Desktop Verification Protocol (QA & Release Gate)**
  - Files: `.ai/features/active/phase-8-distribution/qa-report.md`
  - Details:
    - Execute manual desktop verification matrix as specified in Master Plan §57 and Phase 7 release conditions:
      1. Launch standalone packaged app without dev server.
      2. Verify DevTools are closed and window restores state (`rememberState()`).
      3. Create vault, create note, edit note, format markdown, close, and reopen.
      4. Backup vault and restore backup.
      5. Create/unlock encrypted vault; verify password unlock and auto-lock.
      6. Verify log hygiene (`storage/logs/laravel.log` contains no sensitive keys or note contents).
  - Covers: [FR-06]

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/PackagingConfigurationTest.php` | Validates production config, updater disabled, prebuild hook, and exclusion rules | FR-01, FR-02, FR-03 |
| `tests/Feature/NativeAppServiceProviderTest.php` | Validates window defaults, sizing constraints, and crash recovery trigger | FR-05 |
| Full Test Suite (`php artisan test --compact`) | Ensures all 130+ unit, feature, filesystem, and security tests pass cleanly | Regression safety |

**Test scope for QA**:
- `php artisan test --compact`
- `vendor/bin/phpstan analyse`
- `npm run types:check`
- Inspection of `nativephp/electron/dist` output artifacts and bundle contents.

---

## 5. Risks & Mitigations
- **Risk**: Build failure during PHP binary extraction or electron-builder pack.
  - **Mitigation**: Pre-verified local presence of `vendor/nativephp/php-bin/bin/win/x64/php-8.4.zip` and verified working `npm run build`.
- **Risk**: User data loss on app uninstall.
  - **Mitigation**: `delete_app_data_on_uninstall` explicitly configured to `false` and covered by automated test.
- **Risk**: Sensitive stack traces rendered if unhandled errors occur.
  - **Mitigation**: Enforce `APP_DEBUG=false` in production build environment.

---

## 6. Open Questions
*None. Master Plan §57 and prior ADRs provide clear, decisive direction for Windows distribution.*

---

## 7. Revision Log
| Rev | Date | Author | Description |
|---|---|---|---|
| 1 | 2026-10-04 | System Analyst | Initial draft of requirements and plan for Phase 8 Distribution |
