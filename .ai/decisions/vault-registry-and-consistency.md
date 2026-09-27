# ADR: Vault registry, path authority and DB/filesystem consistency

- **Status**: Proposed (Phase 2 plan, pending user approvals C5, C6, C7, C8)
- **Date**: 2026-09-27
- **Phase**: Master Plan Phase 2, Vault Management (§6, §7, §11, §12, §43, §51, §60 Rules 2/4/9/10)

## Context
- A vault is a physical directory (§6). SQLite holds its registry record (§11: `uuid`, `name`, `description`, `path`, `relative_path`, `is_encrypted`, `status`).
- §7 prescribes the create order: DB record, then directory, then check, then complete. It warns that SQLite transactions can't roll back filesystem operations.
- §12 / Rule 4 require stable UUIDs.
- ADR `storage-root-resolution` makes the storage root "the parent for vaults created in the future". Changing it never moves data, and each vault keeps its own absolute `path`.
- Users and external tools may delete, rename or unplug vault folders at any time (Rule 8), so the registry can drift from the filesystem.
- The app has one user and one instance (§44). SQLite runs locally.

## Options Considered
1. **Locating vaults**:
   - (a) resolve `root + relative_path` at runtime;
   - (b) the absolute `path` decides, and `relative_path` is informational (chosen).

   (a) would mark every vault "missing" after a root change and contradicts the storage-root ADR.
2. **Create consistency**:
   - (a) FS first, then DB (the Phase 1 pattern);
   - (b) DB first with a `creating` status plus startup reconciliation;
   - (c) DB insert, mkdir and check inside one DB transaction, plus FS compensation on failure (chosen).

   (c) keeps §7's order, makes the DB side atomic, and limits the filesystem side to "at most an empty directory left behind".
3. **Current vault**:
   - (a) a `vaults.is_current` column or `last_opened_at`;
   - (b) a settings key holding the UUID (chosen).

   (a) needs a one-row invariant and extra columns not in §11.
4. **Status**:
   - (a) computed live, never stored;
   - (b) stored and reconciled from the filesystem on every read (chosen).

   (b) gives later phases (indexing, backup) a queryable status while self-healing.

## Decision
- **Schema**:
  - `vaults(id, uuid unique, name string(255), description text null, path text unique, relative_path text null, is_encrypted bool default false, status string(30) default 'active', timestamps)`.
  - `Vault` uses `HasUuids` with `uniqueIds() = ['uuid']`. The UUID is UUIDv7, generated once and never changed. `id` stays auto-increment for internal use and is hidden from serialisation.
  - URLs use `{vault:uuid}`. The UI never sees `id`.
- **Path authority**:
  - `path` = `realpath` of the vault directory. It is the only locator.
  - `relative_path` = forward-slash path relative to the storage root when the vault was created or registered, or null when the folder is outside the root. It is a hint for future backup/restore (Phase 6) and never used to locate a vault.
  - Changing or resetting the storage root never reads, rewrites or moves existing vaults.
- **Names**:
  - A vault name is its folder name at creation, so it must satisfy `StoragePathService::assertValidFolderName()` (the portable rules, on every OS).
  - Names are unique regardless of letter case (checked in the service; vault counts are small).
- **Create** (`VaultService::create`):
  1. Check the name and its uniqueness.
  2. `StoragePathService::ensureRootReady()`: create the root if it is missing, probe it for writes, never persist.
  3. Target = `<root>/<name>`. A file there → error. A non-empty directory → error. An empty directory → reused (C8). Overlap with any registered vault (equal, ancestor or descendant) → error.
  4. In a DB transaction: insert the record, `mkdir` (non-recursive) if the folder is missing, check it (a directory and a real write probe), store the canonical `path`.
  5. On any exception, if this operation created the directory, remove it **only if it is still empty**, then rethrow.
  - Outcome matrix:
    - FS failure → the DB rolls back; no record.
    - DB failure (including at commit) → the directory is removed.
    - Crash between mkdir and commit → an empty orphan directory, which a later create with the same name reuses.
- **Register existing folder**: the folder must already be an existing, writable, absolute directory. It must not be a filesystem root, the storage root or a folder containing it, or the Documents folder or a folder containing it, and it must not overlap a registered vault. This is a DB insert only; nothing is created.
- **Status**:
  - `VaultStatus::Active | Missing`, from `is_dir(path)`.
  - It is reconciled in `all()`, `open()`, `current()`, `remove()` and `refreshStatus()`, and persisted only on change. It recovers automatically when the folder returns.
  - Opening a missing vault is refused. A missing *current* vault stays current, and the UI warns.
- **Current vault**:
  - `SettingKey::CurrentVault` (`app.current_vault`, string, general, default null) holds the UUID.
  - A stale UUID is forgotten on read.
  - Removing the current vault clears it in the same transaction as the record delete.
  - The General settings page no longer exposes `group(General)` wholesale.
- **Boundary**: filesystem work goes through `FileStorageService` (§41, the Phase 2 subset). Path comparisons ignore letter case on Windows and require a separator boundary.

## Consequences
- **Positive**:
  - §7's order is honoured and every failure path has a defined, tested outcome.
  - Root changes are safe.
  - UUIDs are ready for Phase 3 notes (`vault_id`) and Phase 6 backups.
  - A drifted registry self-heals.
- **Negative / trade-offs**:
  - Listing does one `is_dir` per vault, and GET requests may write a status change.
  - A vault moved outside the app shows as Missing until relocation exists (C4). Re-adding it creates a new UUID.
  - Name-uniqueness and path-overlap checks live in PHP, not in DB constraints; the unique `path` index is the backstop.
  - The write-probe logic now exists in both `StoragePathService` and `FileStorageService`.
- **Follow-ups**:
  - Phase 3: `StoragePathService` delegates its probe and mkdir to `FileStorageService`; `notes.vault_id` references `vaults.id`, and exports use `uuid`.
  - A vault-relocation item (move, relink, folder rename) before Phase 6.
  - Phase 7 adds a `Locked` status.
