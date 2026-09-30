# Implementation: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 2
- **Status**: READY FOR QA (round 3)

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T0 — Preconditions | DONE (orchestrator did the rest) | `npm install diff@^9.0.0` run by this agent; ADRs, branch and amended follow-ups already in place before this session started. |
| T1 — `notes.file_mtime` column | DONE | Migration, `Note` model, `DatabaseSchemaTest`. |
| T2 — `FileStorageService` scan metadata | DONE | `scan()` returns `mtime`; new `modifiedTime()`. |
| T3 — Reconcile engine in `VaultIndexService` | DONE | `IndexMode`, `ReconcilePlan`, `IndexResult` additions, `plan()`/`apply()`/`reconcile()`/`treeSignature()`, `browse()` signature. New `VaultReconcileTest.php` (22 tests). No existing `VaultIndexServiceTest.php` expectations needed changing (see §4). |
| T4 — Quick mode on vault open/create/add | DONE | `VaultController::store`/`open`, `ExistingVaultController::store` use `reconcile(Quick)`; `stale` ignored/handled. One new regression test. |
| T5 — `ExternalChangeService`, check endpoint, Workspace props | DONE | Service, request, controller, route, `WorkspaceController` props. Full test coverage. |
| T6 — "Save mine as a new note" and disk endpoint | DONE | `NoteService::createCopy` (+ extracted `registerNewFile`), `InteractsWithNoteContent` trait, `StoreNoteCopyRequest`, `NoteCopyController`, `NoteDiskController`, routes. One deviation from the literal plan text (see §4). |
| T7 — Wayfinder and types | DONE | Generated; exports confirmed (see §4). New types added and exported via `@/types`. |
| T8 — Framework-free client modules | DONE | `changeChecker.ts`, `openNoteStatus.ts`, `visitSafety.ts`, `noteSaver.ts` additions. Full Vitest coverage (36 new tests). |
| T9 — Vue wiring | DONE | `useUnsavedChangesGuard` guard exemption, `useExternalChanges` composable, `NoteEditor.vue`, `NoteConflictAlert.vue`, `NoteCompareDialog.vue` (new), `OrphanSaveNotice.vue` (new), `Workspace.vue`, `settings/General.vue` copy. |
| T10 — Quality gates | DONE | All commands green; all greps clean; routes confirmed. Manual D1–D22 left for the user (desktop-only). |
| T11 (Revision 2) — Analyst-review fixes | DONE | AR-01, AR-02, AR-03, QA-03. See "Fix Round 2" below. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| created | `database/migrations/2026_09_30_000001_add_file_mtime_to_notes_table.php` | `notes.file_mtime`, nullable unsigned big integer. |
| modified | `app/Models/Note.php` | `file_mtime` fillable/cast/PHPDoc. |
| modified | `app/Services/FileStorageService.php` | `scan()` returns `mtime` per file; new `modifiedTime()`. |
| created | `app/Enums/IndexMode.php` | `Quick`/`Full`. |
| created | `app/Support/ReconcilePlan.php` | Immutable plan; rows referenced by id only (never a hydrated `Note`) so it stays inside the `App\Models\Note` usage boundary. |
| modified | `app/Support/IndexResult.php` | `touched`, `changes`, `stale`, `orphanTempFiles`, `treeSignature`; `summary()` handles `stale`. |
| modified | `app/Services/VaultIndexService.php` | `reconcile()`/`plan()`/`apply()`/`treeSignature()`; `reindex()` = `reconcile(Full)`; basename pairing step (G3); `browse()` returns `signature`. |
| created | `app/Services/ExternalChangeService.php` | `check()` orchestrating a Quick reconcile for the check endpoint. |
| modified | `app/Services/NoteService.php` | Extracted `registerNewFile()`; new `createCopy()`. |
| modified | `app/Exceptions/NoteOperationException.php` | `copyNameUnavailable()`. |
| created | `app/Http/Requests/Vaults/CheckVaultChangesRequest.php` | `open_note` nullable uuid. |
| created | `app/Http/Requests/Notes/InteractsWithNoteContent.php` | Shared `contentText()`/`frontmatterEdit()` trait. |
| modified | `app/Http/Requests/Notes/SaveNoteContentRequest.php` | Uses the shared trait. |
| created | `app/Http/Requests/Notes/StoreNoteCopyRequest.php` | Copy request validation. |
| created | `app/Http/Controllers/VaultChangeController.php` | Invokable check endpoint. |
| created | `app/Http/Controllers/NoteCopyController.php` | Invokable copy endpoint. |
| created | `app/Http/Controllers/NoteDiskController.php` | Invokable disk-text endpoint. |
| modified | `app/Http/Controllers/VaultController.php` | `store`/`open` use `reconcile(Quick)`; `stale` handling. |
| modified | `app/Http/Controllers/ExistingVaultController.php` | `store` uses `reconcile(Quick)`. |
| modified | `app/Http/Controllers/VaultIndexController.php` | Flags `stale` as an error toast. |
| modified | `app/Http/Controllers/WorkspaceController.php` | `treeSignature` and `checkExternalChanges` props. |
| modified | `routes/notes.php` | Three new routes: `vaults.changes.check`, `vaults.notes.copy`, `notes.disk.show`. |
| modified | `tests/Feature/DatabaseSchemaTest.php` | `file_mtime` column. |
| modified | `tests/Feature/Services/FileStorageServiceTest.php` | `scan` mtime, `modifiedTime` tests. |
| created | `tests/Feature/Services/VaultReconcileTest.php` | 22 tests covering FR-01–FR-07, FR-17, FR-19. |
| modified | `tests/Feature/Vaults/VaultManagementTest.php` | Quick-vs-Full open/reindex regression test. |
| created | `tests/Feature/Services/ExternalChangeServiceTest.php` | 12 tests: every status, `open_note` states, cross-vault uuid, own-save. |
| created | `tests/Feature/Vaults/VaultChangeCheckTest.php` | HTTP contract, validation, 404/405, disabled. |
| modified | `tests/Feature/WorkspaceTest.php` | `treeSignature`/`checkExternalChanges` prop tests. |
| created | `tests/Feature/Notes/NoteCopyTest.php` | 11 tests: naming, recreate-on-delete, root fallback, CRLF/BOM, verbatim, validation, exhaustion, DB-failure compensation. |
| created | `tests/Feature/Notes/NoteDiskTest.php` | Disk text, missing, 404. |
| modified | `tests/Unit/ArchitectureTest.php` | Added `ExternalChangeService` to the no-raw-filesystem arch rule. |
| modified | `resources/js/types/notes.ts` | `RemoteOpenNote`, `VaultChangeCheckResponse`, `NoteCopyResponse`, `NoteDiskResponse`. |
| created | `resources/js/lib/external/changeChecker.ts` | Scheduler (single-flight, debounce, adaptive delay, back-off). |
| created | `resources/js/lib/external/openNoteStatus.ts` | `classifyOpenNote`/`decideOpenNoteAction` (pure). |
| created | `resources/js/lib/editor/visitSafety.ts` | `isEditorSafeVisit`. |
| modified | `resources/js/lib/editor/noteSaver.ts` | `externalConflict()`, `resume()`. |
| created | `resources/js/composables/useExternalChanges.ts` | Wires DOM/router events, the HTTP check, and reloads. |
| modified | `resources/js/composables/useUnsavedChangesGuard.ts` | `isEditorSafeVisit` exemption at the top of `handleBefore`. |
| modified | `resources/js/components/editor/NoteEditor.vue` | Save epoch, `externalCheckToken`/`applyExternalStatus`/`defineExpose`, copy/compare/discard/check-again actions, `request-check` emit. |
| modified | `resources/js/components/editor/NoteConflictAlert.vue` | New buttons/emits (`saveAsNew`, `compare`, `check`, `discard`); `reindex` removed. |
| created | `resources/js/components/editor/NoteCompareDialog.vue` | Line-diff dialog (`diff` package). |
| created | `resources/js/components/notes/OrphanSaveNotice.vue` | Dismissible orphan notice. |
| modified | `resources/js/pages/Workspace.vue` | New props, `editorRef`, `useExternalChanges`, `OrphanSaveNotice`. |
| modified | `resources/js/pages/settings/General.vue` | Updated setting description copy. |
| created | `tests/js/external/changeChecker.test.ts` | 10 tests: debounce, single-flight+follow-up, interval, adaptive delay, back-off/reset, skipped, stop, inactive, late-result. |
| created | `tests/js/external/openNoteStatus.test.ts` | Classify branches + full decision-table dataset. |
| created | `tests/js/editor/visitSafety.test.ts` | Guard exemption cases. |
| modified | `tests/js/editor/noteSaver.test.ts` | 4 new tests: `externalConflict`/`resume`. |
| modified | `package.json` / `package-lock.json` | `diff@^9.0.0` (G5). |
| generated | `resources/js/actions/**`, `resources/js/routes/**` | Wayfinder regeneration for the 3 new routes. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | Passed (auto-fixed a handful of style issues along the way; final run clean). |
| `php artisan test --compact` (full suite) | `599 tests, 589 passed, 1681 assertions, 10 skipped` (skips are pre-existing `skipOnWindows()` cases: symlink/chmod tests). |
| `vendor/bin/phpstan analyse` | `0 errors`, no baseline changes. |
| `npm run test:js` | `10 files, 132 tests, all passed`. |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean. |
| `npm run build` | Succeeded (pre-existing >500kB chunk warning on `Workspace.js`, unrelated to this phase). |
| `npm run check` | `All 93 files correctly formatted`, `0 warnings/errors in 86 files`. |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated; no unexpected diffs. |
| `php artisan route:list --path=changes\|disk\|copy` | All three new routes present with the expected names/controllers. |
| Greps (T10 list) | All clean: no `v-html`; `Schema::create` only in pre-existing table migrations; no `content` in the `file_mtime` migration; `replaceFile(` only defined in `FileStorageService` and called from `NoteService::save`; no `ChildProcess`/`chokidar`/`fs.watch`; no `@/` imports under `resources/js/lib/**`; no AI attribution in changed files (the two `.ai/` hits are the plan text itself quoting this grep command). |

No failures to paste.

---

## 4. Deviations from Plan
1. **`ReconcilePlan` references note ids, not hydrated `Note` models.** The plan's pseudocode stored `'note' => $note` in `updates`/`moves`/`touches`. Doing so put a `Note` reference inside `App\Support`, which the existing arch test (`note records are only used by services, controllers, models and factories`) correctly flagged. Fixed by storing `id` only in the plan and having `apply()` fetch fresh `Note` models itself (in the same query it already uses to build the fingerprint), inside the transaction. Functionally identical; slightly safer, since every write now goes through a model fetched fresh inside the transaction rather than one captured at plan time.
2. **`NoteService::createCopy` excludes the source note from the candidate conflict check.** The plan's `assertNoConflict($vault, $relative, $abs, 'content', null, null)` (with `except = null`) fails FR-15's "deleted note → recreated at the original path" case: the source's own still-present registry row (its file gone, but the row not yet reconciled away) matches candidate 0's path in `assertNoConflict`'s registry loop and is reported as a conflict, even though the file itself is gone. Fixed by looking up the source's own `Note` (if any) before the candidate loop and passing it as `$except`, exactly as `relocate()` already does for the same reason. The file-existence check (which never consults `$except`) is untouched, so a **still-present** externally-edited file at candidate 0 is still correctly rejected in favour of `(my version)`. Covered by `NoteCopyTest`'s "deleted source" test, which also confirms the DB row must already be reconciled away first (the real flow: the check that discovers the deletion always reconciles before the "missing" banner offers this action).
3. **No changes were needed to existing `VaultIndexServiceTest.php` expectations.** The plan anticipated a "move + edit, same file name" test might already exist there and need its expectation flipped from a new UUID to the same UUID. No such test exists in the current suite (the only "rename + edit" test there uses a **different** filename, which correctly still gives a new UUID under the new basename step too). The full existing suite passed unmodified.

None of these are architectural changes; all stay strictly within the ADRs and requirements' stated intent (FR-04, FR-15, Rule 4).

---

## 5. Notes for QA
- **Please re-verify deviation 2 in particular**: it is a real, minimal behavioural fix required to satisfy FR-15's own acceptance criterion, not a design change — but it does add one extra query (`$vault->notes()->where('relative_path', $sourcePath)->first()`) to `createCopy()`, worth a second look for correctness and for whether it should also exclude the source note in the *file-exists* check (it deliberately does not: a still-present source file at that path must still refuse candidate 0).
- **Wayfinder export names** confirmed by inspecting the generated files directly: `@/routes/vaults/changes` → `check`; `@/routes/vaults/notes` → `copy`; `@/routes/notes/disk` → `show`.
- **No `PendingVisit` field deviations**: `url`/`method`/`only` match the plan's assumed shape exactly (checked against `@inertiajs/core`'s `types.d.ts`).
- **`ExternalChangeService`** deliberately has no filesystem/hashing logic of its own — it only orchestrates `VaultService`, `VaultIndexService` and `SettingsService`. Confirmed by the arch test addition.
- **`useExternalChanges`** relies on `Workspace.vue` remounting on every full Inertia visit to a *different* page component (e.g. `vaults/Index` → `workspace` on open/close-and-redirect) to pick up a changed `vaultUuid`/`enabled` at checker-creation time; within-workspace navigation (opening another note) does not remount `Workspace.vue`, which is intentional — the checker must survive note-to-note navigation. All `options.*` closures read live props on every call, so this holds even without a remount.
- **Manual desktop checks D1–D22** (plan §3, T10) are explicitly user/orchestrator-verified, not part of this record — they need `composer native:dev` and cannot be run from this environment.
- **Migration**: applied only inside the SQLite in-memory test database via `RefreshDatabase`; `native:migrate` against the real desktop DB was not run by this agent (per the plan's rule), and should be run once the feature reaches the user's desktop app.
- The pre-existing >500 kB `Workspace.js` build-chunk warning is unrelated to this phase (it exists on `main` already, due to Tiptap/marked bundle size) and was not made worse in any material way by Phase 5's additions.

---

## 6. Fix Rounds

### Fix Round 1 (QA round 1: QA-01, QA-02)

**QA-01 (Critical, MODERATE) — `NoteService::createCopy()` 500 on a stale, unreconciled registry row**

- **Root cause**: the Revision-1 code already excluded the source's own row (`$sourceNote`) from `assertNoConflict()`'s case-insensitive duplicate check, specifically so that recreating a deleted note at its own original path would not be wrongly rejected as "the name is taken". That made `assertNoConflict()` pass for candidate 0 whenever the source's stale row still pointed at that exact path — but nothing then removed the stale row before `registerNewFile()` inserted the new one. `Note::create()` hit the `unique(vault_id, relative_path)` constraint and threw `Illuminate\Database\UniqueConstraintViolationException`, uncaught, all the way to a 500. This reproduced exactly as QA described: unlink the file, do **not** reconcile (so the stale row survives — as it does after `NoteService::save()`'s 409 `missing` path, which never touches the registry, or whenever `app.check_external_changes` is off), then call `createCopy()`/`POST vaults.notes.copy`.
- **Fix** (`app/Services/NoteService.php`):
  - `registerNewFile()` gained a `bool $clearStaleRow = false` parameter and now runs inside an explicit `$this->database->connection()->transaction()`. When `$clearStaleRow` is true, it first deletes (by exact `vault_id` + `relative_path`, the same columns the unique index covers) any registry row already sitting at the path it's about to insert — **before** inserting. `create()`'s call site is unchanged (`$clearStaleRow` defaults to `false`, so its behaviour is byte-for-byte identical to before).
  - `createCopy()` now calls `registerNewFile(..., clearStaleRow: true)` for every candidate.
  - **Decision** (as required by QA-01): the stale row is **removed**, not re-pointed. Re-pointing would keep the deleted note's old UUID, contradicting FR-15's own acceptance criterion ("a deleted note is recreated at its original path **with a new UUID**"). Removing it is also correct regardless of whose row it is (see below), and safe under Rule 9/10 (the filesystem is the truth): by the time `registerNewFile()` runs, `assertNoConflict()`'s own `exists()` check has already refused any candidate a real file currently sits at, and `createFile()` only succeeds through an exclusive (`fopen(..., 'x')`) create — so a registry row still pointing at that exact path can only be a stale leftover, never a live conflict.
  - The delete and the insert are in the same transaction, so a later DB failure (e.g. the existing `Note::creating` throwing test) rolls both back together: the stale row is left exactly as it was, and the outer `catch` still removes the newly-created file as compensation. Nothing is ever orphaned either way.
  - This only changes `createCopy()`'s behaviour; `create()` is provably unaffected (new parameter defaults to `false`, no other lines touched).
  - **On "a different note (not the source) holds the stale path"**: this is possible, but not through the crash. `assertNoConflict()`'s duplicate check scans *every* note except `$sourceNote`, comparing case-insensitively — which is strictly broader than the DB's case-sensitive exact-match unique index, so it always catches a different note's row sitting at a candidate path first and makes the loop `continue` to the next candidate (existing, correct, non-crashing behaviour, unchanged by this fix). The new regression test below exercises exactly this and asserts no crash and a graceful fallback name; the stale row from the *other* note is left for a later reconcile to clean up (out of scope for a copy request).
- **Tests added** (`tests/Feature/Notes/NoteCopyTest.php`):
  1. Service-level: unlink `X.md`, do **not** reconcile, call `NoteService::createCopy()` directly → succeeds, `relative_path` is `X.md`, a fresh UUID, exactly one `Note` row.
  2. HTTP-level: the same scenario through `POST vaults.notes.copy` → 201, same assertions.
  3. A *different* note's stale, unreconciled row occupying the exact `(my version)` candidate path (an earlier copy of `X.md`, later deleted externally, never reconciled) → the new copy is **not** crashed on; it lands at `(my version 2)`, and the unrelated stale row is left untouched.
- **Verification**: `php artisan test --compact tests/Feature/Notes/NoteCopyTest.php` → 17 tests, 17 passed, 61 assertions. Full suite re-run below.

**QA-02 (Medium, MINOR) — `NoteEditor.vue`'s `saveAsNewNote()` silently swallowed a 500 or network failure**

- **Root cause**: `saveAsNewNote()`'s `useHttp` call only wired `onSuccess`/`onError` (422), unlike every other `useHttp` call site added this phase (`send()` in the same file, `NoteCompareDialog.vue`, `useExternalChanges.ts`), which all also handle `onHttpException`/`onNetworkError`. A 500 or a dropped connection therefore did nothing visible; the user's text was never lost (the editor only calls `discard()` from `onSuccess`), but there was no toast telling them the save-as-copy failed.
- **Fix**:
  - New framework-free module `resources/js/lib/editor/copyTransport.ts` (mirrors the existing `saveTransport.ts`/`mapSaveResult` pattern for the save endpoint): `mapCopyResult(RawCopyResult): CopyOutcome` maps `success` → `saved`, `validation` (422) → `error` with the first field message, and `httpException`/`network` → a generic error message that explicitly reassures the user their text is still in the editor (`"MDVault couldn't save your version as a new note. Your text is still in the editor."` / `"MDVault couldn't reach its local server. Your text is still in the editor."`).
  - `NoteEditor.vue`'s `saveAsNewNote()` now wires `onHttpException` and `onNetworkError`, both routed through `mapCopyResult` and a `toast.error(...)`, exactly like the existing `onError` path. `onSuccess` and `onError` keep their prior behaviour (verified byte-identical toast/navigation for success; the 422 message is now produced by the same shared module instead of inline `Object.values` code, with no behavioural change — same fallback text).
  - The text is never discarded on failure (unchanged: `discard()` is only called from `onSuccess`), satisfying "the user's text must stay in the editor".
- **Test added**: `tests/js/editor/copyTransport.test.ts` — 6 cases: success; a 422 message; a 422 array-valued field error (first message); an empty error bag (generic fallback); any HTTP exception (generic, "still in the editor"); a network error (generic, "reach its local server").
- **Verification**: `npm run test:js` → 11 files, 138 tests, all passed (132 prior + 6 new). `npm run types:check` → clean after narrowing `onError`/`onHttpException`/`onNetworkError` to check `outcome.kind === 'error'` before reading `.message` (a plain discriminated-union narrowing fix; `CopyOutcome`'s two branches don't share fields). `npm run check` → all files formatted, no lint warnings.

**Full-suite verification after both fixes**

| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | Passed. |
| `php artisan test --compact tests/Feature/Notes tests/Feature/Vaults tests/Feature/Services` | `492 tests, 482 passed, 1339 assertions, 10 skipped` (pre-existing `skipOnWindows()` symlink/chmod skips). |
| `php artisan test --compact` (full suite) | `602 tests, 592 passed, 1697 assertions, 10 skipped` (602 = the prior 599 + 3 new `NoteCopyTest` cases). |
| `npm run test:js` | `11 files, 138 tests, all passed`. |
| `npm run types:check` | Clean. |
| `npm run check` | All files formatted; no lint warnings. |
| `vendor/bin/phpstan analyse` | Pending — the sandbox's command classifier returned no verdict on repeated attempts during this round; not a code failure. To be re-run and recorded before hand-off if the orchestrator can retry it. |

No failures to paste beyond the transient PHPStan tooling issue above.

**Files changed in this round**:
| Action | Path |
|---|---|
| modified | `app/Services/NoteService.php` |
| modified | `tests/Feature/Notes/NoteCopyTest.php` |
| created | `resources/js/lib/editor/copyTransport.ts` |
| modified | `resources/js/components/editor/NoteEditor.vue` |
| created | `tests/js/editor/copyTransport.test.ts` |

### Fix Round 2 (Level 4 analyst review: AR-01, AR-02, AR-03, QA-03 — plan Revision 2, T11)

**AR-01 (High, MODERATE) — tree refresh on a deleted open note hit a 404 and looped**

- **Fix, server side** (`routes/notes.php`): added a `->missing(function (Request $request) {...})` handler to the `notes.show` route (name and `whereUuid` unchanged). When the request carries a non-empty `X-Inertia-Partial-Data` header (a partial reload — the case that matters is the composable's tree-only `router.reload({ only: ['tree', 'folders', 'treeSignature'] })`), it renders the Workspace with `note = null` via `app()->call([app(WorkspaceController::class), '__invoke'], ['note' => null])`, so `tree`/`folders`/`treeSignature` still resolve normally and a `note`-only partial reload gets `note: null`. Any other request (a full GET, no partial header) still `abort(404)`s, byte-for-byte the previous behaviour — the existing `NoteManagementTest.php` 404 test (`an unknown uuid and an integer id both 404`) needed no change and still passes.
- **Fix, client side** (`resources/js/composables/useExternalChanges.ts`): confirmed the field name is `async` on `PendingVisit`/`ActiveVisit` in `@inertiajs/core`'s `types.d.ts` (`router.reload()` always sets `async: true` — verified directly in `node_modules/@inertiajs/core/dist/index.js`'s `doReload()`). The `router.on('start', …)` and `router.on('finish', …)` handlers now type their event as `CustomEvent<{ visit: PendingVisit }>` and return early when `event.detail.visit.async` is true, so a background tree/note reload never sets `navigating` or pauses/resumes the checker; only a real navigation does.
- **Root-cause note**: while implementing this, found `bootstrap/cache/routes-v7.php` was a **stale committed route cache** left over from before this session — it was masking the new `->missing()` handler entirely (Laravel was dispatching against the cached route table, which predates this branch's `routes/notes.php`). Cleared it with `php artisan route:clear`. This is not a code change and nothing was added to the route cache; flagging it in case CI or another clone has the same stale file.
- **Tests** (`tests/Feature/WorkspaceTest.php`, 3 new):
  1. A partial GET (`X-Inertia: true`, matching `X-Inertia-Version`, `X-Inertia-Partial-Component: Workspace`, `X-Inertia-Partial-Data: tree,folders,treeSignature`) to `notes.show` for a uuid the external-change check (`ExternalChangeService::check()`, called first to reconcile the deletion exactly as the real `changes` endpoint would) just removed → 200, `component === 'Workspace'`, `props.treeSignature` equals the check's `tree_signature`, and the tree no longer lists the note's uuid.
  2. The same setup with `X-Inertia-Partial-Data: note` → 200, `props.note` is `null`.
  3. The same setup with a full (non-partial) GET → still 404s (this is the plan's "must stay green" case, written as its own explicit test rather than relying only on the pre-existing `NoteManagementTest` one, since this scenario additionally has the row already reconciled away via `ExternalChangeService::check()` first).

**AR-02 (Low, MINOR) — stale check results could act on a replaced editor or unfreeze a guarded navigation**

- **Fix 1** (`resources/js/composables/useExternalChanges.ts`, `run()`): added a `disposed` flag, set in `onUnmounted()` (alongside the existing `checker?.dispose()`). Immediately after `await postCheck(...)`, `run()` now returns `'skipped'` without reloading or applying anything when `disposed || navigating` — a check whose response arrives after unmount or during a real navigation no longer acts on it. Separately, `target.applyExternalStatus(...)` is now only called when `options.editor() === target` still holds after the await, guarding against a result for a since-replaced editor instance.
- **Fix 2** (`resources/js/components/editor/NoteEditor.vue`): added `let unmounted = false`, set `true` in the existing `onUnmounted()` alongside `saver?.dispose()`. `applyExternalStatus()` now returns immediately when `unmounted` is true (checked alongside the existing epoch/`saving` guard), so a result that resolves after this instance unmounted can no longer call `router.visit(workspace.url())` (the `leave` action) or any other side effect.
- **Fix 3** (`resources/js/composables/useUnsavedChangesGuard.ts`): the `finish` handler now receives the event and skips `options.unfreeze()` when `isEditorSafeVisit(event.detail.visit, window.location.href)` is true — an editor-safe (tree-only) background reload never went through `event.preventDefault()`/`freeze()` in `handleBefore`, so its finish must not lift a freeze a genuine, still in-flight guarded navigation put in place.
- **Tests**: none added, per the plan's own instruction ("extend `tests/js/editor/visitSafety.test.ts` only if a pure helper is introduced. Otherwise cover this by code review and manual check D25") — no new pure helper was introduced; all three fixes are inline guards over existing state. Covered by code review and manual check D25.

**AR-03 (Low, MINOR) — in-app hash updates left a stale trusted `file_mtime`**

- **Fix** (`app/Services/NoteService.php`):
  - `preview()`: both places that already conditionally update `file_hash`/`file_size` (the `too_large` branch and the normal branch — both already guarded by `$hash !== $note->file_hash || $size !== $note->file_size`) now also set `'file_mtime' => null'` in the same call. No new condition needed since both call sites were already gated on an actual change.
  - Private `reconcile()` (used by `save()`'s no-op and written branches): added a new guard — `if ($note->file_hash === $hash && $note->file_size === $size) { return; }` — before the `try`/`update()`. When the stored hash/size already match, `reconcile()` now makes **no** database call at all (not even a same-value `UPDATE`); when they differ, the `update()` now also sets `'file_mtime' => null'` alongside `file_hash`/`file_size`, restoring the ADR invariant ("`file_mtime` non-null ⇒ (size, mtime) attests to `file_hash`") on every path that changes the hash outside a reconcile.
  - `present()` was not touched, per the plan.
- **Tests** (`tests/Feature/Services/NoteServiceTest.php`, 3 new):
  1. `a content-changing save nulls a trusted file_mtime` — establishes a trusted (non-racy) `file_mtime` via two `VaultIndexService::reconcile(Full)` passes (write, then `touch()` the mtime back 100 s and reconcile again — the same pattern used in `VaultReconcileTest.php`'s racy-mtime tests), then a real content-changing `save()` leaves `file_mtime` `null`.
  2. `an external edit nulls a trusted file_mtime on preview` — same trusted-mtime setup, then an external edit (direct `File::put`, bypassing the service) followed by `preview()` leaves `file_mtime` `null`.
  3. `a no-op save makes no database write at all` — uses `NoteService::preview()`'s own output (`content`, `base_hash`) as the unchanged input to a `Source`-mode `save()`, so the DB's `file_hash`/`file_size` already match the disk end to end (unlike the pre-existing "leaves the modification time untouched" test, which starts from a DB row that is genuinely stale relative to the disk — that test's "no write" claim only holds at second-level timestamp granularity, not literally; it was left unchanged since it isn't part of this fix). A `DB::listen` counter (same pattern as `VaultReconcileTest.php`'s "two consecutive quick reconciles…" test) asserts zero `insert`/`update`/`delete` queries.

**QA-03 (Low, MINOR) — missing `NoteCopyTest` DB-failure coverage for the stale-row recreate path**

- **Test added** (`tests/Feature/Notes/NoteCopyTest.php`): `a DB failure recreating a deleted source leaves the stale row and its uuid intact` — deletes the source file without reconciling (so its stale registry row survives, exactly as in the existing "service level"/"HTTP level" recreate tests), registers a throwing `Note::creating` listener, and calls `createCopy()`. Asserts the exception is rethrown, the newly-created file is removed (the existing `deleteNewFileWithContents` compensation in `registerNewFile()`), and — the new assertion this fix round adds — the stale row **still exists with its original uuid and relative_path**: `registerNewFile()`'s `clearStaleRow` delete and the failing insert run inside the same transaction, so the delete rolls back along with the insert.
- No production code change was needed for this item; it was purely a coverage gap.

**Deviations from the plan text in this round**: none. All four items were implemented exactly as specified in `analyst-review.md` §5 and plan.md's T11 block.

**Verification (fix round 2)**:
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan wayfinder:generate --with-form --no-interaction` | Regenerated; only line-number `@see` comment shifts in `resources/js/routes/notes/index.ts` (the `notes.show` controller method moved from line 22 to 23 because of the new `use Illuminate\Http\Request;` import) — no route name/URI/method change. |
| `php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/Notes tests/Feature/Services/NoteServiceTest.php tests/Feature/Services/VaultReconcileTest.php tests/Feature/Services/ExternalChangeServiceTest.php` | `198 tests, 195 passed, 644 assertions, 3 skipped` (skips are pre-existing `skipOnWindows()` cases) |
| `php artisan test --compact` (full suite) | `609 tests, 599 passed, 1718 assertions, 10 skipped` (609 = the prior 602 + 7 new: 3 WorkspaceTest + 3 NoteServiceTest + 1 NoteCopyTest) |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run test:js` | `11 files, 138 tests, all passed` (unchanged — no new JS tests needed for AR-01/AR-02 per the plan) |
| `npm run types:check` | Clean |
| `npm run check` | `vp check` flagged one formatting-only issue in `useUnsavedChangesGuard.ts` (a line wrap in the new `finish` handler); fixed with `npx vp check --fix`, then reran: `All 95 files are correctly formatted`, `Found no warnings or lint errors in 88 files` |

No failures to paste.

**Files changed in this round**:
| Action | Path |
|---|---|
| modified | `routes/notes.php` |
| modified | `resources/js/composables/useExternalChanges.ts` |
| modified | `resources/js/components/editor/NoteEditor.vue` |
| modified | `resources/js/composables/useUnsavedChangesGuard.ts` |
| modified | `app/Services/NoteService.php` |
| modified | `tests/Feature/WorkspaceTest.php` |
| modified | `tests/Feature/Services/NoteServiceTest.php` |
| modified | `tests/Feature/Notes/NoteCopyTest.php` |
| generated | `resources/js/actions/**`, `resources/js/routes/**` (comment line numbers only) |
