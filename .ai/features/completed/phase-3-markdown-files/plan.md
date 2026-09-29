# Plan: Phase 3: Markdown Filesystem

## Metadata
- **Feature Name**: Phase 3: Markdown Filesystem
- **Feature ID**: mdv-p3
- **Master Plan Phase**: **Implements Master Plan Phase 3, Markdown Filesystem (`docs/Masterplan.md` §52; §13–18, §23, §38–43, §60 Rules 1–5/8–10, §62)**
- **Author**: System Analyst
- **Created Date**: 2026-09-28
- **Task Complexity**: Level 3, Complex Development
- **Requirements**: `requirements.md`
- **Status**: COMPLETE (E1–E10 approved 2026-09-28; QA round 1 PASS; user desktop-verified 2026-09-28; follow-ups QA-P3-01, QA-P3-02 open)

---

## 1. Summary
This plan adds a `notes` registry (§13), which holds only metadata, relative paths and a SHA-256 hash. It adds three services:
- `FileHashService`;
- `VaultIndexService`, which scans, reconciles the registry with UUID-preserving matching, and lists the tree;
- `NoteService`, which creates, renames, moves and deletes notes (delete goes to the Recycle Bin), creates folders and deletes empty ones, and serves the read-only preview.

All raw filesystem work goes through new `FileStorageService` primitives:
- exclusive create;
- no-overwrite, checked file rename;
- a symlink-safe scan.

Re-index runs when a vault is opened and on demand. The Workspace gets a note-tree pane and a raw-Markdown read-only viewer.

---

## 2. Architecture & Design
- **Approach**:
  1. **Registry and indexing** (ADR `note-registry-and-indexing`):
     - The filesystem is the truth and the DB is a rebuildable index.
     - Matching order is exact path → unique case-insensitive path → unique identical hash, so UUIDs survive external renames and moves when the content is unchanged.
     - Folders are never stored (§15). The tree is a live directory scan merged with registry notes.
  2. **File operations** (ADR `note-file-operations`):
     - The file operation comes first, then a check afterwards, then the DB write.
     - Compensation only renames back, or removes an empty file this call created.
     - Delete uses the OS trash, never a permanent delete (it reuses the Phase 2 `Trash` contract).
     - No handles or watchers are held (the Phase 2 ADR follow-up).
  3. **Layering**:
     - Controllers are thin and map `NoteOperationException` to `ValidationException` on the exception's field.
     - Vue receives opaque `/`-separated relative paths from the server and never joins or splits them.
  4. **Workspace**:
     - `WorkspaceController` also serves `GET /notes/{note:uuid}`, through a nullable `?Note $note = null` parameter.
     - `tree` and `folders` are closure props, so the tree's note links use a partial reload (`only: ['note']`) and don't rescan.
- **Alternatives Considered**:
  - Identity:
    - frontmatter `id:` in files: rejected, because it writes to user files and pollutes portability;
    - a `.mdvault/index.json` sidecar: rejected; it adds a second source of truth, and it is a Phase 6 concern;
    - path-only identity: rejected, because every external rename would lose its UUID.
  - Delete:
    - an app-level `.mdvault-trash` folder: rejected. It clutters vaults and sync tools, grows without limit, and differs from vault removal (C1);
    - permanent unlink: rejected (data safety).
  - Tree:
    - registry-only: rejected, because empty folders would be invisible after "Create folder";
    - a folders table: rejected by §15.
  - Indexing:
    - on every Workspace GET: rejected (cost);
    - a queued job: deferred to Phase 5 (it needs progress UI and a worker).
  - Tree in `AppSidebar`: rejected (E8). It would be a shared prop scanning on every page, and it breaks in icon-collapsed mode.
- **Decision Records**:
  - `.ai/decisions/note-registry-and-indexing.md` (new)
  - `.ai/decisions/note-file-operations.md` (new)
  - `.ai/decisions/vault-removal-and-rename-semantics.md` (follow-up line updated in T0)
  - `.ai/decisions/service-layer-architecture.md` (conventions followed; `IndexResult` goes in `app/Support`)

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `notes` | create | `id` PK; `uuid` uuid **unique**; `vault_id` `foreignId()->constrained()->cascadeOnDelete()`; `title` string(255); `filename` string(255); `relative_path` text; `extension` string(20); `mime_type` string(100) default `'text/markdown'`; `file_size` unsignedBigInteger default 0; `file_hash` string(64); `is_encrypted` boolean default false; timestamps. **unique(`vault_id`,`relative_path`)**; index(`vault_id`,`file_hash`). **No `content` column.** |
| `vaults` | none | New `notes()` relation only |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Migration (new) | `database/migrations/<ts>_create_notes_table.php` | Schema above |
| Model (new) | `app/Models/Note.php` | `HasFactory`, `HasUuids` (`uniqueIds: ['uuid']`), fillable, `$hidden = ['id','vault_id']`, casts, `vault(): BelongsTo` |
| Model (modify) | `app/Models/Vault.php` | `notes(): HasMany` plus `@property-read` PHPDoc |
| Factory (new) | `database/factories/NoteFactory.php` | Valid defaults |
| Service (new) | `app/Services/FileHashService.php` | SHA-256 |
| Service (modify) | `app/Services/FileStorageService.php` | File primitives and scan (T3) |
| Support (new) | `app/Support/IndexResult.php` | Immutable re-index counts and summary text |
| Exception (new) | `app/Exceptions/NoteOperationException.php` | User-facing note, folder and index failures with `field()` |
| Service (new) | `app/Services/VaultIndexService.php` | Ignore rules, `reindex`, `browse` |
| Service (new) | `app/Services/NoteService.php` | Note and folder operations, preview, present |
| Requests (new) | `app/Http/Requests/Notes/{NoteNameRules,StoreNoteRequest,UpdateNoteRequest,MoveNoteRequest,StoreFolderRequest,DestroyFolderRequest}.php` | Shape validation |
| Controllers (new) | `app/Http/Controllers/{NoteController,FolderController,VaultIndexController}.php` | Thin HTTP |
| Controllers (modify) | `app/Http/Controllers/{WorkspaceController,VaultController,ExistingVaultController}.php` | Workspace props and note route; re-index after open, create or add |
| Routes (new) | `routes/notes.php` (required from `routes/web.php`) | See the route table |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types (new) | `resources/js/types/notes.ts` (export from `types/index.ts`) | `NoteTreeNode`, `NoteDetail`, `NotePreviewState` |
| Component (new) | `resources/js/components/notes/NoteTree.vue` | Pane header (New note, New folder, Re-index), the tree, and dialog hosting through provide/inject |
| Component (new) | `resources/js/components/notes/NoteTreeItem.vue` | Recursive folder or note row with a dropdown menu |
| Module (new) | `resources/js/components/notes/noteTreeActions.ts` | Typed `InjectionKey` for the action host |
| Components (new) | `resources/js/components/notes/{CreateNoteDialog,CreateFolderDialog,RenameNoteDialog,MoveNoteDialog,DeleteNoteDialog,DeleteFolderDialog}.vue` | Controlled dialogs (`v-model:open`) |
| Component (new) | `resources/js/components/notes/NoteViewer.vue` | Read-only raw Markdown and its states |
| Page (modify) | `resources/js/pages/Workspace.vue` | Two-pane layout while a vault is active |

### Routes (`routes/notes.php`; web middleware; `->whereUuid(...)` on every parameter)
| Method | URI | Name | Controller@action |
|---|---|---|---|
| GET | `/notes/{note:uuid}` | `notes.show` | `WorkspaceController` (invokable) |
| PATCH | `/notes/{note:uuid}` | `notes.update` | `NoteController@update` (rename) |
| POST | `/notes/{note:uuid}/move` | `notes.move` | `NoteController@move` |
| DELETE | `/notes/{note:uuid}` | `notes.destroy` | `NoteController@destroy` |
| POST | `/vaults/{vault:uuid}/notes` | `vaults.notes.store` | `NoteController@store` |
| POST | `/vaults/{vault:uuid}/folders` | `vaults.folders.store` | `FolderController@store` |
| DELETE | `/vaults/{vault:uuid}/folders` | `vaults.folders.destroy` | `FolderController@destroy` (body `path`) |
| POST | `/vaults/{vault:uuid}/reindex` | `vaults.reindex` | `VaultIndexController` (invokable) |

---

## 3. Implementation Tasks
*The tasks are ordered, and each one leaves the suite green.*
- After each task:
  - run `php vendor/bin/pint --dirty --format agent` and that task's tests;
  - after any route change, run `php artisan wayfinder:generate --with-form --no-interaction`.
- Every test that touches the filesystem uses the Phase 2 harness:
  - `beforeEach`: a temp dir `sys_get_temp_dir()/mdvault-<area>-<random>`, then `fakeDocumentsDirectory(<tmp>/Documents)`, and create vaults with `app(VaultService::class)->create('Work')`;
  - `afterEach`: `File::deleteDirectory($tmp)`.
  - Compare paths with `realpath()`.
- Never run `migrate:fresh` against the desktop DB.
- Read `docs/Masterplan.md` §13–18, §23, §38–43 and both new ADRs before starting.

- [x] **T0: Preconditions (orchestrator/user)**
  - Record the E1–E10 answers in §6. If E2 is (a), skip the folder-delete parts of T5, T6 and T7. If E8 is (b), stop and ask the analyst for a revision.
  - Save both new ADRs.
  - In `.ai/decisions/vault-removal-and-rename-semantics.md` → Follow-ups, replace "Phase 3: release file watchers and open handles on a vault before renaming it." with "Phase 3 (delivered): MDVault holds no watchers or persistent handles; every handle is closed in `finally` (ADR `note-file-operations`). Phase 5 must stop its watcher for a vault before renaming or trashing that vault."
  - Suggested: `git add -A && git commit -m "Before Phase 3"`.
  - Covers: —

- [ ] **T1: `notes` schema, `Note` model, factory, relations, arch rules**
  - Command: `php artisan make:model Note -mf --no-interaction`. Check that the paths are `app/Models/Note.php`, `database/factories/NoteFactory.php` and `database/migrations/*_create_notes_table.php`.
  - Migration: exactly the §2 schema. `down()` drops the table.
  - `Note`:
    - `use HasFactory, HasUuids;`
    - `uniqueIds(): ['uuid']`
    - `$fillable = ['vault_id','title','filename','relative_path','extension','mime_type','file_size','file_hash','is_encrypted']`
    - `$hidden = ['id','vault_id']`
    - `casts()`: `file_size` → `integer`, `is_encrypted` → `boolean`
    - `vault(): BelongsTo`
    - PHPDoc `@property` for every column, plus `@property-read Vault $vault`
    - `public const MIME_TYPE = 'text/markdown';`
  - `Vault`: `notes(): HasMany` plus `@property-read \Illuminate\Database\Eloquent\Collection<int, Note> $notes`.
  - `NoteFactory`:
    - `vault_id` => `Vault::factory()`
    - `title` = `Str::title(fake()->unique()->word())`
    - `filename` = `{title}.md`, `relative_path` = the filename
    - `extension` `'md'`, `mime_type` `Note::MIME_TYPE`, `file_size` 0
    - `file_hash` = `'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855'` (a literal, so no hashing function is used here)
    - `is_encrypted` false
  - `tests/Feature/DatabaseSchemaTest.php`:
    - add `notes` to the present dataset;
    - add a column test for all 13 columns;
    - `expect(Schema::hasColumn('notes','content'))->toBeFalse()`;
    - the absent dataset becomes `vault_encryption`, `backups`.
  - `tests/Feature/Models/NoteTest.php` (`php artisan make:test Models/NoteTest --pest --no-interaction`):
    - the UUID is 36 characters and unchanged after `update(['title'=>'X'])`;
    - `toArray()` has no `id` or `vault_id`;
    - `$note->vault->is($vault)`, and `$vault->notes` contains it;
    - deleting the vault record deletes the note (the FK cascade works on SQLite);
    - inserting two notes with the same `(vault_id, relative_path)` throws `QueryException`.
  - `tests/Unit/ArchitectureTest.php`:
    - change the Vault rule's list to `['App\Services','App\Http\Controllers','App\Models','Database\Factories']`;
    - add `arch('note records are only used by services, controllers, models and factories')->expect('App\Models\Note')->toOnlyBeUsedIn(['App\Services','App\Http\Controllers','App\Models','Database\Factories']);`
  - Run `php artisan migrate --no-interaction` and `php artisan native:migrate --no-interaction`.
  - Covers: FR-01, FR-02, FR-21

- [ ] **T2: `FileHashService`**
  - Command: `php artisan make:class Services/FileHashService --no-interaction` (`final`, no constructor).
  - API:
    ```php
    public const ALGORITHM = 'sha256';
    /** Lowercase hex SHA-256 of the file (streamed), or null if it can't be read or isn't a regular file. */
    public function hashFile(string $path): ?string;   // is_file check, then @hash_file(self::ALGORITHM, $path) ?: null
    public function hashString(string $contents): string; // hash(self::ALGORITHM, $contents)
    /** Constant-time comparison of the file's current hash with $expected; false when unreadable. */
    public function matches(string $path, string $expected): bool; // hash_equals
    ```
  - `ArchitectureTest`: `arch('files are hashed only in FileHashService')->expect('hash_file')->toOnlyBeUsedIn('App\Services\FileHashService');`
  - Tests: `tests/Feature/Services/FileHashServiceTest.php`:
    - empty file → `e3b0c442…b855`;
    - `"abc"` file → `ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad`;
    - `hashString('abc')` gives the same;
    - a missing path → null; a directory → null;
    - `matches` true or false;
    - a 3 MB file's hash equals `hash_file('sha256', …)` (streaming works).
  - Covers: FR-03

- [ ] **T3: `FileStorageService` file primitives and scan**
  - Add these methods. The class stays `final`, keeps its constructor, and no Phase 2 method changes.
    ```php
    public function isSymlink(string $path): bool;                  // is_link($path)
    /** samePath, or both regular files with equal non-zero ino and dev (mirrors isSameDirectory). */
    public function isSameFile(string $a, string $b): bool;
    /** Exclusive create: @fopen($path, 'x'); write $contents; fclose in finally. Never overwrites. Returns is_file($path) after clearstatcache(). */
    public function createFile(string $path, string $contents = ''): bool;
    /** Compensation only: unlink $path ONLY if it is a regular file of size 0. Never deletes content. */
    public function deleteNewEmptyFile(string $path): bool;
    /** file_get_contents in try/catch; null on failure. */
    public function read(string $path): ?string;
    public function size(string $path): ?int;                       // clearstatcache(); is_file ? filesize : null
    /** $root joined with a '/'-separated relative path using DIRECTORY_SEPARATOR; '' returns $root. No validation. */
    public function joinRelative(string $root, string $relative): string;
    /** No-overwrite file rename; see below. */
    public function renameFile(string $from, string $to): bool;
    /**
     * Walks $root without following symlinks/junctions. $accept(string $relativePath, string $name, bool $isDirectory): bool
     * decides inclusion; rejected directories are not descended.
     * @return array{files: list<array{path: string, size: int}>, directories: list<string>, unreadable: list<string>}
     * Paths are '/'-separated, relative to $root, each list sorted with strcmp. `unreadable` lists relative
     * directories that could not be listed ('' = the root itself).
     */
    public function scan(string $root, callable $accept): array;
    ```
  - `renameFile`: the same algorithm as `renameDirectory`.
    1. If `exists($to) && ! isSameFile($from, $to)`, return false.
    2. **Case-only change**, two steps via `siblingPath($from, '.mdvault-rename-'.Str::random(12))`, and restore on step 2 failure.
    3. Otherwise a single move.
    4. Every move is `$this->files->move($x, $y)` inside `try/catch \Throwable → false`, in a private `attemptFileMove()`.
    5. Return the check afterwards: `clearstatcache(); is_file($to) && ($caseOnly || ! file_exists($from))`.
    - PHPDoc: PHP `rename()` **overwrites** an existing file on both POSIX and Windows, so the pre-check is mandatory. A Windows lock makes this return false with nothing changed.
  - `scan`:
    - an explicit stack, iterating each directory with `new \FilesystemIterator($abs, \FilesystemIterator::SKIP_DOTS)` inside `try/catch (\Throwable)`;
    - on failure, append that relative dir to `unreadable` and continue;
    - per entry: `isLink()` → skip, and never call `$accept`;
    - `isDir()` → if accepted, add to `directories` and push it;
    - `isFile()` → if accepted, add to `files` with `size` = `getSize()` (0 if that throws).
    - The iterator objects go out of scope per directory, so no handle outlives the call.
  - `tests/Pest.php` helpers:
    ```php
    /** Substitute Filesystem so move() returns false on the given 1-based call numbers (simulates an OS lock on a file). Resolve services AFTER calling this. */
    function failFileMoves(array $failOnCalls = [1]): object { /* same shape as failFolderRenames, overriding move($path, $target): bool */ }
    /** Create files under $root from ['rel/path.md' => 'contents'] (directories created as needed). Keys ending in '/' create empty directories. */
    function writeVaultFiles(string $root, array $files): void
    ```
  - Tests to add to `tests/Feature/Services/FileStorageServiceTest.php`:
    1. `createFile` creates a file with its contents, and refuses an existing file (the original content is intact).
    2. `deleteNewEmptyFile` removes a 0-byte file and refuses a non-empty one (its content is intact).
    3. `renameFile` moves a file with its content; the source is gone.
    4. `renameFile` refuses an existing target file (both files are intact).
    5. Case-only `a.md` → `A.md` gives `scandir` containing `A.md` and not `a.md`, with no `.mdvault-rename-*` left.
    6. `failFileMoves([1])` → false, and the source is intact.
    7. Case-only with `failFileMoves([2])` → false, `a.md` still exists exactly, and no temp file is left.
    8. `isSameFile` (upper-case variant `->onlyOnWindows()`).
    9. `joinRelative` joins `Projects/HRMIS.md` with the native separator.
    10. `scan` over `writeVaultFiles`: nested files, an empty dir listed in `directories`, `/` separators, and a sorted order. The accept callback prunes a directory, and its children are not visited (assert that the callback was never called with a child path).
    11. `scan` skips a symlinked directory and a symlinked file (`->skipOnWindows()`; create them with `symlink()`).
    12. `scan` of a missing root → `unreadable === ['']`.
    13. `read` of a missing file → null; `size` of a missing file → null.
  - Covers: FR-04, FR-10, FR-12, FR-13, FR-19, FR-20, FR-22

- [ ] **T4: `NoteOperationException`, `IndexResult`, `VaultIndexService`**
  - Commands:
    - `php artisan make:exception NoteOperationException --no-interaction`: make it `final`, extend `\RuntimeException`, and use the same private constructor plus `field()` pattern as `VaultOperationException`.
    - `php artisan make:class Support/IndexResult --no-interaction`.
    - `php artisan make:class Services/VaultIndexService --no-interaction`.
  - `NoteOperationException` constructors. Messages contain only the user's own names and paths.

    | Constructor | Field | Message |
    |---|---|---|
    | `invalidName()` | name | "Enter a valid name. It is used as the file or folder name, so it can't contain < > : \" / \\ \| ? *, start with a dot or a space, end with a space or a dot, or be a reserved system name (e.g. CON, NUL). 100 characters or fewer. For notes, “.md” is added for you." |
    | `invalidFolder(string $field)` | given | "Choose a folder inside this vault." |
    | `folderNotFound(string $folder, string $field)` | given | "The folder “{folder}” can't be found in this vault. Re-index the vault to refresh the list." (`{folder}` = "the vault's top level" when `''`) |
    | `targetExists(string $relative, string $field)` | given | "“{relative}” already exists in this vault. Choose another name." |
    | `vaultUnavailable(string $path)` | vault | "The vault folder can't be found or read at {path}. Reconnect the drive, then try again." |
    | `noteFileMissing(string $relative, string $field)` | given | "The file for this note is missing ({relative}). Re-index the vault to update the list." |
    | `createFailed(string $relative, string $field)` | given | "“{relative}” could not be created. Check that MDVault can write to the vault folder." |
    | `moveFailed(string $field)` | given | "The note couldn't be renamed or moved. Close any programs using it and try again. Nothing was changed." |
    | `moveInterrupted(string $from, string $to, string $field)` | given | "The note couldn't be renamed and is no longer at {from}. Look for {to} or a file starting with “.mdvault-rename-” next to it, rename it back, then re-index the vault." |
    | `moveRollbackFailed(string $to, string $field)` | given | "The file was moved to {to}, but MDVault couldn't save the change or move it back. Re-index the vault to pick up its new location." |
    | `trashUnavailable()` | note | "Deleting notes moves them to the Recycle Bin / Trash, which is only available in the desktop app." |
    | `trashFailed(string $relative)` | note | "“{relative}” could not be moved to the Recycle Bin / Trash. Close any programs using it and try again. The note was not deleted." |
    | `folderNotEmpty(string $relative)` | path | "The folder “{relative}” isn't empty (it may contain hidden or non-Markdown files). Only empty folders can be deleted." |
    | `cannotDeleteRoot()` | path | "The vault's top-level folder can't be deleted here. Remove the vault from the Vaults page instead." |
    | `folderDeleteFailed(string $relative)` | path | "The folder “{relative}” could not be deleted. Close any programs using it and try again." |

  - `App\Support\IndexResult`:
    - `final readonly class` with `public int $added, $updated, $moved, $removed, $unchanged, $skipped`;
    - `hasChanges(): bool`;
    - `summary(): string`:
      - if nothing changed: "The index is already up to date.";
      - otherwise: "Index updated: {added} added, {updated} updated, {moved} moved or renamed, {removed} removed.";
      - append " {skipped} item(s) couldn't be read and were left as they were." when `skipped > 0`.
  - `VaultIndexService` (`final`):
    - Constructor: `FileStorageService $files`, `FileHashService $hashes`, `DatabaseManager $database`.
    - `public function isIgnoredName(string $name, bool $isDirectory): bool` returns `str_starts_with($name, '.') || ($isDirectory && strcasecmp($name, 'node_modules') === 0)`.
    - `public function isIndexableFileName(string $name): bool` returns `! isIgnoredName($name, false) && strcasecmp(pathinfo($name, PATHINFO_EXTENSION), 'md') === 0`.
    - `public function reindex(Vault $vault): IndexResult`, in this exact order:
      1. If `! $this->files->isDirectory($vault->path)` → `NoteOperationException::vaultUnavailable($vault->path)`.
      2. `$scan = $this->files->scan($vault->path, fn ($rel, $name, $isDir) => $isDir ? ! $this->isIgnoredName($name, true) : $this->isIndexableFileName($name))`. If `in_array('', $scan['unreadable'], true)` → `vaultUnavailable`.
      3. Hash every scanned file with `hashFile(joinRelative(...))`. A null hash marks the file **unreadable**: `skipped++`, and it is excluded from matching (a record at that exact path is kept untouched).
      4. `$rows = $vault->notes()->get()->keyBy('relative_path')` (remove the unreadable-file paths from it).
      5. **Exact**: for each file whose path is in `$rows`:
         - if `file_hash` or `file_size` differs → queue an update (`updated++`);
         - otherwise `unchanged++`.
         - Consume both.
      6. **Case-insensitive**: group the leftover rows and files by `mb_strtolower(path)`. Where a key has exactly one row and one file → queue a move (`moved++`). Consume.
      7. **Hash**: group the leftovers by hash (`file_hash` for rows). Where a hash has exactly one row and one file → queue a move (`moved++`). Consume.
      8. Leftover rows: if the path is under any `unreadable` dir (`str_starts_with($path, $dir.'/')`) → keep them (`skipped++`); otherwise queue a delete (`removed++`).
      9. Leftover files → queue an insert (`added++`).
      10. One `$this->database->connection()->transaction(...)` applying **deletes first, then updates and moves, then inserts** (`$vault->notes()->create([...])`, so `HasUuids` runs).
      - A move sets `relative_path`, `filename` (basename), `title` (`pathinfo(filename, PATHINFO_FILENAME)`), `extension` (lower-cased), `file_hash` and `file_size`. An insert sets those plus `mime_type = Note::MIME_TYPE` and `is_encrypted = false`.
      - Add a class PHPDoc that references ADR `note-registry-and-indexing`.
    - `public function browse(Vault $vault, ?string $openPath = null): array`, returning `array{tree: list<array<string, mixed>>, folders: list<string>}`:
      - Scan with the same accept rule, but for files accept nothing (directories only), which is cheaper.
      - Folders = scanned directories ∪ the parent folders of every registry note path (so a folder is still shown if a scan error hides it).
      - Build nested nodes:
        - folder: `{type:'folder', name, path, open: bool, children}`, where `open` = `$openPath !== null && str_starts_with($openPath, path.'/')`;
        - note: `{type:'note', uuid, title, path}`.
      - Sorting: folders first, then `strnatcasecmp` on name or title.
      - `folders` = `['']` followed by every folder path sorted with `strnatcasecmp`.
      - A missing vault gives `['tree' => [], 'folders' => ['']]`.
      - Add a PHPDoc array shape for the node types.
  - `ArchitectureTest`: `arch('index and note services use no raw filesystem functions')->expect(['App\Services\VaultIndexService','App\Services\NoteService'])->not->toUse(['file_get_contents','file_put_contents','fopen','unlink','rmdir','mkdir','rename','copy','scandir','hash_file','Illuminate\Support\Facades\File','Illuminate\Support\Facades\Storage']);` (NoteService is created in T5, and the rule passes vacuously until then.)
  - Tests: `tests/Feature/Services/VaultIndexServiceTest.php`, with a vault `Work` and `writeVaultFiles($vault->path, …)`:
    1. **Initial index**:
       - `Readme.md`, `Projects/HRMIS.md` ("# HRMIS"), `Projects/Sub/Deep.md`, `B.MD`;
       - 4 notes; `relative_path` values are exactly `'Projects/HRMIS.md'` and so on (forward slashes on every OS);
       - `title` `HRMIS`; `extension` `'md'` for `B.MD`; `filename` `B.MD`;
       - `file_hash === hash_file('sha256', abs)`; `file_size === filesize`; `mime_type` `text/markdown`;
       - the result has `added === 4`.
    2. **Ignore rules**, a dataset of `x.txt`, `.git/c.md`, `.obsidian/d.md`, `node_modules/e.md`, `Node_Modules/f.md`, `.hidden.md`, `.mdvault-rename-abc` → none indexed.
    3. **Idempotent**: a second `reindex` → `unchanged === 4`, `hasChanges()` false, and `updated_at` unchanged.
    4. **External edit** → `updated === 1`; the new hash and size are stored; the UUID is the same.
    5. **External delete** → `removed === 1`; the record is gone.
    6. **External create** → `added === 1`.
    7. **External rename and move** with the same content (`Projects/HRMIS.md` → `Archive/HRMIS-old.md`) → `moved === 1`, the **same UUID**, and the new path, title and filename.
    8. **Case-only external rename** (`Readme.md` → `README.md`, done with a two-step rename in the test) → `moved === 1`, same UUID.
    9. **Rename plus edit** → `removed 1`, `added 1`, and a new UUID.
    10. **Ambiguous hash**: two records `a.md` and `b.md` with identical content; delete both and create `c.md` with the same content → no pairing (`removed 2`, `added 1`).
    11. **Rebuild**: `Note::query()->delete()`, then `reindex` → all 4 are back with the correct paths and hashes (FR-07).
    12. **Missing vault**: `File::deleteDirectory($vault->path)` → `NoteOperationException` with field `vault`; record count unchanged.
    13. **Symlinked folder** is not indexed (`->skipOnWindows()`).
    14. **Unicode and spaces**: `Café notes/Été 2026.md` → indexed with the exact relative path.
    15. **`browse`**:
        - an empty `Archive/` folder appears;
        - order is folders first (`Archive`, `Projects`), then `B`, `Readme`;
        - `Projects` is `open` when `$openPath = 'Projects/HRMIS.md'`;
        - `folders` equals `['', 'Archive', 'Projects', 'Projects/Sub']`;
        - no node has an `id` key.
    16. `IndexResult::summary()` text for the no-change case, the change case and the skipped case.
  - Covers: FR-04, FR-05, FR-06, FR-07, FR-09, FR-17, FR-22

- [ ] **T5: `NoteService`**
  - Command: `php artisan make:class Services/NoteService --no-interaction` (`final`).
  - Constructor: `FileStorageService $files`, `FileHashService $hashes`, `VaultIndexService $index`, `StoragePathService $paths`, `DatabaseManager $database`.
  - Constants: `PREVIEW_LIMIT = 1_048_576`, `EXTENSION = 'md'`.
  - Public API:
    ```php
    /** Validates a note name; strips one trailing ".md" (any case); returns the stem. @throws NoteOperationException invalidName */
    public function assertValidNoteName(string $name): string;
    /** Validates a folder name (portable rules, no leading dot, not node_modules). @throws NoteOperationException invalidName */
    public function assertValidFolderName(string $name): void;
    public function create(Vault $vault, ?string $folder, string $name): Note;
    public function rename(Note $note, string $name): Note;
    public function move(Note $note, ?string $folder): Note;
    /** @return bool true = file moved to trash, false = file was already missing (record removed) */
    public function delete(Note $note): bool;
    /** @return string the new folder's relative path */
    public function createFolder(Vault $vault, ?string $parent, string $name): string;
    public function deleteFolder(Vault $vault, string $path): void;
    /** @return array{content: ?string, state: 'ok'|'missing'|'too_large'|'unreadable', is_valid_utf8: bool} */
    public function preview(Note $note): array;
    /** @return array{uuid: string, title: string, filename: string, relative_path: string, folder: string, file_size: int, file_hash: string, updated_at: ?string} */
    public function present(Note $note): array;
    public function absolutePath(Note $note): string; // joinRelative($note->vault->path, $note->relative_path)
    public function canTrash(): bool;                 // $this->files->canTrash()
    ```
  - Private helpers:
    - `assertVaultAvailable(Vault)`: not a directory → `vaultUnavailable`.
    - `resolveFolder(Vault $vault, ?string $folder, string $field): array{0: string, 1: string}` returns `[normalizedRelative, absolute]`:
      1. `null` or `''` → `''`, i.e. the vault path.
      2. Reject → `invalidFolder($field)` when there is a backslash, a NUL, a leading `/`, a drive letter `^[A-Za-z]:`, or any segment that is `''`, `.` or `..` after `trim($folder, '/')` and `explode('/')`.
      3. `$abs = joinRelative($vault->path, $rel)`. If it is not a directory, or `isSymlink` on any prefix segment (walk the segments), → `folderNotFound($rel, $field)`.
      4. `! isSameOrInside(canonical($abs), $vault->path)` → `invalidFolder($field)`.
    - `relativeFor(string $folder, string $filename): string` returns `$folder === '' ? $filename : $folder.'/'.$filename`.
    - `assertNoConflict(Vault $vault, string $relative, string $absolute, string $field, ?Note $except, ?string $fromAbsolute)`:
      - if `exists($absolute)` and not (`$fromAbsolute !== null && isSameFile($fromAbsolute, $absolute)`) → `targetExists`;
      - if any other note of the vault has `mb_strtolower(relative_path) === mb_strtolower($relative)` → `targetExists`. Use a PHP comparison over `pluck('relative_path','uuid')`.
  - Name validation:
    - `assertValidNoteName` strips `/\.md$/i` once. Then `assertValidFolderName($stem)` (this class) → `invalidName`.
    - `assertValidFolderName` rules:
      - `$this->paths->assertValidFolderName($name)`, mapping `InvalidStorageRootException` → `invalidName`;
      - then `str_starts_with($name, '.')` → `invalidName`;
      - then `strcasecmp($name, 'node_modules') === 0` → `invalidName`.
  - `create()`, in order:
    1. `assertVaultAvailable`.
    2. `$stem = assertValidNoteName($name)`.
    3. `[$folderRel, $folderAbs] = resolveFolder($vault, $folder, 'folder')`.
    4. `$filename = $stem.'.md'`, `$relative`, `$abs`.
    5. `assertNoConflict(..., 'name', null, null)`.
    6. `! createFile($abs)` → `createFailed($relative, 'name')`.
    7. `$hash = hashFile($abs) ?? hashString('')`, `$size = size($abs) ?? 0`.
    8. Insert the DB record:
       ```php
       try { return $vault->notes()->create([... 'mime_type' => Note::MIME_TYPE, 'is_encrypted' => false]); }
       catch (\Throwable $e) { $this->files->deleteNewEmptyFile($abs); throw $e; }
       ```
  - `rename()` / `move()` both call a private `relocate(Note $note, string $folderRel, string $folderAbs, string $stem, string $field)`:
    1. `assertVaultAvailable`. `$from = absolutePath($note)`. If it is not a file → `noteFileMissing($note->relative_path, $field)`.
    2. `$filename = $stem.'.md'`, `$relative`, `$to = joinRelative($note->vault->path, $relative)`.
    3. If `$relative === $note->relative_path` (exact), return `$note`. No filesystem work.
    4. `assertNoConflict($vault, $relative, $to, $field, $note, $from)`.
    5. `! renameFile($from, $to)` → if `isFile($from)` → `moveFailed($field)`; otherwise → `moveInterrupted($note->relative_path, $relative, $field)`.
    6. Keep `$original = $note->only(['relative_path','filename','title','extension'])`. Inside `try { transaction(fn () => $note->update([...])) }`:
       ```php
       catch (\Throwable $e) {
           $note->fill($original);
           if ($this->files->renameFile($to, $from)) { throw $e; }
           report($e);
           throw NoteOperationException::moveRollbackFailed($relative, $field);
       }
       ```
    - `rename()` calls `assertValidNoteName($name)`, then the current folder: `dirname` of the relative path via `strrpos('/')`, with `''` for the root. It then resolves that folder with field `name`.
    - `move()` calls `resolveFolder($note->vault, $folder, 'folder')` and keeps the current stem (`$note->title`, which equals `pathinfo(filename, FILENAME)`). **Keep the existing on-disk extension case.** For move, use the filename unchanged instead of `$stem.'.md'`: pass `$filename` into `relocate`. Signature: `relocate(Note, string $folderRel, string $filename, string $field)`.
  - `delete()`:
    1. `assertVaultAvailable`. `$abs = absolutePath($note)`.
    2. If `! exists($abs)` → `$note->delete()`, return false.
    3. `! canTrash()` → `trashUnavailable()`.
    4. Guard: `isFile($abs)` and `isSameOrInside($abs, vault path)` and not `samePath($abs, vault path)`; otherwise `invalidFolder('note')`.
    5. `! $this->files->moveToTrash($abs)` → `trashFailed($note->relative_path)`. The record is kept.
    6. `$note->delete()`, return true.
  - `createFolder()`:
    1. `assertVaultAvailable`.
    2. `assertValidFolderName($name)` (field `name`).
    3. `resolveFolder($vault, $parent, 'parent')`.
    4. `$relative`, `$abs`. If `exists($abs)` → `targetExists($relative, 'name')`.
    5. `! makeDirectory($abs)` → `createFailed($relative, 'name')`.
    6. Return `$relative`.
  - `deleteFolder()`:
    1. `assertVaultAvailable`.
    2. `[$rel, $abs] = resolveFolder($vault, $path, 'path')`. If `$rel === ''` → `cannotDeleteRoot()`.
    3. `! isEmptyDirectory($abs)` → `folderNotEmpty($rel)`.
    4. `! deleteEmptyDirectory($abs)` → `folderDeleteFailed($rel)`.
    5. Delete stale records whose `relative_path` starts with `$rel.'/'`, in a transaction.
  - `preview()`:
    1. `$abs`. If it is not a file → `['content' => null, 'state' => 'missing', 'is_valid_utf8' => true]`.
    2. `$size = size($abs)`.
    3. If `$size > PREVIEW_LIMIT`:
       - `$hash = hashFile($abs)`;
       - reconcile `file_size` and `file_hash` if they changed and the hash is not null;
       - return `too_large` with content null.
    4. `$content = read($abs)`; null → `unreadable`.
    5. `$hash = hashString($content)`. If `$hash !== $note->file_hash || strlen($content) !== $note->file_size` → `$note->update(['file_hash' => $hash, 'file_size' => strlen($content)])` (the filesystem wins, §18).
    6. `$valid = mb_check_encoding($content, 'UTF-8')`. If it is not valid → `$content = mb_scrub($content, 'UTF-8')`.
    7. Return `ok`.
  - `present()`: `folder` is the relative path's directory part (`''` for the root), and `updated_at` is `?->toIso8601String()`. Never include `id` or `vault_id`.
  - Tests: `tests/Feature/Services/NoteServiceTest.php`. Every scenario asserts that the UUID is unchanged where relevant, and every failure asserts that the disk and DB are unchanged.
    - **Create**:
      1. `create($vault, null, 'Meeting')` → `<vault>/Meeting.md` is 0 bytes; the record has `relative_path 'Meeting.md'`, `title 'Meeting'`, and hash `e3b0…`.
      2. In the folder `Projects` (pre-created) → `Projects/Meeting.md`.
      3. `'Plan.md'` and `'Plan.MD'` → `Plan.md` (no double extension).
      4. Invalid-name dataset: `''`, `'CON'`, `'a/b'`, `'x.'`, `' x'`, `'.hidden'`, 101 characters → field `name`; no file.
      5. An existing `meeting.md` on disk (a different case) → `targetExists` field `name`; the original content is intact. On Linux, check this through the DB-conflict path: index first, then create `MEETING`.
      6. Folder errors, a dataset on field `folder`: `'../x'`, `'C:/x'`, `'/abs'`, `'a\\b'`, `'a//b'`, `'Missing'` (not found).
      7. A symlinked folder that points outside the vault → field `folder`; nothing is created outside (`->skipOnWindows()`).
      8. DB failure: `Note::creating(fn () => throw new RuntimeException('db down'))` → a RuntimeException; the file does **not** exist; `Note::count() === 0`.
      9. Missing vault → field `vault`.
    - **Rename**:
      10. `a.md` → `rename('b')`: the file moved, the content is intact, and the record has path, filename and title `b`.
      11. Case-only `a` → `A`.
      12. The target exists → field `name`.
      13. File missing (deleted externally) → `noteFileMissing` field `name`.
      14. `failFileMoves([1])` then re-resolve the service → `moveFailed`; unchanged.
      15. DB failure after the move: `Note::saving` throws when `$note->exists` → rethrown; the file is back at `a.md`; the in-memory and `fresh()` records have the old path.
      16. Rollback failure: `failFileMoves([2])` plus the `saving` throw plus `Exceptions::fake()` → `moveRollbackFailed`; `b.md` exists (no data loss); `Exceptions::assertReported(RuntimeException::class)`. Call `Note::flushEventListeners()` before the final DB assertions (the Phase 2 lesson).
      17. Same name → no-op; no filesystem call (assert with a `failFileMoves([1])` fake whose `calls === 0`).
    - **Move**:
      18. `Projects/HRMIS.md` → `move('Servers')` → `Servers/HRMIS.md`; same UUID; filename `HRMIS.md` (§23 example).
      19. `move(null)` → root.
      20. A `B.MD` note moved keeps `B.MD`.
      21. A target folder that doesn't exist → field `folder`.
      22. The target already has `HRMIS.md` → `targetExists` field `folder`.
    - **Delete**:
      23. `fakeTrash()` → returns true; the file is gone; the record is gone; `$fake->trashed === [abs]`.
      24. `fakeTrash(deletes: false)` → `trashFailed` field `note`; the record is kept.
      25. `fakeTrash(available: false)` → `trashUnavailable`; nothing changes.
      26. File already deleted externally → returns false; the record is gone; no trash call.
    - **Folders**:
      27. `createFolder(null, 'Projects')` then `createFolder('Projects', 'Sub')` → both are directories.
      28. Existing → `targetExists`.
      29. Invalid names `.git`, `node_modules`, `CON` → field `name`.
      30. `deleteFolder('Archive')` on an empty folder → gone.
      31. A folder with `.DS_Store`, and one with `a.md` → `folderNotEmpty`; the contents are intact.
      32. `deleteFolder('')` → `cannotDeleteRoot`.
      33. Stale records under a deleted empty folder are removed.
    - **Preview**:
      34. `ok` with the exact content.
      35. Edit the file externally → the new content, and the record's `file_hash` and `file_size` are updated.
      36. Missing file → `missing`.
      37. A file of 1 MiB + 1 byte → `too_large`, content null.
      38. Invalid UTF-8 bytes `"a\xFFb"` → `is_valid_utf8` false, and the content is valid UTF-8 (JSON-encodable).
      39. `present()` has the exact keys and no `id` or `vault_id`; `folder` is `'Projects'` for `Projects/HRMIS.md`.
    - **Vault interplay (FR-20)**:
      40. After `VaultService::rename($vault, 'Office')`, `preview($note->fresh())['state'] === 'ok'`.
      41. `VaultService::remove($vault)` → `Note::count() === 0` and the files still exist.
  - Covers: FR-10 to FR-16, FR-18 to FR-20, FR-22

- [ ] **T6: HTTP layer, index triggers, Workspace props**
  - Commands:
    - `php artisan make:controller NoteController --no-interaction`
    - `php artisan make:controller FolderController --no-interaction`
    - `php artisan make:controller VaultIndexController --invokable --no-interaction`
    - `php artisan make:request Notes/StoreNoteRequest --no-interaction` (and the same for `UpdateNoteRequest`, `MoveNoteRequest`, `StoreFolderRequest`, `DestroyFolderRequest`)
    - Create the trait `app/Http/Requests/Notes/NoteNameRules.php` by hand.
  - `NoteNameRules`, the same shape as `VaultNameRules`:
    - `noteNameRules(NoteService $notes)`: `['bail','required','string','max:110', closure → $notes->assertValidNoteName($value), catch NoteOperationException → $fail($e->getMessage())]`
    - `folderNameRules(NoteService $notes)`: the same, with `max:100` and `assertValidFolderName`.
  - Requests (`authorize()` true; `rules(NoteService $notes)` method injection):
    - Store: `name` = noteNameRules; `folder` = `['nullable','string','max:1024']`.
    - Update: `name`.
    - Move: `folder` = `['nullable','string','max:1024']`.
    - StoreFolder: `name` = folderNameRules; `parent` = `['nullable','string','max:1024']`.
    - DestroyFolder: `path` = `['required','string','max:1024']`.
  - `routes/notes.php`: the §2 table. Add `require __DIR__.'/notes.php';` to `routes/web.php` **after** `vaults.php`. `whereUuid('note')` and `whereUuid('vault')` on every route.
  - Controllers. Each has a private `attempt(callable)` that maps `NoteOperationException` to `ValidationException::withMessages([$e->field() => $e->getMessage()])`, as in `VaultController`:
    - `NoteController::store(StoreNoteRequest, Vault $vault, NoteService)` → create → toast `Note “{title}” created.` → `to_route('notes.show', $note->uuid)`.
    - `update(UpdateNoteRequest, Note $note, NoteService)`:
      - rename;
      - toast `Note renamed.` if the path changed, otherwise `Nothing to change.`;
      - `to_route('notes.show', $note->uuid)`.
    - `move(MoveNoteRequest, Note, NoteService)`: move; toast `Note moved to {folder or “the top level”}.` or `Nothing to change.`; redirect to `notes.show`.
    - `destroy(Note, NoteService)`:
      - `$title` = the title;
      - `$trashed = attempt(delete)`;
      - toast `Note “{title}” moved to the Recycle Bin / Trash.`, or when `! $trashed`: `Note “{title}” was already gone from disk and has been removed from the list.`;
      - `to_route('workspace')`.
    - `FolderController::store(StoreFolderRequest, Vault, NoteService)`: `createFolder`; toast `Folder “{relative}” created.`; `back()`.
    - `FolderController::destroy(DestroyFolderRequest, Vault, NoteService)`: `deleteFolder`; toast `Folder deleted.`; `back()`.
    - `VaultIndexController::__invoke(Vault $vault, VaultIndexService $index)`:
      - `try { $result = reindex; toast success summary() }`;
      - `catch (NoteOperationException $e) { toast error message }`;
      - `back()`.
  - Index triggers:
    - `VaultController::open`: after a successful `open`, `try { $result = $index->reindex($vault); if ($result->hasChanges() || $result->skipped > 0) { toast success $result->summary() } } catch (NoteOperationException $e) { toast error message }`. Inject `VaultIndexService` as a method parameter.
    - `VaultController::store`: after `open`, run `reindex` inside the same try/catch. Ignore the result and keep the existing "created" toast.
    - `ExistingVaultController::store`: after `open`, `$result = reindex` (with the same catch). Toast `Vault “{name}” added. {added} note(s) indexed.`
  - `WorkspaceController::__invoke(SystemStatusService $systemStatus, SettingsService $settings, VaultService $vaults, VaultIndexService $index, NoteService $notes, ?Note $note = null): Response|RedirectResponse`:
    - `$current = $vaults->current()`.
    - If `$note !== null && ($current === null || ! $note->vault->is($current))` → `Inertia::flash('toast', ['type' => 'error', 'message' => "This note is in the vault “{$note->vault->name}”. Open that vault first."])` and `to_route('workspace')`.
    - `$active = $current !== null && $current->status === VaultStatus::Active`.
    - Props, keeping the existing ones:
      - `'tree' => fn () => $active ? $index->browse($current, $note?->relative_path)['tree'] : null`
      - `'folders' => fn () => $active ? $index->browse($current)['folders'] : []` (call `browse` once, memoised in a local `$browse = null` closure-cached variable, so a full render scans once)
      - `'note' => fn () => ($note && $active) ? [...$notes->present($note), ...$notes->preview($note)] : null`
      - `'canTrash' => $notes->canTrash()`
    - Laravel passes `null` for a defaulted, class-typed parameter that the route doesn't have. If `/` ever resolves an empty `Note`, stop and split into `index()`/`show()` methods, and record it.
  - `ArchitectureTest`: the existing "HTTP layer does not touch the filesystem directly" rule already covers the new controllers. No change.
  - Regenerate Wayfinder.
  - Tests:
    - `tests/Feature/Notes/NoteManagementTest.php` (`make:test Notes/NoteManagementTest --pest`). `beforeEach`: temp harness, `create('Work')`, `open`, and `writeVaultFiles`:
      1. POST `vaults.notes.store` `{name:'Meeting', folder:''}` → redirect to `route('notes.show', uuid)`; the file exists; `assertInertiaFlash('toast.type','success')`.
      2. The same with `folder:'Projects'`.
      3. Invalid name `CON` → `assertSessionHasErrors('name')`; no file.
      4. `folder:'../x'` → `assertSessionHasErrors('folder')`.
      5. PATCH `notes.update` `{name:'Standup'}` → the file is renamed; same UUID; redirect to `notes.show`.
      6. POST `notes.move` `{folder:'Servers'}` → moved; `folder:''` → root.
      7. DELETE `notes.destroy` without the trash → `assertSessionHasErrors('note')`; the file is kept. With `fakeTrash()` → the file and the record are gone; redirect to workspace.
      8. POST `vaults.folders.store` `{name:'Archive', parent:''}` → the directory exists. DELETE `vaults.folders.destroy` `{path:'Archive'}` → gone. `{path:'Projects'}` (non-empty) → `assertSessionHasErrors('path')`.
      9. POST `vaults.reindex` after `writeVaultFiles` adds `New.md` → `assertInertiaFlash('toast.message', 'Index updated: 1 added, 0 updated, 0 moved or renamed, 0 removed.')`. Missing vault folder → `toast.type` `error`.
      10. GET `notes.show` → component `Workspace`:
          - `note.uuid`, `note.content`, `note.state 'ok'`, `note.relative_path`;
          - `tree` has the nodes and `folders` contains `''`;
          - `->missing('note.id')->missing('note.vault_id')`.
      11. GET `notes.show` for a note of a non-current vault → redirect to `workspace` with `toast.type` `error`.
      12. Unknown UUID → 404; `/notes/1` → 404.
      13. Content containing `<script>alert(1)</script>` is returned verbatim in `note.content` (rendering as text is checked in T8's code review).
    - `tests/Feature/Vaults/VaultManagementTest.php`: add "opening a vault indexes its Markdown files":
      - create `Work`, write `n.md` directly, POST `vaults.open` → `Note::count() === 1`;
      - `toast.message` contains `1 added`.
    - `tests/Feature/Vaults/ExistingVaultTest.php`: the store happy path also asserts `Note::count() === 1` (for `n.md`) and the toast `Vault “Existing” added. 1 note(s) indexed.`
    - `tests/Feature/WorkspaceTest.php`:
      - first test: add `->where('tree', null)->where('note', null)->where('folders', [])`;
      - restart test: add `->has('tree')` (an array);
      - new test: after `File::deleteDirectory` on the current vault, GET workspace → `tree` null.
  - Covers: FR-08, FR-10 to FR-18, FR-21

- [ ] **T7: Note tree and dialogs (frontend)**
  - Activate the `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` skills.
  - `resources/js/types/notes.ts`:
    ```ts
    export type NoteTreeFolder = { type: 'folder'; name: string; path: string; open: boolean; children: NoteTreeNode[] };
    export type NoteTreeNote = { type: 'note'; uuid: string; title: string; path: string };
    export type NoteTreeNode = NoteTreeFolder | NoteTreeNote;
    export type NotePreviewState = 'ok' | 'missing' | 'too_large' | 'unreadable';
    export type NoteDetail = { uuid: string; title: string; filename: string; relative_path: string; folder: string; file_size: number; file_hash: string; updated_at: string | null; content: string | null; state: NotePreviewState; is_valid_utf8: boolean };
    ```
    Export it from `types/index.ts`.
  - `components/notes/noteTreeActions.ts`: `export type NoteTreeActions = { newNote(folder: string): void; newFolder(parent: string): void; rename(note: NoteTreeNote): void; move(note: NoteTreeNote): void; remove(note: NoteTreeNote): void; removeFolder(folder: NoteTreeFolder): void }; export const noteTreeActionsKey: InjectionKey<NoteTreeActions> = Symbol('noteTreeActions');`
  - `NoteTree.vue`:
    - Props: `vaultUuid: string`, `tree: NoteTreeNode[]`, `folders: string[]`, `selectedUuid: string | null`, `canTrash: boolean`.
    - Single root `<div class="flex h-full flex-col">`.
    - Header row: "Notes", plus icon `Button`s (ghost, size icon, each with an `aria-label` and `title`):
      - New note (`FilePlus`);
      - New folder (`FolderPlus`);
      - Re-index (`RefreshCw`), which calls `router.post(reindex.url(vaultUuid), {}, { preserveScroll: true })` from `@/routes/vaults`, with a `reindexing` ref for the disabled/spinning state.
    - The body lists the root `NoteTreeItem`s, or an empty state "No notes yet. Create one, or add .md files to the vault folder and re-index."
    - Holds refs for each dialog's `open` and target, and `provide(noteTreeActionsKey, {...})`.
    - Renders one instance of each dialog.
  - `NoteTreeItem.vue` (recursive; the component name is used for self-reference):
    - Props `node: NoteTreeNode`, `selectedUuid`, `canTrash`, `depth: number`. `inject(noteTreeActionsKey)`.
    - Folder:
      - `Collapsible :default-open="node.open"`;
      - the row is a `CollapsibleTrigger` button with a `ChevronRight` that rotates when open, a `Folder` icon, the name, and padding-left from `depth`;
      - a `DropdownMenu` (trigger `MoreHorizontal`, `aria-label="Folder actions"`) with: New note here, New folder here, Delete folder;
      - `CollapsibleContent` renders the children.
    - Note:
      - a `Link` to `show(node.uuid)` from `@/routes/notes`, with `:only="['note']"`, `preserve-state`, `preserve-scroll`, a `FileText` icon and the title;
      - active styling when `node.uuid === selectedUuid`;
      - a `DropdownMenu` with Rename, Move to…, Delete.
    - Single root in both cases (a wrapping `<div>`).
  - Dialogs: each takes `v-model:open` plus the target props, uses `useForm`, and submits with Wayfinder `.form`/`form.submit(route(...))`. On success, close and reset. Use `InputError` for every field:
    - `CreateNoteDialog` (`vaultUuid`, `folders`, `defaultFolder`):
      - fields `name` and `folder` (a `Select` over `folders`, labelled "Top level" for `''` and otherwise the path);
      - help text "“.md” is added for you.";
      - `form.submit(store(vaultUuid))` from `@/routes/vaults/notes`.
      - Note: `Select` can't hold the value `''`. Map `''` ↔ the sentinel `'__root__'` in the component, and `transform()` it back to `''`. This is value mapping only, not path logic.
    - `CreateFolderDialog` (`vaultUuid`, `folders`, `defaultParent`): `name` and `parent` → `store(vaultUuid)` from `@/routes/vaults/folders`.
    - `RenameNoteDialog` (`note`): `name` prefilled with the title → `update(note.uuid)` from `@/routes/notes`.
    - `MoveNoteDialog` (`note`, `folders`): the `folder` select → `move(note.uuid)`.
    - `DeleteNoteDialog` (`note`, `canTrash`):
      - text "Move “{title}” to the Recycle Bin / Trash? You can restore it from there.";
      - when `! canTrash`: a muted note "Deleting notes is only available in the desktop app." and the button disabled;
      - `form.submit(destroy(note.uuid))`; `InputError` for `note`.
    - `DeleteFolderDialog` (`vaultUuid`, `folder`):
      - text "Delete the empty folder “{path}”? Folders that still contain files can't be deleted here.";
      - `useForm({ path })` → `destroy(vaultUuid)` from `@/routes/vaults/folders`; `InputError` for `path`.
  - Presentation only: no string operations on paths except displaying them. No `v-html`.
  - Covers: FR-10 to FR-17

- [ ] **T8: Note viewer and Workspace layout**
  - `NoteViewer.vue`, props `note: NoteDetail`, single root `<article class="flex min-h-0 flex-col gap-3">`:
    - Header: the title (`h1`), the `relative_path` (muted, monospace), and a `Badge` "Read-only preview".
    - `state === 'ok'`:
      - `<pre class="whitespace-pre-wrap break-words rounded-md border bg-muted/30 p-4 font-mono text-sm">{{ note.content }}</pre>`;
      - an empty file shows a muted "This note is empty.";
      - `! is_valid_utf8` → an `Alert` "This file isn't valid UTF-8; unreadable characters are shown as �."
    - `missing`: an `Alert` destructive "This note's file can't be found on disk. It may have been moved, renamed or deleted outside MDVault." plus a Re-index `Button`. The vault UUID is passed as a prop.
    - `too_large`: "This file is larger than 1 MB, so it isn't previewed. Open it in another editor."
    - `unreadable`: "MDVault couldn't read this file. Close any programs that may be locking it and try again."
    - Footer (muted, `text-xs`): the size (a small `formatBytes` display helper inside the component), `SHA-256 {first 12 chars}…` with a `title` holding the full hash, and "Editing arrives in Phase 4" as a muted hint ("Editing will be available in a later update.").
  - `pages/Workspace.vue`:
    - New props: `tree: NoteTreeNode[] | null`, `folders: string[]`, `note: NoteDetail | null`, `canTrash: boolean`.
    - Keep the header, the missing alert and the no-vault bar exactly as they are.
    - Replace `<main>` with:
      - when `currentVault && currentVault.status === 'active' && tree !== null`: `<div class="flex min-h-0 flex-1">`, containing `<aside class="w-64 shrink-0 overflow-auto border-r">` with `NoteTree` (`selectedUuid = note?.uuid ?? null`), and `<main class="flex-1 overflow-auto p-4">` with `NoteViewer` when `note` is set, otherwise the empty state "Select a note from the list, or create a new one.";
      - otherwise, the existing `<main>` with the `TiptapEditor` demo (unchanged).
    - `defineOptions` breadcrumbs unchanged.
    - **Do not modify `TiptapEditor.vue`.**
  - Covers: FR-17, FR-18

- [ ] **T9: Quality gates and handover**
  - Run:
    - `php vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (the **full** suite)
    - `vendor/bin/phpstan analyse` (level 7; array shapes; no new baseline entries)
    - `npm run types:check`
    - `npm run build`
    - `npm run check`
    - `php artisan wayfinder:generate --with-form --no-interaction`
  - Checks:
    - `php artisan route:list --path=notes` and `--path=vaults` → the 8 new routes in §2 plus the Phase 2 routes.
    - `rg -n "content" database/migrations/*notes*` → no matches.
    - `rg -n "hash_file|file_get_contents|fopen|unlink|rmdir|->move\(|moveDirectory" app` → only `FileHashService` (`hash_file`) and `FileStorageService`.
    - `rg -n "unlink" app/Services` → only `FileStorageService::deleteNewEmptyFile`.
    - `rg -n "v-html" resources/js/components/notes resources/js/pages/Workspace.vue` → no matches.
    - `rg -n "moveDirectory\([^)]*true" app` → no matches.
    - Confirm that `TiptapEditor.vue` is unchanged.
  - Record everything in `implementation.md`, including any `make:*` path fixes and any `ignoring()` entries in the arch tests, each with its reason.
  - **Manual desktop checks (user, `composer native:dev`)**:
    - **M1**: "Add existing folder" on a folder with nested `.md` files, a `.git` and a `.obsidian` → the tree shows the notes and folders; dot folders are hidden; the toast gives the indexed count.
    - **M2**: New note "Meeting" → `Meeting.md` appears in Explorer; the viewer shows "This note is empty."
    - **M3**: Edit `Meeting.md` in VS Code, then click the note again → the new text shows and the hash in the footer changes.
    - **M4**: New folder "Projects"; move "Meeting" into it → Explorer shows `Projects\Meeting.md`; the URL (UUID) is unchanged.
    - **M5**: Rename to "Standup", then to "standup" (case only) → Explorer reflects both.
    - **M6**: Rename a file in Explorer (content unchanged), then Re-index → the same note URL still works (UUID kept) under the new name.
    - **M7**: Delete a note → it appears in the Recycle Bin. Restore it and Re-index → it returns (with a new UUID, which is expected).
    - **M8**: Delete an empty folder → it succeeds. Delete a folder that contains a note → "isn't empty" error.
    - **M9**: With a note open, rename the vault (Phase 2) → the note still opens and the tree is intact (no handles held).
    - **M10**: Add a folder with about 2,000 `.md` files → record how long the open takes (target: a few seconds). Clicking notes doesn't rescan (fast).
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/DatabaseSchemaTest.php` | `notes` present with 13 columns and no `content`; later tables absent | FR-01 |
| `tests/Feature/Models/NoteTest.php` | UUID generated and stable; hidden `id`/`vault_id`; relations; FK cascade; unique path | FR-01, FR-02, FR-21 |
| `tests/Feature/Services/FileHashServiceTest.php` | Known vectors, null on missing or dir, `matches`, streaming | FR-03 |
| `tests/Feature/Services/FileStorageServiceTest.php` | Exclusive create, empty-only delete, no-overwrite and case-only file rename, lock simulation, scan (pruning, symlinks, empty dirs, missing root) | FR-04, FR-10, FR-12, FR-13, FR-19, FR-20 |
| `tests/Feature/Services/VaultIndexServiceTest.php` | 16 scenarios: initial index, ignore rules, idempotence, external edit/delete/create/rename/case/rename+edit, ambiguity, rebuild, missing vault, symlink, Unicode, browse, summary | FR-04 to FR-07, FR-09, FR-17 |
| `tests/Feature/Services/NoteServiceTest.php` | 41 scenarios: create/rename/move/delete/folders/preview/vault interplay, including DB-failure compensation and rollback failure | FR-10 to FR-16, FR-18 to FR-20 |
| `tests/Feature/Notes/NoteManagementTest.php` | HTTP for every route, error field mapping, re-index toasts, `notes.show` props, other-vault redirect, 404s, no ids | FR-08, FR-10 to FR-18, FR-21 |
| `tests/Feature/Vaults/VaultManagementTest.php`, `ExistingVaultTest.php` | Open and add-existing index the vault | FR-08 |
| `tests/Feature/WorkspaceTest.php` | `tree`/`note`/`folders` for no vault, active and missing | FR-17, FR-18 |
| `tests/Unit/ArchitectureTest.php` | Note/Vault usage, `hash_file` only in `FileHashService`, no raw filesystem in Note/Index services, existing HTTP rule | FR-22 |
| (static) `types:check`, `build`, `check`, greps in T9 | Single roots, no `v-html`, boundaries | FR-17, FR-18, FR-22 |
| (manual) M1–M10 | Desktop behaviour, Recycle Bin, locks, performance | FR-08, FR-14, FR-20 |

**Test scope for QA**:
- Targeted first:
  ```
  php artisan test --compact tests/Feature/Services/FileHashServiceTest.php tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/VaultIndexServiceTest.php tests/Feature/Services/NoteServiceTest.php tests/Feature/Notes tests/Feature/Models/NoteTest.php tests/Feature/DatabaseSchemaTest.php tests/Unit/ArchitectureTest.php
  ```
- Then the **full suite** `php artisan test --compact`. It is required because `tests/Pest.php`, `WorkspaceController`, `VaultController`, `ExistingVaultController` and `routes/web.php` all change.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check`, and the T9 greps.
- Code review:
  - no `content` column or content persistence anywhere in the DB;
  - every file operation is checked afterwards and never overwrites;
  - compensation only renames back or removes a 0-byte file this call created;
  - delete only goes through `FileStorageService::moveToTrash`;
  - re-index is one transaction in the order delete → update → insert, and unreadable entries are never removed;
  - path inputs pass through `resolveFolder`;
  - no path or filesystem logic in Vue or controllers; no `v-html`;
  - single-root components;
  - `TiptapEditor.vue` is unchanged.
- M1–M10 are **user-verified manual checks**, not defects.

---

## 5. Risks & Mitigations
- **Risk**: PHP `rename()` overwrites an existing file. **Mitigation**: the pre-check in `NoteService::assertNoConflict` and `renameFile`, plus the check afterwards. A time-of-check/time-of-use gap is accepted (§44, single user).
- **Risk**: a re-index wrongly deletes records under a locked or unreadable folder. **Mitigation**: `scan()` reports `unreadable`, records under those prefixes are kept, and the root being unreadable aborts the whole re-index.
- **Risk**: false UUID matches. **Mitigation**: only *unique* 1:1 matches are paired (with a test); the case-insensitive step runs before the hash step.
- **Risk**: the unique `(vault_id, relative_path)` violated mid-transaction. **Mitigation**: deletes run first. A move target is always a path with no exact-match row (proof in the ADR).
- **Risk**: invalid UTF-8 breaks the Inertia JSON. **Mitigation**: `mb_scrub` plus the flag (test 38).
- **Risk**: XSS from note content. **Mitigation**: text interpolation only, a `v-html` grep, and HTTP test 13.
- **Risk**: slow vault open for large vaults. **Mitigation**: streaming hashes and a single transaction; `tree` and `folders` are closures with a partial reload for note clicks; M10 measures it. Background indexing is a Phase 5 option.
- **Risk**: a nullable model-bound parameter on an invokable controller resolves an empty model. **Mitigation**: Laravel returns the default when the parameter has a default value. A test covers `/`, and the plan gives a fallback (split methods).
- **Risk**: symlink tests can't run on Windows. **Mitigation**: `->skipOnWindows()`, and the scan checks `isLink()` before any other check.
- **Risk**: the sonnet developer "simplifies" delete into `unlink`. **Mitigation**: the ADR, the greps and the code-review item.

---

## 6. Open Questions (user approvals; recommended answers in bold)
- [x] **E1: Delete note semantics.** Options:
  - (a) move the file to the OS Recycle Bin / Trash (desktop only; checked afterwards; the record is kept on failure);
  - (b) an app-level hidden `.mdvault-trash/` folder inside the vault (works in browser dev, but clutters vaults and sync tools and grows forever);
  - (c) permanent delete.

  **Recommended: (a), the same as vault removal (C1). If the file is already gone from disk, only the list entry is removed. Never permanent.**
- [x] **E2: Folder operations scope.** Options:
  - (a) create folder only;
  - (b) create folder plus delete *empty* folders;
  - (c) also rename/move folders and delete non-empty folders (about 2 more tasks, bulk path rewrites, lock handling).

  **Recommended: (b).** Empty folders are shown from a live directory scan (no folders table, §15). Rename, move and non-empty delete are a follow-up item after Phase 4.
- [x] **E3: Which files are indexed.** **Recommended:**
  - `.md` only, any letter case (`.MD`);
  - skip any file or folder starting with `.` (`.git`, `.obsidian`, `.trash`, `.mdvault-*` temp names) and folders named `node_modules`;
  - never follow or index symlinks or junctions;
  - no size limit for indexing (hashes stream);
  - the preview is capped at 1 MiB;
  - `.markdown` is deferred.
- [x] **E4: Title and filename rules.** **Recommended:**
  - title = filename without `.md` (not the first heading);
  - new note and folder names use the vault-name rules (portable on every OS, 1–100 characters), must not start with `.`, and folders can't be named `node_modules`;
  - `.md` is added automatically, and a typed `.md` is stripped;
  - names are unique ignoring letter case within the folder;
  - new notes are created as empty files. (Amended in Phase 4 Revision 4: when the new-note template setting is on, which is the default, new notes start with a rendered frontmatter block; existing notes are never touched.)
- [x] **E5: When indexing runs.** **Recommended:**
  - a full re-index when a vault is opened, created or added, and on a manual "Re-index" button with a result toast;
  - MDVault's own operations update only their record;
  - opening a note re-hashes that file and refreshes its record if it changed;
  - no startup or background indexing (synchronous; revisit in Phase 5 if M10 is slow).
- [x] **E6: Stable note UUIDs across re-index.** **Recommended:**
  - match by exact path, then by a unique case-insensitive path, then by a unique identical SHA-256; these keep the UUID;
  - everything else is added or removed (so an external rename plus an edit gets a new UUID);
  - MDVault never writes IDs into your files (no frontmatter, no sidecar).
- [x] **E7: What "open note" shows in Phase 3.** **Recommended:**
  - raw Markdown, read-only (monospace, wrapped, never rendered as HTML), with path, size and hash, and missing, too-large and unreadable states;
  - the Tiptap demo is shown only when no vault is open;
  - `TiptapEditor.vue` is unchanged until Phase 4.
- [x] **E8: Where the note tree lives.** Options:
  - (a) a left pane inside the Workspace page;
  - (b) inside the app sidebar under the current vault.

  **Recommended: (a).** (b) would scan the vault on every page (a shared prop), and it doesn't work when the sidebar collapses to icons.
- [x] **E9: Vault interplay (confirm).**
  - Phase 3 keeps no watchers and no open file handles, so vault rename is unaffected (the Phase 2 ADR follow-up is closed).
  - Note paths are vault-relative, so a rename changes nothing in `notes`.
  - Removing a vault deletes its note records (the files stay).
  - Re-adding the folder re-indexes it under new UUIDs.

  **Recommended: confirm.**
- [x] **E10: New folders and dependencies.** New folders:
  - `app/Http/Requests/Notes/`
  - `resources/js/components/notes/`
  - `tests/Feature/Notes/`

  **No new composer or npm dependencies. Recommended: approve.**

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-28 | Initial plan | — |
