# ADR: Storage root resolution and change semantics

- **Status**: Proposed (Phase 1 plan, pending user approval B1)
- **Date**: 2026-09-27
- **Phase**: Master Plan Phase 1 — Storage and Settings (§8, §43, §50, §60 Rule 5, §61 Rule 11)

## Context
§8 says to default the storage root to the user's `Documents/MDVault` on Windows, macOS and Linux, never hardcode platform paths, use a `StoragePathService`, and let the user change the root.

Under NativePHP 2.3 the Electron host passes `NATIVEPHP_DOCUMENTS_PATH` (= `app.getPath('documents')`, which honours OneDrive redirection and XDG). `NativeServiceProvider::configureDisks()` exposes it at runtime as `filesystems.disks.documents.root`. In browser dev (Herd) no such variable exists.

A packaged NativePHP app may run with a config cache built on the developer's machine. There are no vaults yet; §11 says each vault will store its own absolute `path`.

The user needs to pick a folder. NativePHP offers `Native\Desktop\Dialog` (an HTTP call to Electron's `showOpenDialog`, not available in the browser).

## Options Considered
1. **Resolve Documents via `config/*.php` env values (`HOME`, `USERPROFILE`).** Rejected: `config:cache` at build time bakes the builder's home directory into the distributed app.
2. **Read OS env directly inside `StoragePathService`.** Works, but it can't be tested without mutating process globals.
3. **A `UserDirectories` contract with a runtime system implementation (chosen).** The service depends on the contract; tests bind a temp-dir fake. This is the "OS boundary that tests must substitute" case anticipated by ADR `service-layer-architecture`.
4. **Folder choice UI: native dialog only.** Rejected: unusable and untestable in browser dev.
5. **Folder choice UI: text input only.** Rejected: a poor desktop experience.
6. **Moving existing data when the root changes.** Rejected: there is nothing to move in Phase 1. Moving vaults is `VaultService` scope (§41, Phase 2+).

## Decision
- **Contract**: `App\Contracts\UserDirectories::documentsPath(): ?string`.
  - Implementation `App\Support\SystemUserDirectories(config, getenv(), PHP_OS_FAMILY)`, bound in `AppServiceProvider`. Resolution order:
    1. `filesystems.disks.documents.root` (NativePHP runtime);
    2. Windows: `USERPROFILE`, then `HOMEDRIVE`+`HOMEPATH`, then `HOME`; other OSes: `HOME`; + `Documents`;
    3. `null`.
  - It never creates anything.
- **`StoragePathService`**:
  - Default root = `(documentsPath() ?? storage_path('app')) / MDVault`. It is computed at runtime, **never persisted**, and **not created** just by being displayed. It follows the runtime: native vs browser dev may differ.
  - Effective root = `storage.root_path` setting, or the default.
  - `changeRoot(path)`:
    - normalise (trim, separators, trailing separator);
    - reject empty/NUL, non-absolute (platform-aware: drive/UNC on Windows, `/` elsewhere) and existing files;
    - create recursively if missing;
    - probe writability with a real temp file (random name via `Str::random`, always deleted);
    - canonicalise with `realpath`;
    - persist. Choosing the default path forgets the setting.
  - Errors are `InvalidStorageRootException` with user-facing messages. Controllers convert them to validation errors on `root_path`.
  - Order: filesystem first, database second (§43). The only possible inconsistency is an empty created directory, which is harmless.
  - `resetToDefault()` forgets the setting and creates nothing.
- **Change semantics**: the storage root is the **parent directory for vaults created in the future**. Changing or resetting it never moves, copies, renames or deletes user data. Existing vaults (Phase 2+) keep their own absolute `path`. Relocating a vault is an explicit `VaultService` operation.
- **Folder picking**:
  - The text input with server-side validation works in every runtime.
  - In the desktop runtime a "Choose folder…" button calls `POST /settings/storage/browse`. `NativeDialogService::chooseDirectory()` opens the OS picker (`openDirectory`, `createDirectory`, default path = current root if it exists), and the result goes through the same `changeRoot`.
  - The endpoint returns 404 unless `nativephp-internal.running`.
  - Only `NativeDialogService` may use `Native\Desktop\Dialog` (arch rule). It calls `Dialog::new()` per call so `Http::fake()` works in tests.

## Consequences
- **Positive**:
  - No hardcoded platform paths.
  - Correct native Documents (including redirection).
  - Fully testable with temp directories.
  - Tests can never touch the real Documents folder.
  - The root is validated at the point of choice.
  - There is no data-moving risk in Phase 1.
- **Negative / trade-offs**:
  - In browser dev the Documents guess may differ from a redirected Documents folder (dev-only).
  - The native dialog and the true Documents path can only be verified manually on the desktop.
  - A stored custom root can later disappear (e.g. an unplugged drive). Phase 1 only reports `exists`/`writable`; Phase 2 must handle a missing root when creating vaults (§7 "Detect missing Vault directories").
  - Dialog requests block for as long as the picker is open.
- **Follow-ups**:
  - Phase 2 `VaultService` creates vault directories under `StoragePathService::rootPath()`, re-validating it at creation time.
  - Phase 3 `FileStorageService` may absorb the generic directory-creation and write-probe helpers. `StoragePathService` would then delegate to it.
