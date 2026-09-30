# Requirements: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Master Plan Phase**: Phase 5, Filesystem Intelligence (`docs/Masterplan.md` §54). Related sections: §17 hashing, §18 filesystem as truth, §24 external file changes, §38/§39 re-indexing, §41 `VaultIndexService` "External file reconciliation", §43 error handling, §44 concurrency, §60 Rules 8/9/10, §62 filesystem tests, §67 checklist.
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 4, Architectural (change-detection architecture, identity heuristics, schema change, conflict UX)
- **Status**: DELIVERED (G1–G8 approved as recommended 2026-09-30; Phase 5 signed off 2026-09-30)

---

## 1. Problem Statement
Users edit, create, rename, move and delete `.md` files and folders with other tools: VS Code, Notepad, Explorer/Finder, git, scripts (§18, Rule 8). Today MDVault notices these changes only at a few moments:
- on vault open;
- on a manual Re-index;
- when a note is opened (it re-hashes that file);
- when a save hits the base-hash guard (409 `changed`/`missing`).

Between those moments the note tree and the registry are stale. Worse, the open note's editor has no idea the file changed until it tries to save.

§54 requires detecting Create / Modify / Rename / Move / Delete, reconciling them with SQLite, and handling open-document conflicts safely. The application must never silently overwrite external changes. UUIDs must stay stable wherever identity can be determined (Rule 4). The DB and filesystem can fail independently (Rule 10).

## 2. Goals & Non-Goals
### In Scope (Goals)
- **Automatic detection while MDVault is open** (G1). A quick check runs:
  - when the Workspace mounts;
  - when the window gains focus or becomes visible;
  - every 5 s while the window is visible, with an adaptive back-off.

  Opening a note still hashes that file, and the save guard is unchanged. A manual "Re-index" does a full hash of every file.
- **Reconciliation** in `VaultIndexService` (§41):
  - identity matching: exact path → case-insensitive path → unique hash → unique file name (G3);
  - a quick mode that skips hashing when a file's size and modification time are unchanged (G2);
  - one DB transaction with a stale-registry guard;
  - no filesystem writes.
- **Folder changes** (create / delete / rename / move) show up in the tree through a tree signature. Notes inside a renamed folder keep their UUIDs.
- **Open-note policy** (G4):
  - clean + changed → auto-reload with a toast;
  - dirty + changed → conflict banner (Reload from disk / Keep my version / Save mine as a new note / Compare / Copy my text);
  - moved → follow silently;
  - deleted + clean → close with a toast;
  - deleted + dirty → banner (Save as a new note / Copy / Check again / Discard).
- **Compare view** (G5): a line diff of the version on disk against the editor's version.
- **"Save mine as a new note"**: a never-overwrite copy next to the original, or recreation at the original path when it has been deleted.
- **The existing setting `app.check_external_changes`** (G6) switches the automatic focus and interval checks on or off.
- **Orphan `.mdvault-save-*` detection** (G7): a non-blocking notice; the files are never deleted.
- **Vault folder disappearing or returning while open**: detected, with an automatic switch to or from the existing missing-vault UI.

### Out of Scope (Non-Goals)
- Native OS watchers (NativePHP child process, `fs.watch`, chokidar, `@parcel/watcher`). The design allows one to be added later as just another trigger (see ADR `external-change-detection`).
- Detection while MDVault is closed. Vault open (quick reconcile) and manual Re-index cover that.
- Any synchronization, devices, remote accounts, version history, `note_versions` / `sync_*` tables, three-way merge, or automatic merging (§45, §64, Rule 10).
- Recovery actions for orphan temp files (rename or restore); only a notice (G7).
- Folder rename/move and non-empty folder delete **in the app** (an existing follow-up item; not Phase 5).
- Watching vaults other than the current one.
- Encrypted vaults (Phase 7 revisits detection over encrypted names).
- Backup exclusion of `.mdvault-*` files (Phase 6).

## 3. User Personas & Stories
- **As a** user who also edits notes in VS Code, **I want** MDVault's tree and open note to update on their own, **so that** I never work on a stale copy.
- **As a** user typing in MDVault while another tool changes the same file, **I want** to be told, and to choose what to keep, **so that** neither version is lost silently.
- **As a** user who reorganises folders in Explorer, **I want** my notes to keep their identity, **so that** links, backups and future features keep working.
- **As a** user with a large vault, **I want** detection not to slow down typing or saving.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | External modify | A changed `.md` file updates its registry `file_hash`/`file_size` on the next check. | Given a closed note edited externally, When a check runs, Then the DB hash equals the file's SHA-256 and its UUID is unchanged. |
| **FR-02** | External create | A new `.md` file is registered (new UUIDv7) and appears in the tree. | Given a new file `Ideas/New.md`, When a check runs, Then a record exists and the response's `tree_signature` differs from the previous one. |
| **FR-03** | External delete | A missing file's record is removed, unless it sits under an unreadable directory. | Given a file deleted externally, When a check runs, Then its record is gone. Given its parent directory is unreadable, Then the record is kept. |
| **FR-04** | External rename / move keeps UUID | Pairing order: exact path → unique case-insensitive path → unique identical hash → unique file name (G3). Only 1:1 pairs are made; anything ambiguous becomes a delete plus an insert. | Given `A/x.md` moved to `B/x.md` (same content), Then same UUID. Given it is moved **and** edited, Then same UUID (basename step). Given a rename **and** an edit, Then a new UUID (documented). Given two identical files, one moved, Then delete + insert. |
| **FR-05** | Folder changes | Folder create, delete, rename and move are reflected in the tree. Notes inside a renamed folder keep their UUIDs. | Given `Projects/` renamed to `Work/` in Explorer, When a check runs, Then every note under it has the same UUID with its new path, and the tree shows `Work`. Given an empty folder created or deleted externally, Then the tree signature changes. |
| **FR-06** | Quick scan and verification | Quick mode skips hashing a file whose path, size and mtime match the record and whose stored mtime is trusted. Mtimes within 2 s of the scan start are stored as `null` (untrusted). The open note's path is always hashed. Full mode hashes everything. | Given a same-size edit with its mtime restored, Then quick mode misses it and full mode detects it; as the open note, quick mode detects it. Given two quick checks with no changes, Then the second makes no DB writes. |
| **FR-07** | Consistency and failure handling | Reconcile never writes files. DB changes happen in one transaction after a fingerprint re-check. If the registry changed since the plan (or a `QueryException` occurs), nothing is applied and the result is `stale`. Unreadable files and directories keep their records. A missing vault root → `unavailable`, with nothing changed. | Given the registry modified between `plan()` and `apply()`, Then `apply` returns `stale` and no row changes. Given the vault folder renamed away, Then `unavailable`, and the vault status becomes `missing`. |
| **FR-08** | Check endpoint | `POST /vaults/{vault:uuid}/changes` with `{open_note: ?uuid}` returns `{status, changed, tree_signature, open_note, orphan_temp_files}`. `status` is one of `ok`, `busy`, `unavailable`, `disabled`, `inactive`. | Given the setting is off, Then `disabled` with no scan. Given a vault that isn't current, Then `inactive`. Given a valid open note, Then `open_note` = `{uuid, exists, relative_path, file_hash}`. |
| **FR-09** | Client triggers and scheduling | Checks run on mount, on focus or visibility, and every 5 s while visible. There is one check at a time. The delay after a check is `clamp(max(5 s, 10 × duration), 5 s, 60 s)`; errors back off exponentially up to 60 s. Checks pause while hidden, during Inertia visits, and while a save is in flight. The setting turns this off. | Vitest with fake timers covers every rule. |
| **FR-10** | Tree refresh is editor-safe | A tree-only partial reload (`only` without `note`) is not intercepted by the unsaved-changes guard (no flush, no freeze). | Given a dirty editor and an external create elsewhere, When the tree reloads, Then no save is sent and the editor isn't frozen. |
| **FR-11** | Open note: clean + changed | The note reloads from disk automatically, with an info toast. | Given an open, clean note edited in VS Code, When a check runs, Then the editor shows the new content and the saver's base hash equals the disk hash. |
| **FR-12** | Open note: dirty + changed | The saver enters the `changed` conflict and autosave pauses. The banner offers Reload from disk (confirm) / Keep my version (confirm; re-sends with the disk hash) / Save mine as a new note / Compare / Copy my text. Nothing is written without an explicit choice. | Given unsaved edits and an external edit, When a check runs, Then the banner appears, no PUT is sent, and the file keeps the external content. |
| **FR-13** | Open note moved | Same UUID and same content at a new path: the header path updates in place (no remount) and editing continues. Saves land at the new path. A pending `missing` conflict (from a 409) resumes automatically once the move is detected. | Given the open note moved in Explorer while typing, Then the next save writes the new path, and no banner remains. |
| **FR-14** | Open note deleted | Clean: navigate to the Workspace with a warning toast. Dirty: `missing` banner with Save as a new note / Copy my text / Check again / Discard my edits. | Given a clean open note deleted externally, Then the Workspace has no note selected and a toast names the path. |
| **FR-15** | Save mine as a new note | `POST /vaults/{vault:uuid}/notes/copy` with `{source_path, content, mode, has_frontmatter, frontmatter}`. It creates the original file name if that path is free, otherwise `<stem> (my version).md`, then `(my version 2)` up to 20. It uses exclusive create only, keeps the original's EOL/BOM when the original is still readable, and falls back to the vault root when the folder is gone. The client then opens the new note. | Given a `changed` conflict, Then `X (my version).md` exists with the editor's text, and the original keeps the external content. Given a deleted note, Then the file is recreated at its original path with a new UUID. |
| **FR-16** | Compare | `GET /notes/{note:uuid}/disk` returns the current disk text. A dialog shows a line diff (disk → mine) as text only, with no `v-html`. | Given a `changed` conflict, When Compare is clicked, Then added and removed lines are marked. |
| **FR-17** | Own writes are not "external" | In-app saves, creates, renames, moves and deletes update the registry first, so a following check reports no change and shows no toast. `.mdvault-*` names are never indexed. | Given a save, When a check runs, Then `changed` is false and `open_note.file_hash` equals the saver's base hash. |
| **FR-18** | Setting | `app.check_external_changes` enables the automatic checks. Note-open verification, the save guard and manual Re-index always work. The General page copy is updated. | Given the setting is off, Then no requests are sent to `vaults.changes.check`, and an external edit still gives a 409 on save. |
| **FR-19** | Orphan temp files | `.mdvault-save-*` files older than 60 s are listed in the check response. The Workspace shows a dismissible notice. Nothing is deleted or indexed. | Given an old orphan, Then the notice lists its vault-relative path. |
| **FR-20** | Vault availability | If the current vault's folder disappears while it is open, the check returns `unavailable` and the page reloads into the missing-vault state. When the folder returns, the next check returns `ok` and the page reloads into the active state. | Manual D17; feature test for `unavailable`. |
| **FR-21** | Boundaries | Filesystem work stays in `FileStorageService`/`FileHashService`, reconciliation in `VaultIndexService`, orchestration in `ExternalChangeService`. Vue has no path or filesystem logic. There are no new tables and no sync infrastructure. | Arch tests and greps in T10. |

## 5. Non-Functional Requirements
- **Security & Authorization**: local single-user app (ADR `local-app-without-authentication`). Web middleware and CSRF apply to the new POST routes. `source_path` goes through the existing path-safety rules (no `..`, backslashes, drive letters, leading `/`, NUL; symlinks refused). JSON responses expose no `id` or `vault_id`.
- **Performance**:
  - A quick check over an unchanged 1,000-note vault should take under about 150 ms (a stat walk, no hashing).
  - The adaptive delay keeps check time under about 10 % of wall time.
  - Hashing is proportional to the number of changed files, plus the open note.
  - NativePHP's PHP server is single-threaded (`php -S`), so a long check delays a save; the adaptive delay bounds this.
- **Accessibility & UX**: the banner uses `role`/`aria-live` from the existing `Alert`; confirm dialogs are kept; toasts use `vue-sonner`; single-root components; keyboard-reachable buttons.
- **Reliability & Data Integrity**:
  - Reconcile is read-only on disk.
  - DB writes are all-or-nothing, behind a stale guard.
  - Copies use `fopen('x')` and exact-bytes compensation.
  - The only overwrite remains `replaceFile` behind the base-hash guard.
  - External content is never overwritten without a confirmed "Keep my version".

## 6. Technical Constraints & Context
- Laravel 13 / PHP 8.4, Inertia v3 + Vue 3, Tailwind v4, shadcn-vue, Wayfinder, Pest 5, Vitest via Vite+ (`npm run test:js`), SQLite.
- NativePHP desktop 2.3.1: no file-watch API. Its `ChildProcess` could host a Node watcher, but this phase doesn't use it (ADR `external-change-detection`).
- Reuse: `VaultIndexService::reindex`, `FileStorageService::scan`/`createFile`/`deleteNewFileWithContents`, `FileHashService`, `NoteService::preview`/`save`, `MarkdownService`, `noteSaver`, `saveTransport`, `NoteConflictAlert`, `useUnsavedChangesGuard`, `SettingKey::CheckExternalChanges`.

## 7. Risks & Assumptions
- **Assumption**: one user and one running instance (§44); vaults on local or removable disks.
- **Risk**: an mtime-preserving same-size edit is missed by quick mode. **Mitigation**: the open note is always hashed; the save guard catches it; manual Re-index is full; the racy-mtime rule (ADR `external-change-reconciliation`).
- **Risk**: false identity pairing by file name (a deletion plus an unrelated same-name file in another folder within one interval). **Mitigation**: content differences always go through the conflict path; the banner names the new path; documented.
- **Risk**: a partial write by an external tool is observed. **Mitigation**: clean notes simply reload again on the next check; dirty notes only get a banner; "Keep my version" re-checks the hash.
- **Risk**: network or removable drives are slow to stat. **Mitigation**: the adaptive back-off; an `unavailable` status.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`); G1–G8 approved 2026-09-30
