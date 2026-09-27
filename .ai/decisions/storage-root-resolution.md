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
- **Chosen-folder subfolder (post-QA change, requested by user, 2026-09-27)**: whichever folder the user picks — via the native dialog or the text input — `changeRoot()` appends `StoragePathService::FOLDER_NAME` (`MDVault`) to it, unless the chosen folder's own basename already equals `MDVault` (case-insensitive), so it is never doubled. This happens in a single place inside `changeRoot()`, right after the normalise/absolute checks and before the is-file/create/probe steps, so both entry points (`update`, `browse`) get it. A drive root (`D:\`) becomes `D:\MDVault`. The rest of `changeRoot()`'s semantics (create-if-missing, write-probe, "choosing the default forgets the setting") are unchanged and apply to the now-suffixed path.
- **Configurable folder name (second post-QA change, requested by user, 2026-09-27)**: the hardcoded `MDVault` subfolder name became user-configurable. A new `SettingKey::StorageFolderName` (`storage.folder_name`, group storage, type string, default `StoragePathService::FOLDER_NAME`) is read and written only through `SettingsService`, exactly like `StorageRootPath`. `StoragePathService::folderName()` returns the stored value or the default. `defaultRootPath()` now reads `folderName()` instead of the `FOLDER_NAME` constant directly, so the default becomes `Documents/<folder name>`. `changeRoot(string $location, ?string $folderName = null)` takes an optional second argument: the effective name is `$folderName ?? $this->folderName()`, so every existing single-argument call site (tests, and any future internal caller) keeps behaving exactly as before by implicitly reusing the current folder name. The effective name is validated by a new `assertValidFolderName()` guard (see below) before anything touches the filesystem. The "basename already matches" convenience rule, and all create/probe/canonicalise/persist semantics, are otherwise unchanged, just parameterised by name instead of the constant. Persistence order matters: the folder name is written (or forgotten, if it equals the `MDVault` default) *before* the root is compared against `defaultRootPath()`, so that comparison reflects the new name. `resetToDefault()` deliberately still only forgets `StorageRootPath` — the folder name is a separate, independently-persisted setting and is never touched by a location reset, so "reset to default" means "Documents/\<current folder name\>", not "Documents/MDVault" for a user who has already customised the name. `summary()` gained two read-only fields for the UI: `location` (`dirname($root_path)`) and `folder_name` (`folderName()`).
- **Folder-name validation (same change)**: `StoragePathService::assertValidFolderName()` is the single source of truth, called from `changeRoot()` and reused by `UpdateStorageRootRequest`'s Form Request validation (injected via method-parameter resolution, since Laravel resolves `FormRequest::rules()` through the container). A name is rejected if it is empty, has leading/trailing whitespace, exceeds 100 characters, is `.` or `..`, contains any of `< > : " / \ | ? *` or a control character, ends with a `.`, or — after stripping a trailing `.extension` — case-insensitively matches a Windows reserved device name (`CON`, `PRN`, `AUX`, `NUL`, `COM1`–`COM9`, `LPT1`–`LPT9`). This check runs unconditionally on every OS (not gated by `PHP_OS_FAMILY`), because a vault created under a portable name should never break if the vault directory is later opened on Windows. `InvalidStorageRootException` now carries a `field()` (`'location'` or `'folder_name'`); the controller maps each exception to the matching field automatically instead of always writing to `root_path`.
- **Request field rename**: `UpdateStorageRootRequest`'s single `root_path` field was replaced with `location` (the folder the user picks or types — same semantics as the old `root_path` field) and `folder_name` (optional; omitted/blank means "keep the current folder name"). `StorageController::browse()` always passes the current `$paths->folderName()` explicitly as the second argument, matching the pre-existing "browse uses whatever name is already in effect" behaviour.
- **Bug fix (2026-09-27, same day): `folder_name` is now `required`, not optional, on both `update` and `browse`.** The previous "blank/omitted = keep current" behaviour let the saved name silently override a name the user had just typed but not yet saved — most visibly via "Choose folder…", which posted no `folder_name` at all, so `browse()` fell back to `$paths->folderName()` (the *saved* value) and clobbered whatever the user had typed into the field first. The folder-name input is now the single source of truth for both actions: `Storage.vue`'s `chooseFolder()` posts `{ folder_name: form.folder_name }`, a new `BrowseStorageRootRequest` validates it with the same `assertValidFolderName()`-backed rules as `UpdateStorageRootRequest` (required; a helpful "The folder name is required." message on empty) and runs that validation *before* `StorageController::browse()` ever opens the native dialog — an invalid or missing name never reaches `NativeDialogService`. `BrowseStorageRootRequest::authorize()` preserves the pre-existing 404-outside-the-desktop-runtime behaviour (via a `failedAuthorization()` override calling `abort(404)` instead of the default 403), so that check still happens before the folder-name validation is even relevant to a browser-dev caller. On the client, the props-driven `watch()` was narrowed to `location` only — the folder name is never resynced from server props, only re-baselined (via `form.defaults('folder_name', …)`) after a request that itself submitted it, so cancelling the native picker (which changes nothing server-side) can never overwrite an unsaved typed name.
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
  - **`summary()`'s `writable` flag (post-QA fix, 2026-09-27)**: it used PHP's native `is_writable()`, which is unreliable on Windows for OneDrive-redirected or ACL-controlled folders (e.g. `Downloads`) — it can report `false` even though a real write succeeds, which previously made the Storage page wrongly show "MDVault cannot write to this folder" right after a successful `changeRoot()`. `summary()` now reuses the same real write-probe as `changeRoot()` (via a private, non-throwing helper), only when the directory exists, and never creates anything.
- **Follow-ups**:
  - Phase 2 `VaultService` creates vault directories under `StoragePathService::rootPath()`, re-validating it at creation time.
  - Phase 3 `FileStorageService` may absorb the generic directory-creation and write-probe helpers. `StoragePathService` would then delegate to it.
