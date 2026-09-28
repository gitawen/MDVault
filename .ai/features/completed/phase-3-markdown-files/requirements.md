# Requirements: Phase 3: Markdown Filesystem

## Metadata
- **Feature Name**: Phase 3: Markdown Filesystem
- **Feature ID**: mdv-p3
- **Master Plan Phase**: Phase 3, Markdown Filesystem (`docs/Masterplan.md` §52; §13 `notes`, §14 relative paths, §15 no folders table, §16 content on disk, §17 hashing, §18 filesystem as source of truth, §23 note management, §38 re-indexing, §39 database as index, §40–43 services and error handling, §60 rules, §62 testing)
- **Author**: System Analyst
- **Created Date**: 2026-09-28
- **Task Complexity**: Level 3, Complex Development
- **Status**: UNDER REVIEW (pending approvals E1–E10 in `plan.md` §6)

---

## 1. Problem Statement
Phase 2 delivered a vault registry: folders on disk with stable UUIDs and a "current vault". A vault's Markdown files are still invisible to MDVault. Users cannot create, organise or open notes.

Master Plan §52 requires the following:
- a `notes` registry (§13) that stores **relative paths** (§14) and **SHA-256 hashes** (§17), and **never** the content (§16);
- note and folder operations (§23);
- a re-index that rebuilds the registry from disk (§38, §39).

External tools may change files at any time (Rule 8). The filesystem is the source of truth (§18). SQLite cannot roll back file operations (§43, Rule 10).

This phase is the foundation for:
- the editor (Phase 4: open, then parse, then save, then re-hash);
- external-change detection (Phase 5: hash comparison);
- backups (Phase 6: stable UUIDs and relative paths).

## 2. Goals & Non-Goals
### In Scope (Goals)
- A `notes` table per §13, with a UUIDv7 `uuid`, a unique `(vault_id, relative_path)`, and a cascade on vault delete. It has no `content` column.
- `FileHashService`: SHA-256 of files and strings, and a hash-match check.
- `VaultIndexService`:
  - scans a vault with the ignore rules (E3);
  - reconciles the registry (add, update, move, remove) and preserves UUIDs (E6);
  - skips unreadable entries safely;
  - lists the tree and folders.
- `NoteService`:
  - create, rename, move and delete notes (delete = OS Recycle Bin, E1);
  - create folders and delete empty folders (E2);
  - a read-only preview that reconciles that note's hash and size.
- Indexing triggers (E5):
  - vault open, create and add-existing;
  - a manual "Re-index" action;
  - per-operation record updates.
- UI:
  - a Workspace note-tree pane (E8) with new note, new folder, re-index, and per-item rename, move and delete;
  - a read-only raw-Markdown viewer (E7);
  - URLs use UUIDs only.

### Out of Scope (Non-Goals)
- Editing and saving notes, Tiptap integration, `MarkdownService`, round-trip (Phase 4).
- A filesystem watcher, external-change notifications, conflict UI, background or queued indexing (Phase 5).
- Folder rename/move, deleting non-empty folders, drag-and-drop moves, multi-select.
- Search (beyond browsing the tree), `.markdown`/`.txt` files, frontmatter parsing, first-heading titles.
- Writing IDs into user files (frontmatter or sidecar files).
- Backups, encryption (`is_encrypted` is always false), sync.
- The `StoragePathService` write-probe dedupe follow-up (ADR `vault-registry-and-consistency`). It is deferred: there is no functional need, and it would touch Phase 1 code.

## 3. User Personas & Stories
- **As a** user, **I want** my existing Markdown folders to appear as a note tree when I open a vault, **so that** I can use MDVault with notes I already have.
- **As a** user, **I want to** create, rename and move notes and create folders, **so that** I can organise my vault, and see the changes in Explorer or Finder.
- **As a** user, **I want** deleted notes to go to the Recycle Bin, **so that** a mistake is recoverable.
- **As a** user who edits files in VS Code, **I want** "Re-index" to pick up my external changes and keep my notes' identity when I only renamed them, **so that** MDVault stays in sync with the disk.
- **As a** user, **I want to** open a note and read its Markdown, **so that** I can check its content before the editor arrives.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Notes schema | `notes`: `id`, `uuid` (unique), `vault_id` (FK `vaults.id`, cascade on delete), `title`, `filename`, `relative_path` (text), `extension`, `mime_type` (default `text/markdown`), `file_size` (unsigned big int), `file_hash` (64 characters), `is_encrypted` (default false), timestamps. Unique on `(vault_id, relative_path)`; index on `(vault_id, file_hash)`. **No `content` column.** | Given migrations run, then `notes` has exactly these columns and no `content`. `vault_encryption` and `backups` are still absent. |
| **FR-02** | Note identity | `Note` uses `HasUuids` on `uuid` only (UUIDv7). `id` and `vault_id` are hidden from serialisation. `Vault hasMany notes`, and `Note belongsTo vault`. | A created note has a 36-character UUID that is unchanged after an update. `toArray()` has no `id` or `vault_id`. Deleting a vault record deletes its note records, and the files on disk are untouched. |
| **FR-03** | File hashing | `FileHashService` computes lowercase hex SHA-256 of a file (streamed) or of a string. It returns null for an unreadable file, and `matches()` compares in constant time. | The empty file hashes to `e3b0c442…b855`. `"abc"` hashes to `ba7816bf…15ad`. A missing path gives null. |
| **FR-04** | Indexable files (E3) | Indexed: regular files whose extension is `md` in any letter case. Skipped:<br>• any file or folder whose name starts with `.`;<br>• folders named `node_modules` (any case);<br>• symlinks and junctions (never followed or indexed);<br>• any other extension.<br>There is no size limit for indexing. | Given `a.md`, `B.MD`, `x.txt`, `.git/c.md`, `.obsidian/d.md`, `node_modules/e.md` and `.mdvault-rename-x`, then only `a.md` and `B.MD` are indexed. A symlinked folder is not traversed (Linux/macOS test). |
| **FR-05** | Re-index reconciles | `reindex(vault)` scans the vault and hashes every indexable file. It then updates the registry: adds new files, updates changed hash or size, renames or moves matched records, and removes records whose file is gone. All DB writes happen in one transaction. It returns counts (`added`, `updated`, `moved`, `removed`, `unchanged`, `skipped`).<br>• `relative_path` uses `/` separators on every OS.<br>• `filename` is the actual basename.<br>• `title` is the filename without its last extension.<br>• `extension` is lower-cased.<br>• `file_hash` is the SHA-256 of the file. | Given `Projects/HRMIS.md` on disk, when re-indexed, then a record exists with `relative_path='Projects/HRMIS.md'`, `title='HRMIS'`, `file_hash=hash_file(sha256)`, and `file_size=filesize`. A second run reports everything unchanged and writes nothing. |
| **FR-06** | Stable UUIDs (E6) | Matching order:<br>1. exact path;<br>2. a unique case-insensitive path among the leftovers;<br>3. a unique identical hash among the leftovers.<br>Steps 2 and 3 update the existing record and keep its UUID. Ambiguous matches are never paired. | An external rename or move with unchanged content keeps the UUID. A case-only external rename keeps the UUID. A rename plus an edit gives a new UUID. Two identical-content files that both appear where one record vanished are not paired. |
| **FR-07** | Rebuild from filesystem | The registry can be rebuilt entirely from disk. | Given all `notes` rows are deleted, when re-indexed, then every indexable file has a record with the correct path, hash and size. |
| **FR-08** | Index triggers (E5) | Re-index runs:<br>• after a vault is opened (sidebar or Vaults page), created, or added from an existing folder;<br>• on the manual "Re-index" action, which shows a result toast.<br>Operations inside MDVault update only their own record. Workspace GET requests never run a full re-index. | Opening a registered folder that contains `n.md` creates its record. POST re-index gives a success toast with the counts. |
| **FR-09** | Index resilience | A missing or unlistable vault root causes a `NoteOperationException` and changes nothing. An unreadable subfolder or file is counted in `skipped`, and records under it are kept unchanged. | Given the vault folder was deleted, re-index errors and no records change. Given a file whose hash can't be read, its existing record is kept. |
| **FR-10** | Create note | `create(vault, folder, name)`, in order:<br>1. checks;<br>2. **exclusive** file create (never overwrites);<br>3. check the file exists;<br>4. hash it;<br>5. insert the DB record.<br>If the DB insert fails, the just-created file is removed only if it is still empty. New files are empty (0 bytes). | Creating "Meeting" in `Projects` produces `Projects/Meeting.md` on disk and a record. A DB failure leaves no file and no record. An existing `Meeting.md` (any letter case) is refused and untouched. |
| **FR-11** | Names (E4) | Note and folder names:<br>• follow `StoragePathService::assertValidFolderName` (portable, 1–100 characters);<br>• must not start with `.`;<br>• a folder name must not be `node_modules`.<br>For notes, a typed trailing `.md` (any case) is stripped and `.md` is appended. Names are unique ignoring case within the folder, checked on disk and in the registry. | `CON`, `a/b`, `x.`, `.hidden` and an empty name are refused on `name`. `Plan.md` becomes `Plan.md`, not `Plan.md.md`. |
| **FR-12** | Rename note | Order:<br>1. checks;<br>2. a no-overwrite file rename (case-only renames go through a hidden temp name);<br>3. check the result;<br>4. DB update (`relative_path`, `filename`, `title`, `extension`).<br>If the DB update fails, the file is renamed back. If that also fails, the error is reported and the message tells the user to re-index. The UUID is unchanged. | Renaming `a.md` to "b" gives `b.md` on disk and in the DB, with the same UUID. A case-only rename works. A simulated lock gives an error with nothing changed. A DB failure moves the file back. |
| **FR-13** | Move note between folders | Same order and compensation as FR-12. The target folder must exist inside the vault. Moving to the same folder does nothing. | Moving `Projects/HRMIS.md` to `Servers` gives `Servers/HRMIS.md`. `relative_path` and `filename` are updated and the UUID is unchanged (§23). |
| **FR-14** | Delete note (E1) | Moves the file to the OS Recycle Bin / Trash through the existing `Trash` contract. Only if the file is then gone is the record deleted. Desktop only. If the file is already missing, only the record is deleted. MDVault never permanently deletes a note. | With `fakeTrash()`, the file is gone and the record deleted. On a silent trash failure, the error is on `note` and the record is kept. In the browser runtime, the error is on `note` and nothing changes. |
| **FR-15** | Create folder | Creates `<parent>/<name>`: non-recursive, never over an existing entry, checked afterwards. No DB write (§15). | Creating "Projects" at the root makes the folder on disk, and it appears in the tree while empty. |
| **FR-16** | Delete empty folder (E2) | Deletes a vault sub-folder only if it is completely empty, including hidden files. Any stale records under it are removed. The vault root can't be deleted. | Deleting an empty folder removes it. A folder containing `.DS_Store` or `a.md` is refused, and its contents are intact. |
| **FR-17** | Note tree (E8) | While an active vault is current, the Workspace shows a tree of folders (from a live directory scan, so empty folders appear) and notes (from the registry). Folders come first, then natural case-insensitive order. Folders that contain the open note are expanded. A missing vault or no vault means no tree. | The tree shows `Projects/` (with `HRMIS`), an empty `Archive/`, and `Readme` at the root. The Workspace for no vault has `tree = null`. |
| **FR-18** | Open note (E7) | `GET /notes/{uuid}` renders the Workspace with the note's metadata and raw content, read-only.<br>• Content is re-read from disk. If its hash or size differs from the record, the record is updated (the filesystem wins).<br>• States: `ok`, `missing`, `too_large` (over 1 MiB, no content), `unreadable`.<br>• Invalid UTF-8 is replaced with U+FFFD and flagged.<br>• A note in a vault that is not current redirects to the Workspace with an error toast. | Editing the file externally and reopening the note shows the new text and the new hash in the DB. A deleted file shows the missing state with a Re-index action. HTML in Markdown is shown as text, never rendered. |
| **FR-19** | Path safety | All user-supplied folder paths are vault-relative, `/`-separated, and without `..`, `.` or empty segments, backslashes, drive letters or NUL. They must resolve to a real directory inside the vault that is not reached through a symlink. | `../x`, `C:\x`, `/etc`, `a\\b` and a symlinked folder pointing outside the vault are refused on the given field, and nothing is created outside the vault. |
| **FR-20** | Vault interplay (E9) | Phase 3 holds no persistent file handles or watchers: every open handle is closed in `finally`, and scan iterators are released at the end of the request. Note absolute paths are always `vault.path + relative_path`, so a vault rename needs no note changes. Removing a vault (unregister or trash) deletes its note records. | After a vault rename, opening a note works under the same UUID. After a vault removal, `Note::count()` for that vault is 0 and the files are unchanged. |
| **FR-21** | Identity exposure | Routes bind `{note:uuid}` and `{vault:uuid}` with `whereUuid`. Props never contain `id` or `vault_id`. | Integer IDs in URLs give 404. Note props have no `id` or `vault_id` keys. |
| **FR-22** | Boundaries | Filesystem calls live only in `FileStorageService`, and hashing only in `FileHashService`. `NoteService` and `VaultIndexService` use no raw filesystem functions. Controllers and Vue have no path or filesystem logic. | Pest arch rules pass (see plan T1/T4/T5). |

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - No auth (ADR `local-app-without-authentication`).
  - Every path is confined to the vault (FR-19).
  - The note content is rendered with text interpolation only (no `v-html`).
  - Nothing is ever overwritten; renames never replace an existing file.
- **Performance**:
  - Re-index is synchronous and hashes by streaming. It should handle about 2,000 notes of about 10 KB in a few seconds on SSD (checked manually).
  - `tree` and `folders` are lazy closure props, so opening a note with a partial reload (`only: ['note']`) doesn't rescan.
  - The preview is capped at 1 MiB.
- **Accessibility & UX**:
  - Single-root Vue components.
  - Tree items are buttons or links reachable by keyboard; each folder toggle has an `aria-label`.
  - Dialogs follow the Phase 2 patterns. Errors appear inline on fields; non-form failures appear as toasts.
- **Reliability & Data Integrity**:
  - Every file operation is checked afterwards.
  - Compensation never deletes user content: it only renames back, or removes an empty file this call created.
  - Re-index is a single transaction; deletes, then updates, then inserts.
  - Unreadable entries are never treated as deleted.

## 6. Technical Constraints & Context
- Laravel 13.33 / PHP 8.4, Inertia v3 (laravel 3.3.4, vue3 3.7.1), Vue 3.5, Tailwind v4, Wayfinder 0.1.21, Pest 5.2, PHPStan (Larastan 3.12), SQLite with FK constraints on (`DB_FOREIGN_KEYS` default true), NativePHP desktop 2.3.1.
- Existing pieces reused:
  - `FileStorageService` (path comparison, trash, `renameDirectory` pattern);
  - the `Trash` contract with `fakeTrash()`;
  - `StoragePathService::assertValidFolderName`;
  - the `VaultOperationException` pattern;
  - the `Inertia::flash('toast')` pattern;
  - shadcn-vue `collapsible`, `dropdown-menu`, `dialog`, `select`.
- Middleware `ConvertEmptyStringsToNull` turns a root folder `''` into `null`; services accept `?string`, and null means the vault root.
- Pint, `phpstan analyse` (level 7), `npm run types:check`, `build`, `check`.

## 7. Risks & Assumptions
- **Assumption**: one local user and one instance (§44). A time-of-check/time-of-use gap between the existence pre-check and `rename()` is accepted. POSIX and Windows `rename()` overwrite files, so the pre-check plus the check afterwards are mandatory.
- **Assumption**: vault folders live on one volume, so a move within a vault is an atomic rename.
- **Risk**: Unicode normalisation (macOS NFD filenames) can make case-insensitive matching miss. The mitigation is hash matching, and the issue is documented.
- **Risk**: large vaults make opening a vault slow. Indexing is synchronous and cheap per file, and a background job is the Phase 5 upgrade path.
- **Risk**: `rename()` on Windows fails when the file is open in some programs. The check afterwards returns a clean error with nothing changed.
- **Risk**: an external rename plus an edit loses the UUID (E6). This is accepted for v1; Phase 5 watcher rename events can improve it.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [ ] Approved to proceed to Planning (`plan.md`), pending E1–E10
