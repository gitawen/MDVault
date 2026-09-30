# ADR: Service layer architecture

- **Status**: Accepted (A4 approved 2026-09-26; enforced by tests/Unit/ArchitectureTest.php; analyst sign-off 2026-09-27)
- **Date**: 2026-09-26
- **Phase**: Master Plan Phase 0 — Foundation (acceptance: "Basic Laravel service architecture exists")

## Context
Master Plan §19, §40–42 and §60 Rule 5 require all filesystem, database, path, hashing, backup and encryption logic to live in application services. It proposes `app/Services/{Vault,Note,FileStorage,StoragePath,Settings,Backup,Encryption,FileHash,VaultIndex,Markdown}Service.php` and allows adjustment "to Laravel conventions". Vue components do presentation only and talk to Laravel through Inertia.

Phase 0 asks for "foundational services/interfaces" but says "do not implement major features yet". Every listed service belongs to a later phase: Settings/StoragePath → 1, Vault → 2, Note/FileStorage/FileHash/VaultIndex → 3, Markdown → 4, Backup → 6, Encryption → 7. No `app/Services` or `app/Contracts` directory exists yet. Pest 5 with the arch plugin is installed.

## Options Considered
1. **Create all 10 services now as empty classes or classes whose methods throw "not implemented".** It looks complete, but the signatures would be guesses and likely wrong. It is speculative code that breaks "don't implement major features yet" and gives a false sense of progress.
2. **Create interfaces for all 10 services (in `app/Contracts/`) plus container bindings.** Interfaces with no implementation can't be bound or tested; they are design documents in code that will churn.
3. **Establish the convention, enforce it with tests, and add one real foundation service Phase 0 actually needs (chosen).**

## Decision
Option 3.

**Conventions (binding for Phases 1–8):**
1. **Location & naming**: application services live flat in `app/Services/` (namespace `App\Services`). Class names end in `Service`, and classes are `final`. Sub-folders only if a domain grows past about 3 collaborating classes; that is decided in that phase's plan.
2. **Dependencies**: constructor injection with promoted `private readonly` properties. Services that do I/O (filesystem, clock, process) take injectable collaborators (e.g. `Illuminate\Filesystem\Filesystem`, `Illuminate\Contracts\Config\Repository`, `DatabaseManager`) so tests can use real temp directories or substitutes. No static helpers or service locators inside services.
3. **Layering**: `Vue → Inertia → Controller / Form Request → Service → Filesystem / SQLite`.
   - Services never depend on the HTTP layer (`App\Http`, `Illuminate\Http`, `Inertia`).
   - Controllers stay thin: validate (Form Request), call a service, return a response.
   - Controllers never use the `File`/`Storage` facades or raw filesystem functions.
4. **Interfaces**: introduce an interface in `app/Contracts/` only when there is (a) a real second implementation (e.g. plaintext vs encrypted storage in Phase 7), or (b) an OS boundary that tests must substitute (likely a platform path resolver in Phase 1). Bind interfaces in `AppServiceProvider::register()`. Concrete services need no binding: the container auto-resolves them. Use `singleton` only for services that hold legitimate per-process state.
5. **Errors**: services report expected failures with domain exceptions (introduced by the phase that needs them, per Master Plan §43). Filesystem and DB results are never assumed to succeed together.
6. **Enforcement**: `tests/Unit/ArchitectureTest.php` (Pest arch) asserts:
   - `php` and `security` presets;
   - `App\Services` classes are final and end in `Service`;
   - `App\Services` does not use `App\Http`, `Illuminate\Http` or `Inertia`;
   - `App\Http` uses none of the `File`/`Storage` facades or `file_put_contents`, `file_get_contents`, `fopen`, `unlink`, `mkdir`, `rmdir`, `rename`, `copy`, `scandir`.

   Later phases extend this file (e.g. "only `EncryptionService` uses `sodium_*`").

**Phase 0 service**: `App\Services\SystemStatusService`.
- `summary()` returns `array{application, version, runtime: 'desktop'|'browser', database: array{driver, connected}}`.
- It never throws on a DB failure.
- `WorkspaceController` consumes it, and the status bar displays it.

It is the working proof of the layering and the runtime evidence for "SQLite database works". It is not one of the Master Plan's feature services, and it does not overlap with them.

## Consequences
- **Positive**: The architecture exists, is tested, and is enforced automatically from day one. No speculative APIs. Each later phase designs its service against real requirements.
- **Negative / trade-offs**: `app/Services/` starts with a single class; the Master Plan's service list only materialises phase by phase. Arch tests need occasional `ignoring()` entries, and each must be justified in the phase's `implementation.md`.
- **Follow-ups**: Phase 1 adds `SettingsService` and `StoragePathService`, and probably the first contract (a platform path resolver using NativePHP's `documents` disk / `NATIVEPHP_DOCUMENTS_PATH` when running natively).
  - Phase 6: `ZipArchive` only in `ArchiveService`; `BackupService` uses no raw filesystem functions.
