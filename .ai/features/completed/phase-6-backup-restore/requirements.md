# Requirements: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Master Plan Phase**: Phase 6, Backup and Restore (`docs/Masterplan.md` §55). Related sections: §9 tables, §12 UUIDs, §14 relative paths, §17 hashing, §25 Backup System, §26 Backup Structure, §27 Manifest, §28 Validation, §29 Import/Restore, §40–§41 `BackupService`, §43 error handling, §44 concurrency, §60 Rules 1–5, 9, 10, §61 item 17, §62 feature and end-to-end tests, §67 checklist.
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 4, Architectural (archive format, new table, cross FS+DB atomicity, security of untrusted input)
- **Status**: **APPROVED** (H1–H11 approved 2026-09-30); **DELIVERED**, QA PASS, analyst sign-off in `analyst-review.md`

---

## 1. Problem Statement
Users keep their only copy of their notes in vault folders. Today MDVault has no way to create a portable, verifiable backup of one or all vaults, or to restore one onto a fresh installation or another machine with the vault and note identities (UUIDs) intact.

§55 requires Backup All, Backup Vault, Restore, Import, Validation and Manifest, using ZIP. The acceptance test is: create a vault → create notes → back up → remove local data → fresh app state → import → verify vaults, notes and content. It "must work reliably".

A corrupted or incompatible backup must never partially overwrite an installation (§28). The DB and the filesystem can fail independently (Rule 10).

## 2. Goals & Non-Goals
### In Scope (Goals)
- **Back up all vaults** and **back up one vault** to a ZIP containing `manifest.json` plus `vaults/<Name>/…`.
  - Every non-ignored file and folder is included (`.md` notes with UUIDs, attachments, empty folders).
  - The ZIP is verified before it is published.
  - Files are never overwritten.
- **Manifest** (format 1): application, format and database versions, app version, creation time, scope, counts, and a JSON registry of vaults and notes (UUIDs, relative paths, SHA-256, sizes, timestamps). No absolute paths.
- **Validate** a backup (§28): format, versions, registry, path safety, entry set, sizes and full SHA-256 integrity, with a preview of what it contains and what would happen.
- **Restore / Import** (one flow): a per-vault action (restore with the original UUIDs / restore as a copy / skip). Restored vaults go into the storage root, with a `(restored)` suffix when the name is taken. The restore is staged, verified and committed all-or-nothing across vaults, then Full-reconciled.
- **`backups` table**: a record of each backup created, shown in a "Recent backups" list.
- **UI**: a Settings → Backup page (back up all, restore from a file or path, recent backups), a "Back up" button on each vault card, and a restore preview dialog.
- **The §55 round-trip acceptance test**, automated.

### Out of Scope (Non-Goals)
- Other formats (`tar.gz`) and the `backup.default_format` setting.
- Scheduled or automatic backups, retention, reminders, and "back up before removing a vault".
- Backing up or restoring application settings (theme, editor, storage root) (H3).
- Replacing an existing vault in place, restoring individual notes or folders, and restoring to a user-chosen folder other than the storage root.
- Encrypted vaults (Phase 7), password-protected ZIPs, and ZIPs not made by MDVault (use "Add existing folder" for plain folders).
- Uploading archives through the browser, cloud destinations, any sync, device or remote concepts (§45, §64).
- Background jobs, progress bars or cancellation. Operations are synchronous, with a busy state.
- Deleting backup files or records from within the app.
- Vault relocation (a separate follow-up item).

## 3. User Personas & Stories
- **As a** user with one copy of my notes, **I want to** back up every vault into one file, **so that** a disk failure doesn't cost me my notes.
- **As a** user moving to a new computer, **I want to** restore my backup into a fresh MDVault, **so that** my vaults, notes, folders and note identities come back exactly.
- **As a** user who deleted something by mistake, **I want to** restore a vault from an older backup as a separate copy, **so that** I can recover files without touching my current vault.
- **As a** cautious user, **I want to** check a backup before restoring it, **so that** I know it is intact and what it will do.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Back up all vaults | Every **active** vault is written to one ZIP. Missing vaults are skipped and named in the result (H10). If there are no vaults, or all are missing, the backup is refused. | Given vaults Work and Personal, When I back up all, Then the ZIP has `manifest.json`, `vaults/Work/…` and `vaults/Personal/…`, with `scope: all` and correct counts. Given Personal is missing, Then only Work is included and the result names Personal as skipped. |
| **FR-02** | Back up one vault | Writes one vault. A missing vault is an error. | Given Work is active, Then the ZIP contains only `vaults/Work/…` and `scope: vault`. Given Work is missing, Then an error is shown and no file is written. |
| **FR-03** | Content selection | Includes every regular file and directory, except: dot-prefixed entries (`.git`, `.obsidian`, `.mdvault-*`), `node_modules` directories, and symlinks or junctions. `.md` files are notes; other files are attachments. Empty folders are included. File bytes are kept exactly (EOL, BOM). | Given `Projects/a.md`, `assets/logo.png`, `Empty/`, `.git/HEAD`, `.mdvault-save-x`, Then the first three are present and the last two are absent. Extracted bytes equal the originals. |
| **FR-04** | Manifest | Format-1 manifest as specified in ADR `backup-archive-format`. It contains no absolute paths. Note UUIDs come from the registry after a Quick reconcile. | Given a backup, Then the manifest has every required key, the counts equal the contents, every note has the registry UUID and the SHA-256 of its bytes, and the JSON contains no vault absolute path. Given a `.md` file created externally just before backup, Then it is registered and included. |
| **FR-05** | Consistent, all-or-nothing backup | Any unreadable file or folder, or a file changing during the backup, fails the whole backup. The file is written to a hidden temp sibling, re-opened and fully verified, then renamed into place without overwriting. Temp files are removed on failure; stale ones (> 1 h) are cleaned up. | Given a failure publishing the file, Then no target exists, no temp file remains and no record is written. Given a verified backup, Then every entry's SHA-256 equals the manifest. |
| **FR-06** | Destination | Desktop: a native Save dialog pre-filled with `<Documents>/MDVault Backups/<suggested name>`; cancel does nothing. Browser dev and tests: written straight into the default backup folder. Refused when: the path isn't absolute, the file name is invalid, the folder is missing or not writable, a file already exists (never overwritten), or the path is inside a registered vault. `.zip` is appended if missing. | Given an existing file at the chosen path, Then an error and the existing file is unchanged. Given a path inside a vault, Then an error. Given a cancelled dialog, Then no file and no record. |
| **FR-07** | Backup records | Each successful backup inserts a `backups` row (uuid, scope, filename, path, size, SHA-256 of the ZIP, format version, counts, contents). If the insert fails, the file is kept and the result says it wasn't recorded. Settings shows the 20 most recent, newest first, with a live `exists` flag. | Given two backups, Then the Settings page lists both newest first, and a deleted file shows as missing. Given the DB insert fails, Then the ZIP exists and the toast warns. |
| **FR-08** | Validate / inspect | Runs the §28 pipeline (ADR `backup-restore-semantics` stages 1–8), including streamed SHA-256 of every entry. Returns backup info and, per vault: counts, bytes, state `new`/`exists`, predicted restore and copy names, and the default action. Content problems → `valid: false` with ≤ 20 problems. An unusable path → 422. | Given a valid backup, Then `valid: true`, and each vault's state reflects the DB. Given a tampered note, Then `valid: false` naming that entry. Given a non-ZIP, missing manifest, bad JSON, newer format, wrong application, count mismatch, duplicate UUID or invalid hash, Then `valid: false` with a specific problem. |
| **FR-09** | Archive safety | Refuses: zip-slip names (`..`, absolute, drive, backslash), control characters, dot segments, Windows-invalid names (on Windows), case-duplicate paths (Windows/macOS), symlink entries, encrypted entries, extra or missing entries, size mismatches, > 100,000 entries, a manifest > 16 MiB, encrypted vaults, and too little disk space. `extractTo` is never used. | Given an archive containing `vaults/Work/../../evil.md`, Then it is invalid, and restore writes nothing anywhere. Given a symlink entry, Then it is invalid. Given an entry longer than declared, Then it is invalid. |
| **FR-10** | Restore actions and identity | Per vault: `restore` (original UUIDs; only when the vault UUID is not registered; default then), `copy` (new UUIDv7 for the vault and notes), `skip` (default when registered). A single colliding note UUID gets a new UUID, with a warning. Timestamps and description are preserved. | Given a fresh install, Then the restored vault and note UUIDs equal the manifest's. Given the vault UUID is registered and the action is `restore`, Then the request fails and nothing changes. Given `copy`, Then new UUIDs and the name `Work (restored)`. |
| **FR-11** | Restore destination and names | Restores into the storage root at `<root>/<name>`. When the name is taken, or anything exists at the target, `<name> (restored)`, `(restored 2)` … `(restored 20)` is used. Existing folders are never merged or overwritten. | Given an unregistered folder `Work` already in the root, Then the vault is restored as `Work (restored)` and the old folder is untouched. |
| **FR-12** | Atomic restore | Validate → stage in `<root>/.mdvault-restore-*` with per-file size and hash checks → one DB transaction inserting all vaults and notes and renaming each staged folder into place. On any failure, moved folders are renamed back, staging is deleted, and nothing is registered. Stale staging folders (> 1 h) are cleaned up. | Given a DB failure on the second vault, Then no vault rows exist, no target folders exist, and no staging folder remains. Given a folder rename failure, Then the same. Given a hash mismatch during staging, Then no change. |
| **FR-13** | Post-restore verification | A Full reconcile per restored vault. Any change becomes a warning. If no vault is current, the first restored vault is opened. `file_mtime` starts null; file modification times are restored best-effort. | Given a normal restore, Then the reconcile reports no changes and the Workspace opens the restored vault. |
| **FR-14** | §55 round trip | Create a vault (nested folders, an empty folder, unicode names, CRLF + BOM, an attachment) → back up → remove all rows, settings and folders → new Documents root → restore → verify. | The vaults (uuid, name, description), notes (uuid, relative_path, file_hash, file_size) and every file's bytes are equal, empty folders exist, and a Full reconcile reports nothing. This passes both through the services and through HTTP. |
| **FR-15** | UI | A Settings → Backup page: back up all, choose a backup file (desktop dialog) or enter a path, recent backups with Restore…. A "Back up" button on each vault card (disabled unless active). The restore dialog shows inspection results, a per-vault action select, problems and a busy state. All components have a single root, no `v-html`, and no path or FS logic. | Given the desktop app, Then "Choose backup file…" opens a native dialog filtered to `.zip`. Given browser dev, Then the path input works and the page says where backups are saved. |
| **FR-16** | Boundaries | `ZipArchive` only in `ArchiveService`. `BackupService` uses no raw FS functions. Only `FileStorageService` writes or deletes files. `Backup` model usage restricted. No `notes.content`, no sync, device or version tables. The only new table is `backups`. | The Pest arch tests pass, and the grep checks in T12 pass. |

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - A local single-user app (ADR `local-app-without-authentication`), with CSRF on every POST.
  - Archives are untrusted input (FR-09).
  - Error messages contain only the user's paths and names, never stack traces.
  - Manifests contain no absolute paths, and never passwords or keys (Phase 7).
  - The native dialogs are used only through `NativeDialogService`.
- **Performance**:
  - Hashing is streamed, and ZIP entries are read as streams; no whole-archive buffering.
  - A backup reads each file about twice. A restore reads the archive twice (inspect + restore).
  - Target: a 5,000-note vault backs up and restores in well under a minute on an SSD (manual D12).
- **Accessibility & UX**:
  - Buttons show a busy state and are disabled while a request runs.
  - The dialog is keyboard accessible (shadcn Dialog), and selects have labels.
  - Toasts give clear outcomes and warnings.
  - Single-root Vue components.
- **Reliability & Data Integrity**:
  - All-or-nothing backup and restore; never overwrite; verify after writing.
  - DB and FS compensation as specified.
  - The DB-insert failure of a backup record keeps the valid file.

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4); NativePHP desktop 2.3.1 (bundled PHP includes `zip`).
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4 + shadcn-vue.
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`).
- Testing: Pest 5 (feature, unit and arch), Vitest for any new pure TS helper.
- Code Style: Laravel Pint (`vendor/bin/pint --dirty --format agent`).
- Database: SQLite (metadata only). New table `backups`. No other schema change.
- **§67 checklist**:
  1. v1? Yes (§25, §55).
  2. Markdown stays the source of truth? Yes: the bytes are copied verbatim, and the registry is only identity metadata.
  3. Portable? Yes: no absolute paths, and folder names are portable.
  4. In a service? Yes: `BackupService` and `ArchiveService`; Vue is presentation only.
  5. Migration? Yes, `backups` only.
  6. Sync complexity? None.
  7. External edits? The backup Quick-reconciles first and verifies after writing; a file changed during the backup fails it. The restore Full-reconciles.
  8. Offline? Fully local.
  9. DB/FS mismatch? Staging, then commit with compensation, then reconcile.
  10. Tests? §4 of the plan.

## 7. Risks & Assumptions
- **Assumption**: vaults and the storage root are on local or removable disks. The staging folder shares the storage root's volume, so renames are atomic.
- **Assumption**: backups are made by MDVault format 1. Other ZIPs are refused.
- **Risk: libzip and non-ASCII file names on Windows.** **Mitigation**: an explicit test on Windows; fallback plan in `plan.md` R2.
- **Risk: long synchronous operations block the single-threaded desktop server.** **Mitigation**: a busy UI and a streaming implementation; background jobs are a later option.
- **Risk: Windows locks during the staged-folder rename.** **Mitigation**: 3 attempts, 200 ms apart, then full compensation.
- **Risk: leftover unregistered folders after a crash mid-commit.** **Mitigation**: the backup is intact; the folders are visible; stale staging is auto-cleaned; documented.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`); H1–H11 approved by user 2026-09-30
