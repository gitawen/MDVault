# Requirements: Phase 2: Vault Management

## Metadata
- **Feature Name**: Phase 2: Vault Management
- **Feature ID**: mdv-p2
- **Master Plan Phase**: Phase 2, Vault Management (`docs/Masterplan.md` §51; §6, §7, §11, §12, §19, §40–43, §60)
- **Author**: System Analyst
- **Created Date**: 2026-09-27
- **Task Complexity**: Level 3, Complex Development
- **Status**: UNDER REVIEW (pending approvals C1–C10 in `plan.md` §6)

---

## 1. Problem Statement
MDVault has settings and a configurable storage root (Phase 1), but no vaults exist yet. A vault is a physical folder of Markdown notes (§6), and SQLite holds its registry record (§11). Users need to:
- create vaults;
- open and close them;
- rename them;
- remove them safely;
- see them in the sidebar.

The app must also detect when a vault's folder has disappeared (§7, §51). Phase 3 (notes and indexing) depends on this registry, on the stable vault UUID (§12, Rule 4) and on the "current vault" state.

SQLite transactions cannot roll back filesystem operations, so consistency between the database and the filesystem must be handled explicitly (§7, §43, Rule 10).

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- The `vaults` table as specified in §11, with a stable UUID (UUIDv7 via `HasUuids`) and an integer `id` that is never exposed to the UI.
- **Create**:
  - The vault's folder is `<storage root>/<name>`.
  - The storage root is created if it is missing.
  - The folder is checked after creation (it exists and is writable).
  - DB/FS consistency: the DB record is created first inside a transaction, the folder is created second, and a compensating clean-up runs on failure.
- **Open / close**: the current vault is persisted in `settings` (`app.current_vault` = UUID) and survives a restart.
- **Rename**: display name and description only (C2).
- **Remove**:
  - unregister only (default);
  - optionally move the folder to the OS Recycle Bin / Trash, desktop only, checked afterwards, behind safety guards (C1).
- **Missing-folder detection**: `status` is `active` or `missing`. It is reconciled whenever vaults are listed, opened or resolved as current, and it recovers automatically.
- **Add an existing folder as a vault** (C3), by typed path or native folder picker.
- **Sidebar**: a real vault list replaces the placeholder. Selecting a vault opens it, and each vault shows its state (current / missing). `AppSidebar.vue` becomes single-root (Phase 0 QA-04).
- **Vaults management page**: list, create, add existing, rename, remove.
- **Workspace**: shows the current vault, or an empty or missing state.

### Out of Scope (Non-Goals)
- Notes, `.md` files, folders inside vaults, indexing and re-indexing, hashing (Phase 3 / 5).
- Editor changes (Phase 4). `TiptapEditor.vue` is not modified.
- Moving a vault, changing its location, renaming its folder on disk, relinking a missing vault (C2 / C4, deferred).
- Permanently deleting a folder (C1: never in v1 without a new decision).
- Vault information panel, "reveal in Explorer", backups, encryption (`is_encrypted` is always `false`).
- Any `notes`, `vault_encryption` or `backups` table.

---

## 3. User Personas & Stories
- **As a** local user, **I want to** create a vault by typing a name, **so that** MDVault creates and tracks a folder for my notes.
- **As a** user, **I want** the vault I was working in to be open again after I restart MDVault, **so that** I can carry on where I left off.
- **As a** user, **I want to** rename a vault, **so that** its label matches how I think about it, without breaking external tools that use the folder.
- **As a** user, **I want to** remove a vault without losing files, and optionally send its folder to the Recycle Bin, **so that** mistakes can be recovered.
- **As a** user, **I want to** see when a vault's folder is missing (for example an unplugged drive), **so that** I understand why it cannot open.
- **As a** user, **I want to** add a folder I already have as a vault, **so that** I can re-add a removed vault or use existing Markdown folders.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Vault registry schema | `vaults` table: `id`, `uuid` (unique), `name`, `description` (nullable), `path` (unique, absolute canonical), `relative_path` (nullable), `is_encrypted` (default false), `status` (string(30), default `active`), timestamps. The `Vault` model generates a UUIDv7 into `uuid` only; `id` stays auto-increment. | Given migrations run, then `vaults` has exactly these columns. `notes`, `vault_encryption` and `backups` are still absent. A created vault has a 36-character UUID that never changes on update. |
| **FR-02** | Create vault | `VaultService::create(name, description)`:<br>1. Ensure the storage root is ready (create it if missing, probe it for writes).<br>2. Target = `<root>/<name>`. Reject it if it is a file, a non-empty folder, or overlaps a registered vault.<br>3. In a DB transaction: insert the record, create the folder, check it (is a directory and writable), save the canonical path.<br>4. If anything fails after this call created the folder, remove that folder (only if it is still empty). | Given a valid name, when a vault is created, then the folder exists, one record exists with `status=active`, `relative_path=<name>` and `path=realpath(folder)`, and no probe file remains. Given a DB failure after the folder was created, then no record exists and the folder is removed. Given the folder cannot be created, then no record exists. |
| **FR-03** | Name rules | Names are trimmed exactly (no leading or trailing whitespace allowed), 1–100 characters, and valid portable folder names. The same rules as `StoragePathService::assertValidFolderName()` apply: no `< > : " / \ \| ? *` or control characters, no trailing dot, not `.` or `..`, no Windows reserved names. Names are unique among vaults regardless of case. | Given `CON`, `a/b`, `x.`, `" x"` or an empty name, then creation fails with an error on `name` and nothing is written. Given an existing vault "Work", when creating "work", then it fails on `name`. |
| **FR-04** | Existing target folder | An existing **empty** folder at the target is reused. A non-empty folder or a file is refused with a clear message. A path that equals, contains or sits inside a registered vault's path is refused. | Given `<root>/Work` exists and is empty, creation succeeds and the folder is kept. Given it contains `a.md`, creation fails, `a.md` is untouched and no record is created. |
| **FR-05** | Open vault | `open(vault)` reconciles the status. A missing vault is refused (with an error message) and its status is stored as `missing`. Otherwise `app.current_vault` = UUID. | Given an active vault, when it is opened, then the setting equals its UUID and the Workspace `currentVault.uuid` matches. Given its folder was deleted externally, when it is opened, then an error toast appears, the setting is unchanged and the status is `missing`. |
| **FR-06** | Current vault survives restart | `current()` resolves the setting to a vault. If the setting points to a vault that no longer exists, it is forgotten and `null` is returned. | Given a vault was opened, when a fresh request cycle runs (`app()->forgetScopedInstances()`), then `current()` returns the same UUID and the Workspace shows it. |
| **FR-07** | Close vault | `close()` forgets `app.current_vault`. | Given an open vault, when it is closed, then `current()` is null and the Workspace shows the "No vault open" state. |
| **FR-08** | Rename vault | Updates `name` (FR-03 rules; uniqueness ignores the vault itself, so changing only the letter case is allowed) and `description`. The folder on disk is never touched. | Given vault "Work" at `<root>/Work` with `a.md`, when it is renamed "Office", then `name=Office`, `path` is unchanged and `a.md` is still in `<root>/Work`. |
| **FR-09** | Remove vault (unregister) | Removes the record. If it was the current vault, the setting is forgotten in the same transaction. The folder and its contents are untouched. | Given vault "Work" with `a.md`, when it is removed, then no record exists, `<root>/Work/a.md` still exists, and the setting is cleared if it was current. |
| **FR-10** | Remove vault and move folder to Trash | Desktop runtime only (`nativephp-internal.running`). The vault must be `active`. Safety guards refuse a filesystem root, the storage root or any folder containing it, and the Documents folder or any folder containing it. Order: trash the folder, check it is gone, then delete the record. If the folder still exists afterwards, it fails and the record is kept. MDVault never deletes a folder permanently. | Given the desktop runtime and a successful trash, then the folder is gone and the record is removed. Given the trash silently fails, then the error is on `move_to_trash` and the record remains. Given the browser runtime, then the error is on `move_to_trash` and nothing changes. |
| **FR-11** | Missing-folder detection | A status of `active` / `missing` is reconciled from `is_dir(path)` in `all()`, `open()`, `current()`, `remove()` and `refreshStatus()`. It is persisted only when it changes. | Given an active vault whose folder is deleted externally, when vaults are listed, then its status is `missing` (in the DB and in the props). When the folder is recreated, it is `active` again. |
| **FR-12** | Add existing folder (C3) | `register(path, name, description)`: the path must be absolute, exist and be a writable directory. It must not be a filesystem root, the storage root or a folder containing it, or the Documents folder or a folder containing it. It must not overlap a registered vault. Name rules follow FR-03. Nothing is created on disk. `relative_path` is the forward-slash path relative to the storage root when the folder is inside it, otherwise `null`. The desktop picker returns the chosen path and a suggested name (the folder's basename) as flash data. | Given `<tmp>/Existing` containing `n.md`, when it is registered as "Existing", then a record exists, `relative_path` is null and `n.md` is untouched. Given a missing path, a file, a relative path, a registered path or a parent of the storage root, then it fails on `path`. |
| **FR-13** | Sidebar vault list | The shared Inertia prop `vaults` (list of summaries) appears on every page. The sidebar lists the vaults, highlights the current one, marks missing ones, opens a vault on click, and links to the Vaults page. There is an empty state. `AppSidebar.vue` has a single root. | Given two vaults with one current, then every page's `vaults` prop has two items with the correct `is_current`, and there are no integer `id`s in any prop. |
| **FR-14** | Vaults management page | `GET /vaults` shows the list (name, description, path, status, current) with the storage root, `canBrowse` and `canTrash`. It has create, add-existing, rename, remove and open actions. | The page renders component `vaults/Index` with `storageRoot`, `canBrowse` and `canTrash`. |
| **FR-15** | Workspace current vault | The Workspace receives `currentVault` (a summary or null) and shows the vault header, a "No vault open" empty state, or a "folder missing" warning. | Given no current vault, then `currentVault` is null. Given one is open, then it matches. |
| **FR-16** | Storage root change isolation | Changing or resetting the storage root never modifies, moves or re-resolves existing vaults. They keep their absolute `path`. | Given vault A under root R1, when the root changes to R2, then A's `path` is unchanged, its status is `active`, and it still opens. New vaults are created under R2. |
| **FR-17** | Architecture | All filesystem and DB logic lives in `VaultService` / `FileStorageService`. Controllers are thin. The NativePHP Shell is only used in `NativeTrash`. The `Vault` model is only used in services, controllers (route binding) and its factory. UUIDs are used in URLs. | Pest arch rules pass. Routes use `{vault:uuid}` (with a UUID constraint). An integer id in the URL returns 404. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - No authentication (ADR `local-app-without-authentication`).
  - Vault routes are public on the local server and protected by NativePHP's `PreventRegularBrowserAccess`.
  - The desktop-only endpoints (`vaults.existing.browse`) return 404 outside the runtime.
  - Trash is refused outside the runtime.
  - Error messages contain no stack traces, only the user's own paths and names.
- **Performance**: vault counts are small (tens at most). Listing does one query plus one `is_dir` per vault. Status writes happen only when a status changes. No N+1 (no relations yet).
- **Accessibility & UX**:
  - Every new or edited Vue component has a single root.
  - Dialogs use shadcn `Dialog` with labelled inputs and `InputError`.
  - Removal is confirmed in a dialog that shows the full path, says "your files stay on disk" (in the unregister case), and uses a destructive-styled confirm button.
  - Missing vaults show an icon and text (not colour alone).
- **Reliability & Data Integrity**:
  - DB-first-in-transaction plus filesystem compensation on create (ADR `vault-registry-and-consistency`).
  - Trash first, then check, then DB delete on removal (ADR `vault-removal-and-rename-semantics`).
  - MDVault never deletes a non-empty folder. It never permanently deletes a folder at all; the only clean-up is `rmdir` of an empty folder it created itself in the same operation.
  - A unique index on `uuid` and on `path`; a service-level duplicate check that ignores case on Windows.
- **Offline**: no network access. The NativePHP Shell/Dialog calls go only to the local Electron API.

---

## 6. Technical Constraints & Context
- Laravel 13 / PHP 8.4, Inertia v3 + Vue 3, Tailwind v4, shadcn-vue, Wayfinder, Pest 5, SQLite, NativePHP desktop 2.3.
- ADRs:
  - `service-layer-architecture`: final `*Service` classes in `app/Services`; contracts only at OS boundaries.
  - `settings-persistence`: `SettingKey` enum, sparse rows.
  - `storage-root-resolution`: the root is only the parent for future vaults; `assertValidFolderName()` is the single source of truth for name rules.
  - `desktop-runtime-baseline`.
- NativePHP facts, verified in `vendor/`:
  - `Native\Desktop\Facades\Shell::trashFile()` sends `DELETE shell/trash-item` to Electron `shell.trashItem()`.
  - It returns `void` and **swallows** HTTP 400 errors, so success must be checked by looking at the filesystem afterwards.
  - `ShellContract` is bound with `bind` (a fresh `Client` per resolve), so `Http::fake()` works.
  - `ShellFake` exists but does not delete anything.
- `nativephp-internal.running` is the desktop-runtime flag (same as `NativeDialogService::isAvailable()`).

---

## 7. Risks & Assumptions
- **Assumption**: one local user and one instance (§44). No cross-process locking.
- **Assumption**: approvals C1–C10 are accepted as recommended. Rejecting C3 removes FR-12 and T6; the other rejections change individual tasks, as listed in `plan.md` §6.
- **Risk**: tests trash or create folders in real user locations. **Mitigation**: `fakeDocumentsDirectory()` plus temp dirs in every vault test. The default `Trash` binding is unavailable outside the runtime. The `fakeTrash()` helper only deletes inside the test temp dir.
- **Risk**: Windows path aliasing (letter case, 8.3 short names, trailing separators). **Mitigation**: canonical `realpath` is stored; `FileStorageService::samePath()` / `isSameOrInside()` ignore case on Windows and respect separator boundaries; tests compare with `realpath()`.
- **Risk**: the folder is locked by another program (Explorer, VS Code, OneDrive) during trash. **Mitigation**: the check afterwards; the record is kept; the message says to close other programs.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [ ] Approved to proceed to Planning (`plan.md`): pending user approvals C1–C10
