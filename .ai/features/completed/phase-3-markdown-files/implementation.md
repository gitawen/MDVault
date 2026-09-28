# Implementation: Phase 3: Markdown Filesystem

## Metadata
- **Feature Name**: Phase 3: Markdown Filesystem
- **Feature ID**: mdv-p3
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1 (APPROVED, E1–E10 as recommended)
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T0 — Preconditions | DONE (orchestrator) | Approvals recorded, ADRs saved, checkpoint commit `2500c0e` on `phase-3-markdown-files`. |
| T1 — `notes` schema, `Note` model, factory, relations, arch rules | DONE | `make:model Note -mf` landed at the correct paths. |
| T2 — `FileHashService` | DONE | |
| T3 — `FileStorageService` file primitives and scan | DONE | `scan()` walks with an explicit stack and a `for ($iterator->rewind(); …)` loop (see Deviations). |
| T4 — `NoteOperationException`, `IndexResult`, `VaultIndexService` | DONE | |
| T5 — `NoteService` | DONE | Constructor drops the unused `VaultIndexService $index` (see Deviations). |
| T6 — HTTP layer, index triggers, Workspace props | DONE | |
| T7 — Note tree and dialogs (frontend) | DONE | |
| T8 — Note viewer and Workspace layout | DONE | `TiptapEditor.vue` unchanged (verified via `git diff`). |
| T9 — Quality gates and handover | DONE | See §3. |

---

## 2. Files Changed

### Created
| Path | Summary |
|---|---|
| `database/migrations/2026_09_27_161820_create_notes_table.php` | `notes` table per plan §2 (no `content` column). |
| `app/Models/Note.php` | `HasUuids` (`uniqueIds() = ['uuid']`) + `HasFactory`; `$hidden = ['id','vault_id']`; `MIME_TYPE` const; `vault(): BelongsTo`. |
| `database/factories/NoteFactory.php` | Literal empty-string SHA-256 default, matching the plan (no hashing function used in the factory). |
| `app/Services/FileHashService.php` | `hashFile`/`hashString`/`matches`, streamed via `hash_file`. |
| `app/Services/FileStorageService.php` (modified) | Added `isSymlink`, `isSameFile`, `createFile`, `deleteNewEmptyFile`, `read`, `size`, `joinRelative`, `renameFile` (+ private `attemptFileMove`), `scan`. |
| `app/Exceptions/NoteOperationException.php` | 15 named constructors per plan §4 table, each with `field()`. |
| `app/Support/IndexResult.php` | `final readonly class` with `hasChanges()`/`summary()`. |
| `app/Services/VaultIndexService.php` | `isIgnoredName`, `isIndexableFileName`, `reindex` (exact → case-insensitive → hash matching, one transaction, deletes→updates/moves→inserts), `browse`. |
| `app/Services/NoteService.php` | create/rename/move/delete, createFolder/deleteFolder, preview/present/absolutePath/canTrash, plus `resolveFolder`/`assertNoConflict`/`relocate` private helpers. |
| `app/Http/Requests/Notes/{NoteNameRules,StoreNoteRequest,UpdateNoteRequest,MoveNoteRequest,StoreFolderRequest,DestroyFolderRequest}.php` | Shape validation; `NoteNameRules` delegates to `NoteService::assertValidNoteName`/`assertValidFolderName`. |
| `app/Http/Controllers/{NoteController,FolderController,VaultIndexController}.php` | Thin HTTP; each has a private `attempt()` mapping `NoteOperationException` → `ValidationException`. |
| `routes/notes.php` | The 8 routes in plan §2, `whereUuid` on every parameter. |
| `resources/js/types/notes.ts` | `NoteTreeFolder`, `NoteTreeNote`, `NoteTreeNode`, `NotePreviewState`, `NoteDetail`. |
| `resources/js/components/notes/NoteTree.vue` | Pane header (New note/New folder/Re-index), tree list, dialog hosting via `provide`. |
| `resources/js/components/notes/NoteTreeItem.vue` | Recursive folder/note row (self-referencing SFC) with dropdown actions. |
| `resources/js/components/notes/noteTreeActions.ts` | `NoteTreeActions` type + `noteTreeActionsKey` injection key. |
| `resources/js/components/notes/{CreateNoteDialog,CreateFolderDialog,RenameNoteDialog,MoveNoteDialog,DeleteNoteDialog,DeleteFolderDialog}.vue` | Controlled dialogs (`v-model:open`). |
| `resources/js/components/notes/NoteViewer.vue` | Read-only raw Markdown viewer with `ok`/`missing`/`too_large`/`unreadable` states. |
| `tests/Feature/Models/NoteTest.php` | UUID stability, hidden `id`/`vault_id`, relations, FK cascade, unique path. |
| `tests/Feature/Services/{FileHashServiceTest,VaultIndexServiceTest,NoteServiceTest}.php` | 7 / 16 / 41 scenarios respectively. |
| `tests/Feature/Notes/NoteManagementTest.php` | HTTP for every note/folder/reindex route, error mapping, `notes.show` props, other-vault redirect, 404s. |

### Modified
| Path | Summary |
|---|---|
| `app/Models/Vault.php` | Added `notes(): HasMany` + `@property-read` PHPDoc. |
| `app/Http/Controllers/WorkspaceController.php` | Injects `VaultIndexService`, `NoteService`; nullable `?Note $note = null`; `tree`/`folders`/`note`/`canTrash` props; other-vault redirect. |
| `app/Http/Controllers/VaultController.php` | `store`/`open` reindex the vault after a successful open (result surfaced only from `open`, per plan). |
| `app/Http/Controllers/ExistingVaultController.php` | `store` reindexes and appends the indexed count to the toast. |
| `routes/web.php` | `require __DIR__.'/notes.php';` after `vaults.php`. |
| `resources/js/pages/Workspace.vue` | New props; two-pane layout (`NoteTree` + `NoteViewer`) when a vault is active and `tree !== null`; unchanged Tiptap `<main>` otherwise. |
| `resources/js/types/index.ts` | Export `./notes`. |
| `tests/Pest.php` | Added `failFileMoves`, `writeVaultFiles`, `noteOperationField`; `fakeTrash()`'s `moveToTrash` now handles a file as well as a directory (see Deviations). |
| `tests/Unit/ArchitectureTest.php` | Widened the Vault-usage rule to include `App\Models`; added the Note-usage rule, the `hash_file`-boundary rule, and the "no raw filesystem in Index/Note services" rule. |
| `tests/Feature/DatabaseSchemaTest.php` | `notes` moved from the absent-tables dataset to present + a 13-column check + `content` absence. |
| `tests/Feature/Vaults/VaultManagementTest.php`, `ExistingVaultTest.php` | Added/extended assertions that opening/registering a vault indexes its `.md` files. |
| `tests/Feature/WorkspaceTest.php` | `tree: null`/`note: null`/`folders: []` on the no-vault assertion; `has('tree')` on restart; new "missing vault → null tree" test. |
| `resources/js/actions/**`, `resources/js/routes/**` | Regenerated via `wayfinder:generate --with-form`. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` on the final run (intermediate runs auto-fixed import ordering). |
| `php artisan test --compact` (full suite) | `421 tests, 415 passed, 1163 assertions, 6 skipped` (skips: 1 pre-existing Phase 1 Windows-only case, 1 pre-existing Phase 2 Linux-only case, 4 new symlink/Windows-only Phase 3 cases — this session runs on Windows). |
| `vendor/bin/phpstan analyse` (run as `php vendor/bin/phpstan analyse`) | `{"tool":"phpstan","result":"passed","errors":0}` (three fixes required, see Deviations). |
| `npm run types:check` | Clean (`vue-tsc --noEmit`, no output) after one fix (see Deviations). |
| `npm run build` | Succeeds; `Workspace` chunk rebuilt (429 kB, includes the new tree/dialog components). |
| `npm run check` | One `--fix` pass needed (formatting only, new files); final: "All 61 files are correctly formatted" / "Found no warnings or lint errors in 54 files". |
| `php artisan route:list --path=notes` | 5 routes (the 4 `/notes/...` routes plus `vaults.notes.store`, which also matches `--path=vaults`). |
| `php artisan route:list --path=vaults` | 12 routes: the 4 Phase 2 routes + 8 new ones. |
| `rg -n "content" database/migrations/*notes*` | No matches. |
| `rg -n "hash_file\|file_get_contents\|fopen\|unlink\|rmdir\|->move\(\|moveDirectory" app` | Only `FileHashService` (`hash_file`, plus a PHPDoc mention) and `FileStorageService` (`fopen`, `unlink`, `file_get_contents`, `rmdir`, `moveDirectory`, `->move(`), plus one expected false positive: `NoteController.php:45` calls `$notes->move(...)` (`NoteService::move()`, not a filesystem call — same pattern already noted in the Phase 2 implementation record for `VaultService::rename()`). |
| `rg -n "unlink" app/Services` | Only `FileStorageService::deleteNewEmptyFile`. |
| `rg -n "v-html" resources/js/components/notes resources/js/pages/Workspace.vue` | No matches. |
| `rg -n "moveDirectory\([^)]*true" app` | No matches. |
| `git diff --stat -- resources/js/components/editor/TiptapEditor.vue` | Empty — file unchanged. |
| `php artisan migrate --no-interaction` / `php artisan native:migrate --no-interaction` | `notes` table created in both the dev and native SQLite databases (run during T1). |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated after the T6 route changes; re-run at T9 with no functional diff. |

---

## 4. Deviations from Plan
- **`NoteService`'s constructor drops `VaultIndexService $index`.** The plan's T5 constructor signature lists it, but no method described in T5 (`create`/`rename`/`move`/`delete`/`createFolder`/`deleteFolder`/`preview`/`present`) calls into it — `deleteFolder()` removes stale records directly by relative-path prefix rather than delegating to a reindex. PHPStan (level 7, a required quality gate) flags an injected-but-never-read property as an error (`property.onlyWritten`) with no way to suppress it per the tool's own instructions ("do not add baseline entries… do not widen types just to make the error go away"). Removing the unused parameter is behaviourally inert (nothing in any test constructs `NoteService` with a specific argument count; `app(NoteService::class)` auto-resolves) and was the only fix consistent with "fix the underlying cause." No test or controller relies on the removed parameter.
- **`Note`'s `created_at`/`updated_at` PHPDoc is `?CarbonImmutable`, not `CarbonImmutable`** (unlike `Vault`, which is non-nullable). This matches the plan's own `present()` return-shape spec (`updated_at: ?string`) and resolves a PHPStan `nullsafe.neverNull` error on `$note->updated_at?->toIso8601String()` in `NoteService::present()` without removing the nullsafe operator the plan explicitly asks for. No behavioural change — `present()` is only ever called on a persisted note.
- **`FileStorageService::scan()`'s inner loop uses `for ($iterator->rewind(); $iterator->valid(); $iterator->next())` and calls `isLink()`/`getFilename()`/`isDir()`/`isFile()`/`getSize()` on the `\FilesystemIterator` instance itself**, instead of `foreach ($iterator as $entry) { $entry->isLink(); … }`. PHPStan's stub types `FilesystemIterator::current()` as `SplFileInfo|string` (flag-dependent, not resolvable statically), which fails level 7 on every method call on `$entry`. Since `FilesystemIterator extends DirectoryIterator extends SplFileInfo`, the iterator object itself exposes the exact same per-entry methods with concrete return types, so this is a mechanical rewrite with identical runtime behaviour (confirmed by the unchanged `scan()` test results).
- **`tests/Pest.php`'s `fakeTrash()` helper's `moveToTrash()` now does `is_dir($path) ? File::deleteDirectory($path) : File::delete($path)`**, instead of always `File::deleteDirectory($path)`. The Phase 2 helper only ever simulated trashing a vault *folder*; Phase 3's `NoteService::delete()` trashes a single *file*, and `File::deleteDirectory()` silently does nothing to a file path (the "delete moves the file to the fake trash" test failed with `trashFailed` until this was added). Backward compatible — every existing Phase 2 vault-trash test (which only ever passes directory paths) still passes unchanged.
- **Added a `noteOperationField(Closure $callback): string` helper to `tests/Pest.php`**, matching the shape of `VaultServiceTest`'s inline try/catch pattern but shared, since `NoteServiceTest`/`NoteManagementTest`'s ~35 field-assertion scenarios would otherwise repeat the same seven-line try/catch. No production code affected.
- No scope changes otherwise. E1–E10 were applied exactly as approved (Recycle Bin only, create+delete-empty-folder only, `.md`-only/case-insensitive/no-symlinks indexing, filename-stem titles with `.md` auto-suffix, synchronous indexing on open/create/add/manual, exact→case-insensitive→hash UUID matching with no ID writes to user files, raw read-only Markdown viewer, tree as a Workspace left pane, no new dependencies or folders beyond the three named).

---

## 5. Notes for QA
- **The `NoteController.php:45` grep false positive** (see §3) is the same pattern already accepted in the Phase 2 record for `VaultController`/`VaultService::rename()` — please confirm you read it the same way (the only *filesystem-level* move/rename calls are `FileStorageService::renameFile()`/`renameDirectory()`/`attemptFileMove()`/`attemptMove()`).
- **`VaultIndexService::reindex()`'s case-insensitive matching step (§4 T4 step 6) pairs on path alone, not content** — an external case-only rename *plus* a content edit still keeps the UUID and silently absorbs the new hash/size, because the plan's algorithm runs the case-insensitive step before the hash step and only requires "exactly one row, exactly one file" at that stage. This is a direct reading of the ADR/plan text, not an inference; worth a deliberate look since it's a subtler UUID-stability edge case than the ones explicitly tested (case-only rename with *unchanged* content, and rename+edit at a *different* path).
- **`NoteService::delete()`'s guard order**: the "already gone" check happens before the `canTrash()` check, so a browser-runtime (no trash) delete of an *already-missing* file still succeeds (record removed, `false` returned) rather than failing with `trashUnavailable`. This matches FR-14/plan step order literally; flagging in case QA expects the reverse.
- **`MoveNoteDialog.vue` does not pre-select the note's current folder** — the plan's dialog-prop list for `MoveNoteDialog` is `(note, folders)` only, unlike `CreateNoteDialog`/`CreateFolderDialog` which explicitly take a `defaultFolder`/`defaultParent`. Deriving the current folder from `note.path` client-side would violate the architecture's "Vue never joins or splits paths" rule (T7's "Presentation only" note), so the dialog always opens on "Top level" and the user must pick the destination explicitly. Worth confirming this reading against the plan's intent.
- **Manual desktop checks M1–M10** (plan T9) were **not** exercised in this session — no native shell available (same constraint noted in every prior phase's implementation record). They cover: add-existing-folder indexing with dot-folders hidden (M1), new/edit/rename/case-rename/external-rename+reindex note round-trips (M2–M6), Recycle Bin delete+restore+reindex (M7), empty vs. non-empty folder delete (M8), vault rename with a note open — no held handles (M9), and a ~2,000-file performance check (M10). These are user-verified manual checks per the plan's test-scope note, not defects.
- **`resources/js/components/notes/NoteTreeItem.vue`** self-references via its inferred SFC filename (`<NoteTreeItem>` inside its own `<CollapsibleContent>`), the standard Vue 3 `<script setup>` recursive-component pattern; no `defineOptions({ name: ... })` was needed.
- The two Windows-session skips that are new to this phase (`->skipOnWindows()` in `FileStorageServiceTest`/`VaultIndexServiceTest`, plus two `->skipOnWindows()` cases in `NoteServiceTest`) all exercise the symlink-is-never-indexed/traversed guarantee (FR-04, FR-19) and should be re-run on Linux/macOS CI if available.

---

## 6. Fix Rounds
*(none yet)*
