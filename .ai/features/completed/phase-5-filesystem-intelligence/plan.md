# Plan: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Master Plan Phase**: **Implements Master Plan Phase 5, Filesystem Intelligence (`docs/Masterplan.md` §54). Related sections: §17, §18, §24, §38, §39, §41, §43, §44; Rules 4, 8, 9, 10; §61 items 14–15; §62 filesystem tests.**
- **Author**: System Analyst
- **Created Date**: 2026-09-30
- **Task Complexity**: Level 4, Architectural (analyst sign-off after QA)
- **Requirements**: `requirements.md`
- **Status**: DELIVERED (Level 4 analyst sign-off 2026-09-30, conditional on QA round 3 PASS; G1–G8 approved as recommended)

---

## 1. Summary
MDVault detects external changes by polling, driven by the renderer. There is no OS watcher.

A **quick reconcile** (`VaultIndexService::reconcile(..., IndexMode::Quick)`) stats every `.md` file. It hashes only files whose size or mtime differ from the new `notes.file_mtime` column, plus the open note. It then pairs records to files:
1. exact path;
2. case-insensitive path;
3. unique hash;
4. **unique file name**.

It applies deletes, updates, moves and inserts in one transaction, behind a fingerprint guard.

The Workspace calls `POST /vaults/{vault}/changes`:
- on mount;
- on focus or visibility;
- every 5 s while visible (adaptive).

It compares a **tree signature** to decide whether to reload the tree, and passes the open note's remote state to `NoteEditor`. `NoteEditor` classifies that state against its saver:
- clean + changed → auto-reload;
- dirty + changed → the existing `changed` conflict banner;
- moved → follow;
- deleted → close, or show the `missing` banner.

The banner gains "Save mine as a new note" (exclusive-create copy) and "Compare" (line diff).

---

## 2. Architecture & Design
- **Approach**: see the three new ADRs. Summary:
  1. **Detection** (ADR `external-change-detection`):
     - Triggers are renderer events: mount, `window` `focus`, `visibilitychange`, and a 5 s interval while visible, with adaptive back-off.
     - There is one check in flight at a time. Checks pause during Inertia visits and in-flight saves.
     - Note-open verification (`preview()` re-hash), the save base-hash guard and the full manual Re-index remain the safety net.
     - The same code path runs in desktop and browser dev.
  2. **Reconciliation** (ADR `external-change-reconciliation`): `plan()` (scan, selective hash, pairing) → `apply()`, which:
     - opens a transaction;
     - re-reads a fingerprint of every vault row (`id → path|hash|size`);
     - aborts as `stale` on any difference;
     - deletes, then updates/moves/touches, then inserts.

     Other rules:
     - `reconcile()` retries a stale plan once.
     - There are no filesystem writes.
     - Racy-mtime rule: an mtime ≥ scan start − 2 s is stored as `null`.
     - `touch` (an mtime-only change) runs `withoutTimestamps` and is not reported as a change.
     - Tree signature = SHA-256 of the sorted scanned directories plus the sorted `uuid:path` pairs, computed identically in `browse()` and `apply()`.
  3. **Frontend** (ADR `open-note-external-conflicts`):
     - Framework-free modules: `changeChecker` (scheduler), `openNoteStatus` (classify and decide), `visitSafety` (a guard exemption for tree-only reloads).
     - A `useExternalChanges` composable in Workspace.
     - `NoteEditor` exposes `noteUuid`, `externalCheckToken` and `applyExternalStatus`.
     - `noteSaver` gains `externalConflict()` and `resume()`.
- **Alternatives Considered** (details in the ADRs):
  - A native watcher (a NativePHP `ChildProcess::node` running `fs.watch`/chokidar). Rejected for now:
    - there's no NativePHP API, and chokidar is only a transitive dev dependency;
    - it holds directory handles that block vault rename/trash on Windows;
    - its events are unreliable (overflow, network drives, duplicates) and it would still need a reconcile scan;
    - it can't be tested with Pest;
    - it doesn't run in browser dev.

    It remains a future trigger for the same reconcile.
  - Polling from a NativePHP scheduled command: rejected. There's no UI channel back except native events, and it isn't testable end to end.
  - Hashing everything on every check (no migration): rejected as the default (O(total bytes) every 5 s). It remains the `Full` mode.
  - Keeping the scan snapshot in the cache instead of a column: rejected. It's less deterministic, and the database cache store is itself SQLite.
  - Broadcasting over NativePHP (`nativephp` channel → `window.Native.on`): rejected for this phase (desktop-only, not needed with polling).
  - Hiding a missing open note from reconcile: rejected (a registry lying about the disk).
- **Decision Records**:
  - `.ai/decisions/external-change-detection.md` (new)
  - `.ai/decisions/external-change-reconciliation.md` (new)
  - `.ai/decisions/open-note-external-conflicts.md` (new)
  - Amended in T0: `note-registry-and-indexing.md`, `note-file-operations.md`, `note-save-atomic-replace.md`, `settings-persistence.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| `notes` | alter (G2) | Add `file_mtime` unsigned big integer, **nullable**, after `file_hash`. No index. It is derived metadata (a trusted modification time in Unix seconds, or `null` = "hash next time"). **No `content` column. No new tables.** |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Migration (new) | `database/migrations/2026_09_30_xxxxxx_add_file_mtime_to_notes_table.php` | Adds `file_mtime` |
| Model (modify) | `app/Models/Note.php` | `file_mtime` fillable, cast `integer`, PHPDoc |
| Enum (new) | `app/Enums/IndexMode.php` | `Quick = 'quick'`, `Full = 'full'` |
| Support (new) | `app/Support/ReconcilePlan.php` | Immutable plan (snapshot, deletes, updates, moves, touches, inserts, counts, directories, orphans) |
| Support (modify) | `app/Support/IndexResult.php` | + `touched`, `changes`, `stale`, `orphanTempFiles`, `treeSignature` (all defaulted) |
| Service (modify) | `app/Services/FileStorageService.php` | `scan()` returns `mtime` per file; new `modifiedTime()` |
| Service (modify) | `app/Services/VaultIndexService.php` | `reconcile()`, `plan()`, `apply()`, `treeSignature()`; `reindex()` = `reconcile(Full)`; basename step; `browse()` returns `signature` |
| Service (new) | `app/Services/ExternalChangeService.php` | `check(Vault, ?string $openNoteUuid): array` |
| Service (modify) | `app/Services/NoteService.php` | `createCopy()`; private `registerNewFile()` extracted from `create()` |
| Exception (modify) | `app/Exceptions/NoteOperationException.php` | `copyNameUnavailable()` |
| Request (new) | `app/Http/Requests/Vaults/CheckVaultChangesRequest.php` | `open_note` nullable uuid |
| Request (new) | `app/Http/Requests/Notes/StoreNoteCopyRequest.php` | `source_path`, `content`, `mode`, `has_frontmatter`, `frontmatter` |
| Controller (new) | `app/Http/Controllers/VaultChangeController.php` | Invokable JSON check |
| Controller (new) | `app/Http/Controllers/NoteCopyController.php` | Invokable JSON copy (201) |
| Controller (new) | `app/Http/Controllers/NoteDiskController.php` | Invokable JSON disk text |
| Controller (modify) | `VaultController` (`store`, `open`), `ExistingVaultController` | Use `reconcile($vault, IndexMode::Quick)` |
| Controller (modify) | `VaultIndexController` | Stays full; handles a `stale` result |
| Controller (modify) | `WorkspaceController` | Props `treeSignature` (lazy) and `checkExternalChanges` |
| Routes (modify) | `routes/notes.php` | Three routes (below) |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Module (new) | `resources/js/lib/external/changeChecker.ts` | Scheduler: single flight, debounce, interval, adaptive back-off, active flag |
| Module (new) | `resources/js/lib/external/openNoteStatus.ts` | `classifyOpenNote`, `decideOpenNoteAction` (pure) |
| Module (new) | `resources/js/lib/editor/visitSafety.ts` | `isEditorSafeVisit(visit, currentUrl)` |
| Module (modify) | `resources/js/lib/editor/noteSaver.ts` | `externalConflict()`, `resume()` |
| Composable (new) | `resources/js/composables/useExternalChanges.ts` | Wires the DOM and router events, the HTTP call and reloads; returns `requestCheck`, `orphanTempFiles` |
| Composable (modify) | `resources/js/composables/useUnsavedChangesGuard.ts` | Skips editor-safe visits |
| Component (modify) | `resources/js/components/editor/NoteEditor.vue` | `defineExpose`, `applyExternalStatus`, copy and compare actions, `request-check` emit |
| Component (modify) | `resources/js/components/editor/NoteConflictAlert.vue` | New buttons and emits |
| Component (new) | `resources/js/components/editor/NoteCompareDialog.vue` | Diff dialog (`diff` package, G5) |
| Component (new) | `resources/js/components/notes/OrphanSaveNotice.vue` | Dismissible notice (G7) |
| Page (modify) | `resources/js/pages/Workspace.vue` | Uses the composable; editor ref; notice |
| Page (modify) | `resources/js/pages/settings/General.vue` | Copy |
| Types (modify) | `resources/js/types/notes.ts` | `VaultChangeCheckResponse`, `RemoteOpenNote`, `NoteCopyResponse`, `NoteDiskResponse` |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| POST | `/vaults/{vault:uuid}/changes` | `vaults.changes.check` | `VaultChangeController` (invokable) | web (CSRF), `whereUuid('vault')` |
| POST | `/vaults/{vault:uuid}/notes/copy` | `vaults.notes.copy` | `NoteCopyController` (invokable) | web (CSRF), `whereUuid('vault')` |
| GET | `/notes/{note:uuid}/disk` | `notes.disk.show` | `NoteDiskController` (invokable) | web, `whereUuid('note')` |

---

## 3. Implementation Tasks
Rules for every task:
- After each task, run `php vendor/bin/pint --dirty --format agent` and that task's tests.
- After any route change, run `php artisan wayfinder:generate --with-form --no-interaction`.
- Use the Phase 3/4 harness: a temp dir, `fakeDocumentsDirectory`, `VaultService::create('Work')`, `writeVaultFiles`, cleanup in `afterEach`.
- Read the three new ADRs first.
- Activate the skills `laravel-best-practices`, `testing-best-practices`, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` where relevant.
- **No AI attribution anywhere.**
- Never run `migrate:fresh` against the desktop DB (`native:migrate` applies the new migration).
- Relative imports only inside `resources/js/lib/**`.

- [x] **T0: Preconditions (orchestrator/user)**
  - Record the G1–G8 answers in §6. If G1, G2 or G4 deviate from the recommendation, stop and ask the analyst for a revision.
  - Save the three ADRs.
  - Append an "Amended in Phase 5" line to each of these ADRs:
    - `note-registry-and-indexing.md` → Follow-ups: "Phase 5 (delivered, ADR `external-change-reconciliation`): `reconcile()` with Quick/Full modes and the `notes.file_mtime` shortcut, a fourth pairing step (unique file name), a stale-registry guard, and a tree signature. Vault open/create/add now reconcile in Quick mode; manual Re-index stays Full."
    - `note-file-operations.md` → Follow-ups: replace the Phase 5 bullet with "Phase 5 (resolved): detection polls (ADR `external-change-detection`) and holds no directory handle between checks, so nothing needs pausing before a vault rename or trash; `.mdvault-*` names stay ignored."
    - `note-save-atomic-replace.md` → Follow-ups: replace the Phase 5 bullet with "Phase 5 (delivered): own saves update `file_hash` before any later check, so they are never reported as external; Compare view and 'Save mine as a new note' (ADR `open-note-external-conflicts`); orphan `.mdvault-save-*` files older than 60 s are reported, never deleted."
    - `settings-persistence.md` → Follow-ups: "Phase 5: `app.check_external_changes` enables only the automatic focus/interval checks; note-open verification, the save guard and manual Re-index always run."
  - Create the branch `phase-5-filesystem-intelligence` from `phase-4-tiptap-editor` (or `main` after merge; never commit on `main`).
  - If G5 is approved: `npm install diff@^9.0.0` (it ships its own types; no `@types/diff`).
  - Covers: FR-21

- [x] **T1: `notes.file_mtime` column (G2)**
  - Command: `php artisan make:migration add_file_mtime_to_notes_table --table=notes --no-interaction`.
  - Up: `$table->unsignedBigInteger('file_mtime')->nullable()->after('file_hash');`. Down: `dropColumn('file_mtime')`.
  - `Note`: add `'file_mtime'` to `$fillable`, `'file_mtime' => 'integer'` to `casts()`, and `@property ?int $file_mtime`. `NoteService::present()` stays unchanged (never exposed).
  - `tests/Feature/DatabaseSchemaTest.php`: add `'file_mtime'` to the notes column list.
  - Covers: FR-06

- [x] **T2: `FileStorageService` scan metadata**
  - `scan()`: each file entry becomes `array{path: string, size: int, mtime: ?int}`, with `mtime` from `$iterator->getMTime()` inside a try (`null` on failure, or when ≤ 0). Update the PHPDoc. Existing consumers ignore the extra key.
  - New method: `public function modifiedTime(string $path): ?int` (`clearstatcache(); $t = @filemtime($path); return $t === false ? null : $t;`).
  - Tests in `tests/Feature/Services/FileStorageServiceTest.php`:
    1. `scan` returns `mtime` equal to `filemtime` for each file after `touch($p, 1_700_000_000)`.
    2. `modifiedTime` returns `null` for a missing path.
  - Covers: FR-06

- [x] **T3: Reconcile engine in `VaultIndexService`**
  - Commands: `php artisan make:enum Enums/IndexMode --no-interaction` (string-backed: `Quick = 'quick'`, `Full = 'full'`) and `php artisan make:class Support/ReconcilePlan --no-interaction` (`final readonly`).
  - `IndexResult` additions, all constructor params with defaults so existing call sites compile:
    ```php
    public int $touched = 0,
    /** @var list<array{type: 'created'|'modified'|'moved'|'deleted', uuid: string, path: string, from: ?string, content_changed: bool}> */
    public array $changes = [],
    public bool $stale = false,
    /** @var list<string> */
    public array $orphanTempFiles = [],
    public ?string $treeSignature = null,
    ```
    `summary()`: when `stale`, return "The vault changed while it was being indexed. Try again." `hasChanges()` is unchanged; `touched` never counts as a change.
  - `VaultIndexService` constants: `RACY_WINDOW_SECONDS = 2`, `ORPHAN_MIN_AGE_SECONDS = 60`.
  - Public API:
    ```php
    /** Full re-index (manual action). Unchanged contract. */
    public function reindex(Vault $vault): IndexResult; // = reconcile($vault, IndexMode::Full)
    /** @param list<string> $verifyPaths vault-relative paths always hashed (the open note) @throws NoteOperationException */
    public function reconcile(Vault $vault, IndexMode $mode = IndexMode::Full, array $verifyPaths = []): IndexResult; // plan+apply, one retry on stale
    /** @param list<string> $verifyPaths @throws NoteOperationException */
    public function plan(Vault $vault, IndexMode $mode, array $verifyPaths = []): ReconcilePlan;
    public function apply(Vault $vault, ReconcilePlan $plan): IndexResult;
    /** @param list<string> $directories @param array<string, string> $notePathsByUuid */
    public function treeSignature(array $directories, array $notePathsByUuid): string;
    ```
  - **`plan()` algorithm**:
    1. The vault root is not a directory → `vaultUnavailable`.
    2. `$startedAt = CarbonImmutable::now()->getTimestamp()`.
    3. `scan()` with an accept callback:
       - directories: `! isIgnoredName($name, true)`;
       - files: if `str_starts_with($name, FileStorageService::SAVE_TEMP_PREFIX)`, push `$relative` onto `$orphanCandidates` and return false; otherwise `isIndexableFileName($name)`.

       A root listed in `unreadable` → `vaultUnavailable`.
    4. `$rows` = every vault note keyed by `relative_path` (select `id, uuid, relative_path, file_hash, file_size, file_mtime` plus the columns `moveAttributes` needs). `$snapshot[id] = relative_path."\0".file_hash."\0".file_size` for **all** rows.
    5. For each scanned file (`$verify = array_flip($verifyPaths)`):
       - **Quick shortcut**: if `$mode === Quick`, a row exists at the exact path, `! isset($verify[$path])`, `$row->file_mtime !== null`, `$row->file_mtime === $file['mtime']` and `$row->file_size === $file['size']`, then count it `unchanged`, remove it from both maps, and don't hash it.
       - Otherwise hash it. A `null` hash is handled as today (skipped; the exact-path row is kept untouched).
       - `$trustedMtime = ($file['mtime'] === null || $file['mtime'] >= $startedAt - RACY_WINDOW_SECONDS) ? null : $file['mtime']`.
    6. **Exact path**:
       - the hash or size differs → `updates` with `file_hash`, `file_size`, `file_mtime`;
       - otherwise, `$row->file_mtime !== $trustedMtime` → `touches` (`file_mtime` only);
       - otherwise `unchanged`.
    7. **Unique case-insensitive path**, then **unique hash**: as today; `moveAttributes` plus `file_mtime`.
    8. **New step: unique file name.** Group the remaining rows by `mb_strtolower(basename(path))`, and likewise the remaining files. Pair only when both groups have exactly one member. Use `moveAttributes` (which carries the new hash and size) plus `file_mtime`, and mark `content_changed = hash differs`.
    9. Leftover rows: under an unreadable directory → `skipped`; otherwise `deletes`. Leftover files → `inserts` (+ `file_mtime`).
    10. Orphans: keep each candidate whose `modifiedTime(joinRelative(root, rel))` is non-null and `< $startedAt - ORPHAN_MIN_AGE_SECONDS`.
    11. Return a `ReconcilePlan` holding the snapshot, the lists (each move keeps `from` path and `content_changed`), the counts, `directories = $scan['directories']` and the orphans.
  - **`apply()` algorithm**:
    1. `try { $created = $db->transaction(function () use (...) { ... }); } catch (QueryException $e) { report($e); return new IndexResult(0,0,0,0,0,0, stale: true); }`
    2. Inside the transaction:
       - Re-read `Note::query()->where('vault_id', $vault->id)->get(['id','relative_path','file_hash','file_size'])` and build the same fingerprint map. If it isn't identical (`!=` on arrays; same keys and values) to `$plan->snapshot`, return `null`.
       - Otherwise, in this order:
         - `whereIn('id', deletes)->delete()`;
         - each update or move: `$note->update($attributes)`;
         - each touch: `Note::withoutTimestamps(fn () => $note->update(['file_mtime' => …]))`;
         - each insert: `$vault->notes()->create($attributes)`, collecting the created models.
       - Return the created list.
    3. `$created === null` → an `IndexResult` with `stale: true`.
    4. Build `changes`: created (uuid, path); modified (exact-path updates); moved (from, to, `content_changed`); deleted (uuid, path, captured from the plan's rows).
    5. `treeSignature = treeSignature($plan->directories, $vault->notes()->pluck('relative_path', 'uuid')->all())`.
    6. Return the counts (`added`, `updated`, `moved`, `removed`, `unchanged`, `skipped`, `touched`), `changes`, `orphanTempFiles` and `treeSignature`.
  - **`treeSignature()`**: sort the directories with `strcmp`; build the list of `"{uuid}:{path}"` strings sorted with `strcmp`; return `hash('sha256', json_encode([$dirs, $pairs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))`.
  - **`browse()`**: additionally return `'signature' => $this->treeSignature($scan['directories'], $notes->pluck('relative_path','uuid')->all())`, using the **scan** directories, not the union with registry-derived parents. Update the PHPDoc return shape.
  - `VaultIndexController`: if `$result->stale`, flash an error toast with `summary()`; otherwise unchanged.
  - Existing tests in `VaultIndexServiceTest.php`: any test asserting a new UUID for a **move plus edit with the same file name** must now assert the same UUID. Record every changed expectation in `implementation.md`.
  - New file `tests/Feature/Services/VaultReconcileTest.php` (`php artisan make:test Services/VaultReconcileTest --pest --no-interaction`), with dataset-driven filesystem tests (§62):
    1. External **create** → `added` 1, change `created`, the UUID is a UUIDv7.
    2. External **modify** → `updated` 1, the same UUID, the DB hash equals `hash_file`.
    3. External **delete** → `removed` 1; the record is gone.
    4. External **rename** (same content) → same UUID, new `relative_path`/`filename`/`title`.
    5. External **move** to another folder (same content) → same UUID.
    6. **Move plus edit**, same file name → same UUID, `content_changed` true.
    7. **Rename plus edit** (different name) → delete + insert (new UUID).
    8. **Folder rename** `Projects/` → `Work/` containing 3 notes, two of them 0-byte (identical hashes) → all 3 keep their UUIDs (basename step).
    9. **Ambiguous names**: `A/x.md` and `B/x.md` are both deleted and `C/x.md` is created → no pairing (delete, delete, insert).
    10. **Case-only rename** keeps the UUID (regression).
    11. **Quick shortcut**:
        - reconcile once;
        - `touch($p, time() - 100)` on every file, then reconcile (Full) so the mtimes become trusted;
        - overwrite a file with **same-size** content and `touch` it back to the stored mtime;
        - Quick reconcile → `unchanged`, the hash is **not** updated;
        - `reconcile(Quick, [$path])` → `updated`;
        - on a fresh copy of the scenario, `reindex()` (Full) → `updated`.
    12. **Racy rule**: a file written now and reconciled immediately → `file_mtime` is `null`. After `touch($p, time() - 100)` and another reconcile → `file_mtime === time() - 100`, reported as `touched` (not `updated`), and `updated_at` is unchanged.
    13. **No spurious writes**: two consecutive Quick reconciles with no changes (files touched into the past first) → the second makes zero `update`/`insert`/`delete` queries (`DB::listen` counter), and `hasChanges()` is false.
    14. **Stale guard**: `plan()`, then `Note::query()->first()->update(['file_hash' => str_repeat('0', 64)])`, then `apply()` → `stale` true, and every other row is unchanged. Likewise a row inserted between plan and apply → stale.
    15. **`reconcile()` retries once**: covered through `plan`/`apply` in test 14. Add a direct test only if a seam is natural; don't add a production seam just for this.
    16. **Unreadable file** (`->skipOnWindows()`, `chmod 000`): the record is kept and `skipped` is 1.
    17. **Vault root missing** → `NoteOperationException` (field `vault`); the DB is unchanged.
    18. **Orphans**: `.mdvault-save-abc123` touched to `time() - 120` → listed in `orphanTempFiles`, not indexed. One touched now → not listed.
    19. **Tree signature**:
        - `browse($vault)['signature'] === reconcile(...)->treeSignature` when nothing changed;
        - it changes after an empty folder is created or deleted, and after a rename;
        - it doesn't change after a content-only modify.
    20. **Ignored names**: `.git/x.md`, `node_modules/x.md` and `.mdvault-rename-*` are never indexed (regression).
  - Covers: FR-01 to FR-07, FR-17, FR-19

- [x] **T4: Quick mode on vault open/create/add (G2)**
  - `VaultController::store` and `open`, and `ExistingVaultController`: replace `$index->reindex($vault)` with `$index->reconcile($vault, IndexMode::Quick)`. Keep the existing toast conditions, and ignore `stale` there (the next check retries). `VaultIndexController` stays `reindex()` (Full).
  - Tests: the existing `VaultManagementTest`/`ExistingVaultTest` stay green. Add one test: an open after an external same-size, mtime-restored edit does **not** change the hash, while `POST vaults.reindex` does (it documents the G2 trade-off).
  - Covers: FR-06

- [x] **T5: `ExternalChangeService`, check endpoint, Workspace props**
  - Commands:
    - `php artisan make:class Services/ExternalChangeService --no-interaction` (`final`)
    - `php artisan make:request Vaults/CheckVaultChangesRequest --no-interaction`
    - `php artisan make:controller VaultChangeController --invokable --no-interaction`
  - `ExternalChangeService`:
    - Constructor: `VaultService $vaults, VaultIndexService $index, SettingsService $settings`.
    - Signature:
      ```php
      /** @return array{status: 'ok'|'busy'|'unavailable'|'disabled'|'inactive', changed: bool, tree_signature: ?string, open_note: ?array{uuid: string, exists: bool, relative_path: ?string, file_hash: ?string}, orphan_temp_files: list<string>} */
      public function check(Vault $vault, ?string $openNoteUuid): array;
      ```
    - Order:
      1. `! $settings->boolean(SettingKey::CheckExternalChanges)` → `disabled`.
      2. `$vaults->current()` is null or `! ->is($vault)` → `inactive`.
      3. `$vaults->refreshStatus($vault)`; status `Missing` → `unavailable`.
      4. `$verify = []`. If `$openNoteUuid`, find the row in **this** vault by uuid; if it exists, `$verify = [$row->relative_path]`.
      5. `try { $r = $index->reconcile($vault, IndexMode::Quick, $verify); } catch (NoteOperationException) { return unavailable; }`
      6. `$r->stale` → `busy`.
      7. `open_note`: when `$openNoteUuid` is given, re-query that uuid in the vault → `{uuid, exists: true, relative_path, file_hash}`, or `{uuid, exists: false, relative_path: null, file_hash: null}`.
      8. Return `ok` with `changed = $r->hasChanges()`, `tree_signature = $r->treeSignature` and `orphan_temp_files = $r->orphanTempFiles`.

      Every non-`ok` status returns `changed` false, and `null`/`[]` for the other keys.
  - `CheckVaultChangesRequest`: `authorize()` true; rules `['open_note' => ['nullable', 'uuid']]`.
  - `VaultChangeController::__invoke(CheckVaultChangesRequest $request, Vault $vault, ExternalChangeService $changes): JsonResponse` → `response()->json($changes->check($vault, $request->validated('open_note')))`.
  - `routes/notes.php`: `Route::post('vaults/{vault:uuid}/changes', VaultChangeController::class)->whereUuid('vault')->name('vaults.changes.check');`
  - `WorkspaceController`: add the props
    - `'treeSignature' => fn () => $active ? $browse()['signature'] : null`
    - `'checkExternalChanges' => $settings->boolean(SettingKey::CheckExternalChanges)`
  - `ArchitectureTest`: add `App\Services\ExternalChangeService` to the targets of "index and note services use no raw filesystem functions".
  - Tests:
    - `tests/Feature/Services/ExternalChangeServiceTest.php`:
      - every status branch;
      - `open_note` for an unchanged note, a modified note (`file_hash` = disk), a moved note (new path, same uuid) and a deleted note (`exists` false);
      - a uuid from another vault → treated as not found (`exists` false) and never verified;
      - own save: `NoteService::save` followed by `check` → `changed` false and `open_note.file_hash` = the save's hash.
    - `tests/Feature/Vaults/VaultChangeCheckTest.php`:
      - `postJson(route('vaults.changes.check', $uuid), ['open_note' => $noteUuid])` → 200 with the exact key set; no `id`/`vault_id` anywhere;
      - `open_note: 'nope'` → 422;
      - unknown vault → 404; GET → 405;
      - setting off → `status: disabled`.
    - `tests/Feature/WorkspaceTest.php`: the `treeSignature` prop equals `check()['tree_signature']` for an unchanged vault; `checkExternalChanges` is true by default.
  - Covers: FR-08, FR-17, FR-18, FR-20

- [x] **T6: "Save mine as a new note" and the disk-text endpoint**
  - Commands:
    - `php artisan make:request Notes/StoreNoteCopyRequest --no-interaction`
    - `php artisan make:controller NoteCopyController --invokable --no-interaction`
    - `php artisan make:controller NoteDiskController --invokable --no-interaction`
  - `NoteOperationException::copyNameUnavailable(string $relative)`, field `content`: "MDVault couldn't find a free name for a copy of “{relative}”. Rename or move some notes and try again. Your text is still in the editor."
  - `NoteService`:
    - Extract from `create()`, with unchanged behaviour:
      ```php
      private function registerNewFile(Vault $vault, string $relative, string $filename, string $stem, string $absolute, string $bytes): Note
      ```
      It hashes and sizes the file, inserts the record, and on a DB failure calls `deleteNewFileWithContents` and rethrows.
    - New method:
      ```php
      /** @throws NoteOperationException */
      public function createCopy(Vault $vault, string $sourcePath, string $content, NoteSaveMode $mode, ?FrontmatterEdit $frontmatter): Note;
      ```
      1. `assertVaultAvailable`.
      2. `$sourcePath` must not contain `\`, NUL, a leading `/` or a drive letter, and no segment may be `''`, `.` or `..`. It must end in `.md` (case-insensitive). Otherwise `invalidFolder('source_path')`.
      3. `$folder = parentFolder($sourcePath)`, `$stem = assertValidNoteName(basename($sourcePath))`.
      4. If `$folder !== ''` and `! isDirectory(joinRelative(vault, folder))` → `$folder = ''`. Otherwise `[$folder] = resolveFolder($vault, $folder, 'source_path')` (symlink and inside-vault checks).
      5. Envelope:
         - `$srcAbs = joinRelative($vault->path, $sourcePath)`;
         - if `isFile && ! isSymlink && size ≤ EDIT_LIMIT` and `read()` isn't null, `$doc = decode($raw)`, and if `! $doc->validUtf8` then `$doc = decode('')`;
         - otherwise `$doc = decode('')`.
      6. Bytes: same compose and encode as `save()` (rich: `composeRich($doc, $content, $frontmatter)`; source: `composeSource`), then `encode($source, $doc->eol, $doc->hasBom)`. Longer than `EDIT_LIMIT` → `contentTooLarge()`.
      7. Candidates:
         - `[$stem]`;
         - then `mb_substr($stem, 0, 100).' (my version)'`;
         - then `mb_substr($stem, 0, 100)." (my version {$n})"` for n = 2..20.

         For each candidate:
         - `$relative = relativeFor($folder, $cand.'.md')`;
         - `try { assertNoConflict($vault, $relative, $abs, 'content', null, null); } catch (NoteOperationException) { continue; }`;
         - `if (! createFile($abs, $bytes)) { if (exists($abs)) continue; throw createFailed($relative, 'content'); }`;
         - `return registerNewFile(...)`.

         All candidates exhausted → `copyNameUnavailable($sourcePath)`.
  - `StoreNoteCopyRequest` rules:
    - `source_path`: `['required', 'string', 'max:1024']`
    - `content`: same closure rule as `SaveNoteContentRequest`
    - `mode`: `['required', Rule::enum(NoteSaveMode::class)]`
    - `has_frontmatter`: `['required_if:mode,rich', 'boolean', 'prohibited_unless:mode,rich']`
    - `frontmatter`: `['nullable', 'string']`

    Accessors `contentText()` and `frontmatterEdit()` work exactly as in `SaveNoteContentRequest`. Extract a shared trait `app/Http/Requests/Notes/InteractsWithNoteContent.php` used by both requests.
  - `NoteCopyController::__invoke(StoreNoteCopyRequest $request, Vault $vault, NoteService $notes): JsonResponse`:
    - `try { $copy = $notes->createCopy(...); } catch (NoteOperationException $e) { throw ValidationException::withMessages([$e->field() => $e->getMessage()]); }`
    - Returns `response()->json(['uuid' => $copy->uuid, 'title' => $copy->title, 'relative_path' => $copy->relative_path], 201)`.
  - `NoteDiskController::__invoke(Note $note, NoteService $notes): JsonResponse` → `$p = $notes->preview($note)`; returns `['state' => $p['state'], 'content' => $p['content'], 'base_hash' => $p['base_hash']]`.
  - Routes:
    - `Route::post('vaults/{vault:uuid}/notes/copy', NoteCopyController::class)->whereUuid('vault')->name('vaults.notes.copy');` (**above** any conflicting pattern)
    - `Route::get('notes/{note:uuid}/disk', NoteDiskController::class)->whereUuid('note')->name('notes.disk.show');`
  - Tests:
    - `tests/Feature/Notes/NoteCopyTest.php`:
      1. A `changed` conflict scenario (the original edited externally): POST rich → 201; `Projects/X (my version).md` exists with the frontmatter from the request and the body; the original keeps the external bytes; the original's UUID is unchanged.
      2. A second copy → `(my version 2)`.
      3. The source deleted → recreated at the original path; the new UUID differs from the deleted one.
      4. The source folder deleted → created at the vault root.
      5. A CRLF + BOM original → the copy is CRLF + BOM.
      6. Source mode is verbatim.
      7. `source_path` values `../x.md`, `C:/x.md`, `a\\b.md` and `x.txt` → 422 on `source_path`.
      8. Content over 1 MiB → 422.
      9. The name exhausted (20 occupied candidates, created with `writeVaultFiles`) → 422 `content`.
      10. DB failure on insert (a `Note::creating` listener throws) → the new file is removed and the original is untouched.
      11. No `id`/`vault_id` in the JSON.
    - `tests/Feature/Notes/NoteDiskTest.php`: 200 with the current disk text after an external edit; the file missing → `state: missing`; unknown uuid → 404.
  - Covers: FR-14, FR-15, FR-16

- [x] **T7: Wayfinder and types**
  - `php artisan wayfinder:generate --with-form --no-interaction`. Expected: `@/routes/vaults/changes` (`check`), `@/routes/vaults/notes` (`copy`), `@/routes/notes/disk` (`show`). Verify the exact export names in the generated files and use them.
  - `resources/js/types/notes.ts`:
    ```ts
    export type RemoteOpenNote = { uuid: string; exists: boolean; relative_path: string | null; file_hash: string | null };
    export type VaultChangeCheckResponse = { status: 'ok' | 'busy' | 'unavailable' | 'disabled' | 'inactive'; changed: boolean; tree_signature: string | null; open_note: RemoteOpenNote | null; orphan_temp_files: string[] };
    export type NoteCopyResponse = { uuid: string; title: string; relative_path: string };
    export type NoteDiskResponse = { state: NotePreviewState; content: string | null; base_hash: string | null };
    ```
    Export them through `@/types` like the existing types.
  - Covers: FR-08, FR-15, FR-16

- [x] **T8: Framework-free client modules (with Vitest)**
  - **`resources/js/lib/external/changeChecker.ts`**:
    ```ts
    export const CHECK_INTERVAL_MS = 5_000;
    export const MAX_CHECK_INTERVAL_MS = 60_000;
    export const TRIGGER_DEBOUNCE_MS = 250;
    export const DURATION_FACTOR = 10;
    export type CheckRunResult = 'ok' | 'skipped' | 'error' | 'stop';
    export type ChangeChecker = { start(): void; trigger(): void; setActive(active: boolean): void; dispose(): void };
    export function createChangeChecker(options: { run: () => Promise<CheckRunResult>; now?: () => number; intervalMs?: number; maxIntervalMs?: number; debounceMs?: number }): ChangeChecker;
    ```
    Rules:
    - `start()` = `trigger()` plus the interval loop.
    - `trigger()` debounces by `debounceMs`. If a run is in flight, set `again`, and run once more (debounced) after it settles.
    - There is never more than one run at a time.
    - After `ok`: `delay = min(max, max(interval, duration × DURATION_FACTOR))`.
    - After `error`: `delay = min(max, (lastDelay || interval) × 2)`; reset on the next `ok`.
    - After `skipped`: `delay = interval`.
    - After `stop`: dispose (no further runs; later triggers are ignored).
    - `setActive(false)` clears the timers, and triggers are ignored while inactive. `setActive(true)` triggers immediately.
    - `dispose()` clears everything, and a late result is ignored.
  - **`resources/js/lib/external/openNoteStatus.ts`**:
    ```ts
    export type LocalOpenNote = { uuid: string; relativePath: string; baseHash: string };
    export type OpenNoteChange =
      | { kind: 'unchanged' }
      | { kind: 'moved'; relativePath: string }
      | { kind: 'changed'; relativePath: string; currentHash: string; moved: boolean }
      | { kind: 'deleted' };
    export function classifyOpenNote(local: LocalOpenNote, remote: RemoteOpenNote): OpenNoteChange;
    export type OpenNoteAction = 'none' | 'refresh' | 'resume' | 'reload' | 'leave' | 'conflict-changed' | 'conflict-missing';
    export function decideOpenNoteAction(change: OpenNoteChange, editor: { dirty: boolean; conflict: 'changed' | 'missing' | null }): OpenNoteAction;
    ```
    `classifyOpenNote`, in this order:
    1. `remote.uuid !== local.uuid` → unchanged.
    2. `! remote.exists` → deleted.
    3. `remote.file_hash === null` → unchanged.
    4. `moved = remote.relative_path !== local.relativePath`.
    5. `remote.file_hash !== local.baseHash` → changed (with `moved`).
    6. `moved` → moved.
    7. Otherwise unchanged.

    `decideOpenNoteAction` (exhaustive, test every cell):

    | change \ editor | clean, no conflict | dirty, no conflict | conflict `changed` | conflict `missing` |
    |---|---|---|---|---|
    | unchanged | none | none | resume | resume |
    | moved | refresh | refresh | resume | resume |
    | changed | reload | conflict-changed | conflict-changed | conflict-changed |
    | deleted | leave | conflict-missing | conflict-missing | none (already shown) |

  - **`resources/js/lib/editor/visitSafety.ts`**:
    ```ts
    export function isEditorSafeVisit(visit: { url: URL; method: string; only: string[] }, currentUrl: string): boolean;
    ```
    It returns true only when the method is `get`, `only.length > 0`, `! only.includes('note')`, and `visit.url.pathname === new URL(currentUrl).pathname`. Check the `PendingVisit` field names (`url`, `method`, `only`) with `search-docs`.
  - **`noteSaver.ts`** additions, to both the type and the implementation:
    ```ts
    /** Enter a conflict detected by an external-change check (not by a save). Ignored while a save is in flight. Pauses autosave; keeps the pending edit. */
    externalConflict(reason: 'changed' | 'missing', currentHash: string | null, message: string): void;
    /** Clear any conflict (the file is back where the saver expects it, or the external change was undone) and flush. */
    resume(): Promise<'clean' | 'saved' | 'failed'>;
    ```
    - `externalConflict`: if `flushPromise !== null`, return. Otherwise clear the timers, set `hadFailure = true`, status `conflict`, `conflict = { reason, currentHash }`, `message`, and emit. If already in a conflict with the same reason, only update `currentHash` and `message`.
    - `resume`: set `conflict = null`, `hadFailure = false`, status `pending ? 'dirty' : 'clean'`, emit, then `return flush()`.
  - Tests:
    - `tests/js/external/changeChecker.test.ts` (fake timers): debounce; single flight plus one follow-up; the interval; the adaptive delay (a 2 s run → 20 s; a 10 s run → 60 s cap); error back-off doubling and reset; skipped; stop; inactive ignores triggers and reactivation triggers; a late result after dispose is ignored.
    - `tests/js/external/openNoteStatus.test.ts`: every classify branch; the full decision table (a dataset).
    - `tests/js/editor/visitSafety.test.ts`: tree-only reload → true; `only` including `note` → false; `only` empty → false; another path → false; POST → false.
    - `tests/js/editor/noteSaver.test.ts` (extend):
      - `externalConflict` pauses autosave (no send after the timers); `isDirty()` is true; `overwrite()` then uses the external `currentHash`;
      - `externalConflict` during an in-flight save is ignored;
      - `resume()` clears a `missing` conflict and sends;
      - `resume()` when clean → `'clean'` with no send.
  - Covers: FR-09, FR-10, FR-11 to FR-14

- [x] **T9: Vue wiring**
  - **`useUnsavedChangesGuard.ts`**: at the top of `handleBefore`, `if (isEditorSafeVisit(event.detail.visit, window.location.href)) { return; }` (no freeze, no flush). The `finish` unfreeze stays.
  - **`resources/js/composables/useExternalChanges.ts`**:
    ```ts
    export type ExternalChangeTarget = {
      noteUuid(): string;
      externalCheckToken(): number | null;             // null while a save is in flight
      applyExternalStatus(token: number, remote: RemoteOpenNote): void;
    };
    export function useExternalChanges(options: {
      vaultUuid: () => string | null;
      enabled: () => boolean;
      vaultActive: () => boolean;
      treeSignature: () => string | null;
      editor: () => ExternalChangeTarget | null;
    }): { requestCheck(): void; orphanTempFiles: Ref<string[]> };
    ```
    - `run()`:
      1. There is no vault → `'stop'`.
      2. `const target = editor(); const token = target ? target.externalCheckToken() : null; if (target && token === null) return 'skipped';`
      3. POST `check.url(vaultUuid)` with `{ open_note: target?.noteUuid() ?? null }` through `useHttp` (as in `NoteEditor.send`).
      4. Map the response:
         - `disabled`/`inactive` → `'stop'`;
         - `busy` → `'skipped'`;
         - `unavailable` → if `vaultActive()`, call `router.reload()`; return `'ok'`;
         - `ok`:
           - if `! vaultActive()`, `router.reload()` and return;
           - if `tree_signature !== treeSignature()`, `router.reload({ only: ['tree', 'folders', 'treeSignature'] })`;
           - if `open_note` and `target`, `target.applyExternalStatus(token!, open_note)`;
           - `orphanTempFiles.value = orphan_temp_files`.
      5. Network or other errors → `'error'`.
    - Lifecycle:
      - `onMounted`: if `enabled()`, create the checker and `start()`.
      - `window` `focus` → `trigger()`.
      - `document` `visibilitychange` → `setActive(visible && ! navigating)`.
      - `router.on('start')` → `navigating = true; setActive(false)`; `router.on('finish')` → `navigating = false; setActive(visible)`.
      - `onUnmounted`: dispose and remove every listener.
    - `requestCheck()` → `checker?.trigger()`. When the checker is disabled, it does nothing; the banner then shows Re-index instead, see below.
  - **`Workspace.vue`**:
    - Add the props `treeSignature: string | null` and `checkExternalChanges: boolean`.
    - `const editorRef = ref<InstanceType<typeof NoteEditor> | null>(null)`.
    - Call `useExternalChanges({ vaultUuid: () => props.currentVault?.uuid ?? null, enabled: () => props.checkExternalChanges, vaultActive: () => props.currentVault?.status === 'active', treeSignature: () => props.treeSignature, editor: () => editorRef.value })`.
    - `<NoteEditor ref="editorRef" … :external-checks="checkExternalChanges" @request-check="requestCheck" />`.
    - Render `<OrphanSaveNotice :paths="orphanTempFiles" />` above the tree/editor row when the list is non-empty.
  - **`NoteEditor.vue`**:
    - New prop `externalChecks: boolean`; `emit('request-check')`.
    - Save epoch: `let epoch = 0`. In `send()`, `epoch++` before the request and again when it resolves.
    - `externalCheckToken()` returns `saveState.value.status === 'saving' ? null : epoch`.
    - `localSnapshot()` returns `{ uuid, relativePath: props.note.relative_path, baseHash: saver?.getState().baseHash || props.note.base_hash || props.note.file_hash }`.
    - `applyExternalStatus(token, remote)`:
      - ignore it if `token !== epoch` or the status is `saving`;
      - `change = classifyOpenNote(localSnapshot(), remote)`;
      - `action = decideOpenNoteAction(change, { dirty: isDirty(), conflict: saveState.value.conflict?.reason ?? null })`.

      Actions:
      - `refresh`: `router.reload({ only: ['note', 'tree', 'folders', 'treeSignature'] })`.
      - `resume`: `await saver?.resume()`, then `refresh`.
      - `reload`: `discard()`; `toast.info('“{title}” was changed outside MDVault and has been reloaded.')`; `refresh` (the new `base_hash` remounts the editor through the Workspace `:key`).
      - `leave`: `discard()`; `toast.warning('“{relative_path}” was deleted or moved outside MDVault.')`; `router.visit(workspace.url())`.
      - `conflict-changed`: `saver?.externalConflict('changed', change.currentHash, msg)`. The message is "“{relative_path}” was changed outside MDVault while you were editing. Your edits haven't been saved yet.", plus " It was also moved to “{change.relativePath}”." when `moved`. If there is no saver (still assessing), treat it as `reload`.
      - `conflict-missing`: `saver?.externalConflict('missing', null, '“{relative_path}” was deleted or moved outside MDVault, and MDVault couldn't find where it went. Your text is still in the editor.')`.
    - When `send()` resolves with a `conflict` of reason `missing`, `emit('request-check')`, so a pure move resolves itself through `resume`.
    - `defineExpose({ noteUuid: () => props.note.uuid, externalCheckToken, applyExternalStatus })`.
    - New conflict actions:
      - `saveAsNewNote()`:
        - payload `{ source_path: props.note.relative_path, ...(mode rich ? { ...richSavePayload(decodeRichContent(readContent())), mode: 'rich' } : { content: readContent(), mode: 'source' }) }`;
        - `useHttp(payload).post(copy.url(props.vaultUuid))`;
        - on success: `discard()`, `toast.success('Your version was saved as “{relative_path}”.')`, `router.visit(show.url(uuid))`;
        - on 422: `toast.error(first message)` and keep the banner.
      - `openCompare()`: `compareOpen = true`, `compareMine = mode rich ? richContentAsText(decodeRichContent(readContent())) : readContent()`.
      - `discardMissing()`: `discard()`, then `emit('request-check')` (the check then leaves or refreshes); when `! externalChecks`, `router.visit(workspace.url())`.
      - `checkAgain()`: `externalChecks ? emit('request-check') : runReindex()`.
  - **`NoteConflictAlert.vue`**:
    - New prop `externalChecks: boolean`.
    - Emits: `reload`, `overwrite`, `copy`, `saveAsNew`, `compare`, `check`, `discard`. Remove `reindex`; its role moves to `check`.
    - `changed`: Reload from disk (existing confirm), Keep my version (existing confirm), **Save mine as a new note**, **Compare**, Copy my text.
    - `missing`: **Save as a new note**, Copy my text, **Check again** (or "Re-index vault" when `! externalChecks`), **Discard my edits**, with the confirm "Discard your unsaved edits? They haven't been saved anywhere."
    - Keep the single root `<div class="contents">`.
  - **`NoteCompareDialog.vue`** (new; single root `Dialog`):
    - Props `open` (v-model), `noteUuid`, `title`, `mine: string`.
    - On open: GET `disk.url(noteUuid)` through `useHttp`; states loading / error / `missing` ("The file is no longer on disk.").
    - Content: `diffLines(disk.content ?? '', mine)` from `diff`, rendered as `<pre>` lines. Added lines get `bg-emerald-500/10` with a `+` prefix, removed lines `bg-red-500/10` with a `-` prefix, unchanged lines a muted style. The heading reads "On disk → Your version".
    - Interpolation only, **no `v-html`**. Scrollable, `max-h-[70vh]`.
    - If G5 is declined: two side-by-side read-only `<pre>` panes with no highlighting and no dependency.
  - **`OrphanSaveNotice.vue`** (new):
    - An `Alert` with "An interrupted save left a recovery file in this vault. It may hold text you typed. Open it in a text editor to recover it; MDVault never deletes these files." plus a `<ul>` of paths, and a Dismiss button.
    - Dismissal is remembered for the session in a module-level `Set` keyed by path.
  - **`settings/General.vue`**: replace "Takes effect once vaults are available." with "When on, MDVault checks the open vault for changes made by other programs whenever its window is focused and every few seconds while it is visible. Opening and saving a note always checks the file, even when this is off."
  - Presentation only: no path logic in Vue beyond passing `relative_path` strings back unchanged; no filesystem; no `v-html`.
  - Covers: FR-09 to FR-16, FR-18, FR-19, FR-20

- [x] **T10: Quality gates and handover**
  - Run:
    - `php vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (full suite)
    - `vendor/bin/phpstan analyse` (no new baseline entries)
    - `npm run test:js`
    - `npm run types:check`
    - `npm run build`
    - `npm run check`
    - `php artisan wayfinder:generate --with-form --no-interaction`
  - Greps. Each must give the stated result:
    - `rg -n "v-html" resources/js` → none.
    - `rg -n "Schema::create" database/migrations` → only the existing tables (no new tables).
    - `rg -n "content" database/migrations/*file_mtime*` → none.
    - `rg -n "replaceFile\(" app` → the definition plus `NoteService::save` only.
    - `rg -n "ChildProcess|chokidar|fs\.watch" app resources/js` → none.
    - `rg -n "from '@/" resources/js/lib/external resources/js/lib/editor resources/js/lib/markdown` → none.
    - `rg -n "Co-Authored-By|Generated with" .` over the changed files → none.
  - `php artisan route:list --path=changes` and `--path=disk` and `--path=copy` show the three routes.
  - Record in `implementation.md`: every changed expectation in existing tests (T3), the Wayfinder export names (T7), and any `PendingVisit` field deviations.
  - **Manual desktop checks** (user, `composer native:dev`; VS Code and Explorer side by side):
    - **D1**: Edit a closed note in VS Code → within about 5 s, or immediately on focusing MDVault, opening it shows the edit; the footer hash matches.
    - **D2**: Create `Ideas/New.md` in Explorer → it appears in the tree without clicking anything.
    - **D3**: Delete a note in Explorer → it disappears from the tree.
    - **D4**: Rename the **open, clean** note in Explorer → the header path updates with no flicker and the URL (UUID) is unchanged.
    - **D5**: Move the open note to another folder in Explorer → same as D4.
    - **D6**: Rename a folder containing notes → the tree updates, and the open note under it follows (same URL).
    - **D7**: Create and delete an empty folder in Explorer → the tree updates.
    - **D8**: The open note is clean; edit and save it in VS Code → MDVault reloads it with a toast.
    - **D9**: Type continuously in MDVault while saving an edit in VS Code → the banner appears (from the check or from the 409), and VS Code's text is still on disk.
    - **D10**: From D9, Keep my version (confirm) → the disk has MDVault's text.
    - **D11**: From D9, Save mine as a new note → `X (my version).md` opens with my text; the original shows VS Code's text.
    - **D12**: From D9, Compare → removed and added lines are marked correctly.
    - **D13**: Delete the open, clean note in Explorer → the Workspace shows no note, with a toast. Repeat while typing → the missing banner appears; Save as a new note recreates it at the original path.
    - **D14**: Move the open note in Explorer while typing → editing continues, the saved text lands at the new location (check in VS Code), and no banner stays visible.
    - **D15**: Turn the General setting off → no automatic detection (DevTools Network shows no `changes` calls). Opening a note still shows the disk content, and saving over an external edit still conflicts.
    - **D16**: Minimise MDVault → no `changes` calls. Restore it → one immediate call.
    - **D17**: Rename the vault folder in Explorer while it is open → the missing-vault alert appears within about 5 s. Rename it back → the vault recovers without a restart.
    - **D18**: Generate 5,000 notes with a script → typing stays responsive, and after the first pass `changes` calls take under about 500 ms.
    - **D19**: `git checkout` another branch of a vault repository → the tree updates, and unchanged notes keep their UUIDs (their URLs still open).
    - **D20**: Place a `.mdvault-save-test` file with a modified time more than 60 s old → the orphan notice lists it; the file is untouched after dismissing.
    - **D21**: In-app create, rename, move, delete and save → no external-change toasts or banners.
    - **D22**: With Wi-Fi off, all of the above works.
  - Covers: all FRs (verification)

---

- [x] **T11 (Revision 2): Analyst-review fixes** (see `analyst-review.md` §5)
  - AR-01: `routes/notes.php` `notes.show` gets a `->missing()` handler that, for Inertia partial reloads only (non-empty `X-Inertia-Partial-Data`), renders `WorkspaceController` with `note = null`; full visits still 404. `useExternalChanges.ts` ignores async visits in its `start`/`finish` handlers. Tests in `tests/Feature/WorkspaceTest.php` (partial tree reload on a deleted uuid → 200 with the check's signature; partial `note` reload → `note` null; full GET still 404).
  - AR-02: `useExternalChanges.run()` skips results after dispose or navigation and only applies them to the same editor instance; `NoteEditor.applyExternalStatus` ignores calls after unmount; `useUnsavedChangesGuard` does not unfreeze on the finish of an editor-safe visit.
  - AR-03: `NoteService::preview()` and private `reconcile()` set `file_mtime` to null whenever they change `file_hash`/`file_size` (no write on a no-op save). Tests for save, preview and the no-op case.
  - QA-03: `NoteCopyTest` DB-failure variant with a stale row at the recreate path; the stale row survives with its uuid and the new file is removed.
  - Covers: FR-06, FR-10, FR-14, FR-15, FR-17

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Services/VaultReconcileTest.php` | Create, modify, delete, rename, move, move+edit, rename+edit, folder rename with duplicate hashes, ambiguity, case-only, quick shortcut and verify path, racy mtime, no spurious writes, stale guard, unreadable, missing root, orphans, tree signature, ignored names | FR-01 to FR-07, FR-17, FR-19 |
| `tests/Feature/Services/VaultIndexServiceTest.php` | Existing tests; updated expectations for the basename step | FR-04 |
| `tests/Feature/Services/FileStorageServiceTest.php` | `scan` mtime, `modifiedTime` | FR-06 |
| `tests/Feature/Services/ExternalChangeServiceTest.php` | Every status; `open_note` states; cross-vault uuid; own save isn't external | FR-08, FR-17, FR-18, FR-20 |
| `tests/Feature/Vaults/VaultChangeCheckTest.php` | HTTP contract, validation, 404/405, disabled | FR-08 |
| `tests/Feature/Vaults/VaultManagementTest.php` | Open uses Quick; manual Re-index is Full | FR-06 |
| `tests/Feature/Notes/NoteCopyTest.php` | Copy naming, recreate on delete, root fallback, envelope, validation, exhaustion, DB-failure compensation | FR-14, FR-15 |
| `tests/Feature/Notes/NoteDiskTest.php` | Disk text, missing, 404 | FR-16 |
| `tests/Feature/WorkspaceTest.php` | `treeSignature` equals the check's signature; `checkExternalChanges` prop | FR-05, FR-18 |
| `tests/Feature/DatabaseSchemaTest.php` | `file_mtime` exists; no `content` | FR-06, FR-21 |
| `tests/Unit/ArchitectureTest.php` | `ExternalChangeService` has no raw filesystem calls | FR-21 |
| `tests/js/external/changeChecker.test.ts` | Scheduling rules | FR-09 |
| `tests/js/external/openNoteStatus.test.ts` | Classification and the full decision table | FR-11 to FR-14 |
| `tests/js/editor/visitSafety.test.ts` | Guard exemption | FR-10 |
| `tests/js/editor/noteSaver.test.ts` | `externalConflict`, `resume` | FR-12, FR-13 |
| (static) greps, `types:check`, `build`, `check` | Boundaries, no `v-html`, no watcher code, no new tables | FR-21 |
| (manual) D1–D22 | Desktop behaviour | FR-01 to FR-20 |

**Test scope for QA**:
- Targeted first:
  ```
  php artisan test --compact tests/Feature/Services/VaultReconcileTest.php tests/Feature/Services/VaultIndexServiceTest.php tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/ExternalChangeServiceTest.php tests/Feature/Services/NoteServiceTest.php tests/Feature/Vaults tests/Feature/Notes tests/Feature/WorkspaceTest.php tests/Feature/DatabaseSchemaTest.php tests/Unit/ArchitectureTest.php
  npm run test:js
  ```
- Then the **full suite** `php artisan test --compact`. It is required because of the migration, the `IndexResult` constructor, the `VaultIndexService` API, the `NoteService` refactor, and the Workspace props and routes.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check`, and the T10 greps.
- Code review:
  - reconcile writes no files and applies all-or-nothing behind the fingerprint guard;
  - touches don't bump `updated_at` and aren't reported;
  - no content is overwritten without a confirmed Keep mine;
  - `createCopy` uses exclusive create only, with exact-bytes compensation;
  - the guard exemption applies only to tree-only partial reloads;
  - the check token prevents acting on stale results;
  - no path or filesystem logic in Vue; no `v-html`; single-root components;
  - no sync, device or version tables or naming;
  - no AI attribution.
- D1–D22 are **user-verified manual checks**, not defects.

---

## 5. Risks & Mitigations
- **Risk**: polling misses changes made while the window is hidden or minimised, until the next focus. **Mitigation**: an immediate check on focus/visibility; note-open verification; the save guard.
- **Risk**: an mtime-preserving same-size edit to a non-open note is missed by quick mode. **Mitigation**: the racy-mtime rule; opening that note re-hashes it; manual Re-index (Full). Documented in the ADR (G2).
- **Risk**: large vaults make checks slow and block the single-threaded PHP server. **Mitigation**: the stat-only quick path; the adaptive delay (≤ 10 % duty, 60 s cap); pausing while hidden; D18.
- **Risk**: false identity pairing by the basename step. **Mitigation**: only unique 1:1 pairs; content differences always go through the conflict path; the banner names the new path; documented.
- **Risk**: an external tool's intermediate write (truncate-then-write) is observed. **Mitigation**: a clean note just reloads again; dirty → banner only; "Keep my version" re-checks the hash (409 if it moved on).
- **Risk**: a stale check result arrives after a save and triggers a false conflict. **Mitigation**: the save epoch token; no checks during saves; the server applies inside a transaction with a fingerprint guard.
- **Risk**: an auto-reload of a clean note discards the user's selection or scroll. **Mitigation**: acceptable (no content is lost); a toast explains it.
- **Risk**: tree reloads interrupt typing through the unsaved-changes guard. **Mitigation**: the `isEditorSafeVisit` exemption (FR-10) with tests.
- **Risk**: the developer adds a watcher or native events "to be safe". **Mitigation**: the ADR, the grep, and a code-review item.
- **Risk**: `diff` (G5) isn't approved. **Mitigation**: the documented side-by-side fallback.

---

## 6. Open Questions (user approvals; recommended answers in bold)
- [x] **G1: Detection mechanism.** (approved as recommended, 2026-09-30) This is also an interpretation of Master Plan §24/§54 wording ("filesystem watcher"). Options:
  - (a) **Hybrid polling (recommended)**: quick checks on Workspace mount, window focus/visibility and every 5 s while visible (adaptive), plus note-open verification, the save base-hash guard and a manual full Re-index;
  - (b) a native watcher: a NativePHP `ChildProcess::node` script using Node `fs.watch({ recursive: true })` or chokidar, pushing events to Laravel and then to the renderer through `window.Native.on`;
  - (c) hybrid plus native: (a) now, and (b) as an extra trigger later.

  **Recommended: (a) now, with (c) as the documented upgrade path.** Why:
  - NativePHP 2.3.1 has no watcher API, and chokidar isn't a production dependency.
  - A recursive watcher holds the vault directory open on Windows, which blocks the in-app vault rename and trash.
  - Watcher events are lossy and still need a reconcile scan.
  - (a) is fully testable with Pest and Vitest, and works in browser dev.
- [x] **G2: Quick-scan schema and vault-open mode.** (approved as recommended, 2026-09-30)
  - **Add the nullable `notes.file_mtime` column (migration)**, used to skip hashing unchanged files (racy-mtime rule: mtimes within 2 s of the scan are stored as `null`).
  - **Vault open/create/add reconcile in Quick mode; manual Re-index stays Full.**
  - Alternatives: no column (hash every file on every check; O(vault bytes) every 5 s); a cache-stored snapshot.
- [x] **G3: Identity heuristic.** (approved as recommended, 2026-09-30) **Add a fourth pairing step, "unique file name"**, after exact path → case-insensitive path → unique hash. Moves or folder renames that also change content, or that involve duplicate-content files, then keep their UUID. Pairing is 1:1 only.
  - Alternatives: keep the Phase 3 three steps (new UUIDs in those cases); add a "single vanished/new pair in the same folder" step (rejected: pairs unrelated delete+create).
- [x] **G4: Open-note policy.** (approved as recommended, 2026-09-30) **Recommended:**
  - clean + changed → auto-reload with a toast;
  - dirty + changed → banner: Reload from disk (confirm) / Keep my version (confirm) / **Save mine as a new note** / **Compare** / Copy my text; autosave paused;
  - moved → follow in place (saves go to the new path; a pending `missing` conflict clears itself);
  - deleted + clean → close the note with a toast;
  - deleted + dirty → banner: Save as a new note (recreated at the original path) / Copy / Check again / Discard.
  - The copy is named `<name> (my version).md`, then `(my version 2)` … up to 20, and after saving MDVault opens the copy.
- [x] **G5: Compare view dependency.** (approved as recommended, 2026-09-30) **Approve `diff@^9.0.0`** (jsdiff: BSD-3-Clause, no runtime dependencies, bundled types) for a highlighted line diff. Alternative: side-by-side panes without highlighting (no dependency).
- [x] **G6: Setting and interval.** (approved as recommended, 2026-09-30) **`app.check_external_changes` (existing, default on) enables only the automatic focus/interval checks. The interval is a fixed 5 s with adaptive back-off, not user-configurable in v1.** Note-open verification, the save guard and manual Re-index always run. Alternative: add a `general.external_check_interval` setting.
- [x] **G7: Orphan `.mdvault-save-*` files.** (approved as recommended, 2026-09-30) Options:
  - (a) defer;
  - **(b) detect orphans older than 60 s and show a dismissible notice listing their paths; never delete or index them (recommended)**;
  - (c) full recovery UI (restore as note / move to trash).
- [x] **G8: New folders.** (approved as recommended, 2026-09-30) **Approve `resources/js/lib/external/` and `tests/js/external/`.** No composer changes. One migration (G2). One npm dependency (G5).

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-30 | Initial plan | — |
| 2 | 2026-09-30 | Level 4 analyst review | T11 added: AR-01 (404 loop on tree refresh of a deleted open note), AR-02 (stale-result and unfreeze guards), AR-03 (null file_mtime on in-app hash updates), QA-03 test. No ADR decision change. |
| 3 | 2026-09-30 | Delivered | Level 4 sign-off; follow-ups F1–F6 recorded in analyst-review.md §6 |
