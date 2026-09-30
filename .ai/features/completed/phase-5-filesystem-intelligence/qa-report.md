# QA Report: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-09-30
- **Verdict**: FAIL

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage

| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 create | yes | `VaultReconcileTest` | ✅ |
| FR-02 create/UUIDv7 | yes | `VaultReconcileTest` | ✅ |
| FR-03 delete (+ unreadable dir keeps record) | yes | `VaultReconcileTest` | ✅ |
| FR-04 rename/move identity (4-step pairing) | yes | `VaultReconcileTest` (rename, move, move+edit, rename+edit, ambiguous, case-only) | ✅ |
| FR-05 folder changes, UUIDs survive folder rename | yes | `VaultReconcileTest` (basename step with duplicate hashes) | ✅ |
| FR-06 quick scan/verify/racy mtime | yes | `VaultReconcileTest`, `FileStorageServiceTest`, `VaultManagementTest` | ✅ |
| FR-07 stale guard, unreadable, vault-missing | yes | `VaultReconcileTest` | ✅ |
| FR-08 check endpoint contract | yes | `VaultChangeCheckTest`, `ExternalChangeServiceTest` | ✅ |
| FR-09 client scheduling | yes | `changeChecker.test.ts` | ✅ |
| FR-10 tree-reload guard exemption | yes | `visitSafety.test.ts` + `useUnsavedChangesGuard.ts` wiring | ✅ |
| FR-11 clean+changed auto-reload | yes | `openNoteStatus.test.ts` (decision table) + `NoteEditor.vue` | ✅ (no dedicated component-level test, decision table only — acceptable, matches plan) |
| FR-12 dirty+changed banner | yes | `noteSaver.test.ts`, `openNoteStatus.test.ts` | ✅ |
| FR-13 open note moved | yes | `openNoteStatus.test.ts` (`moved`→`refresh`/`resume`) | ✅ |
| FR-14 open note deleted | yes | `openNoteStatus.test.ts` | ✅ |
| **FR-15 "Save mine as a new note"** | **partially** | `NoteCopyTest` | ❌ **See QA-01** — the specific "deleted note, stale registry row not yet reconciled" path is unhandled and untested |
| FR-16 Compare | yes | manual (no Vitest for `NoteCompareDialog.vue`, acceptable per plan; `diffLines` behaviour is a thin wrapper) | ✅ |
| FR-17 own writes not external | yes | `ExternalChangeServiceTest` ("an in-app save is never reported…") | ✅ |
| FR-18 setting gates only automatic checks | yes | `VaultChangeCheckTest`, `ExternalChangeServiceTest` | ✅ |
| FR-19 orphan temp files | yes | `VaultReconcileTest` | ✅ |
| FR-20 vault availability | yes | `ExternalChangeServiceTest` (`unavailable`) | ✅ |
| FR-21 boundaries (services only, no new tables) | yes | `ArchitectureTest`, T10 greps | ✅ |

---

## 2. Test Execution

| Command | Result |
|---|---|
| `php artisan test --compact` (targeted scope per plan §4) | `324 tests, 316 passed, 998 assertions, 8 skipped` |
| `php artisan test --compact` (full suite) | `599 tests, 589 passed, 1681 assertions, 10 skipped` (matches implementation.md; skips are pre-existing `skipOnWindows()` symlink/chmod tests) |
| `npm run test:js` | `10 files, 132 tests, all passed` |
| `vendor/bin/phpstan analyse` (`php vendor/bin/phpstan analyse`) | `0 errors` |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean, no output |
| T10 greps (`v-html`, `Schema::create`, `content` in the new migration, `replaceFile(`, watcher code, relative-import boundary, AI attribution) | All clean, as reported in implementation.md |
| `php artisan route:list --path=changes\|disk\|copy` | All three routes present with expected names/controllers |

No test failures. **The full test suite passing does not contradict QA-01**: the one existing test that exercises the "deleted source" path (`NoteCopyTest`: "a deleted source is recreated at the original path with a new UUID") deliberately reconciles the vault *before* calling `createCopy()`, which removes the stale registry row and sidesteps the bug entirely (its own inline comment says so). I reproduced the failure directly against the service with a standalone script that mirrors the untested, and more realistic, sequence (see QA-01).

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | **Critical** | MODERATE | `app/Services/NoteService.php:153-251` (`createCopy`), `app/Http/Controllers/NoteCopyController.php` | **"Save mine as a new note" crashes with a 500 when recreating a deleted note at its original path while the registry still has a stale row for that path.** Deviation #2 (`implementation.md` §4) passes the source's own `Note` as `$except` to `assertNoConflict()`, which correctly lets `createCopy()` proceed past the pre-check for candidate 0 (the original name). But the stale row is never deleted or updated before `registerNewFile()` inserts a **new** row at the same `relative_path`. `notes` has a `unique(vault_id, relative_path)` index (`2026_09_27_161820_create_notes_table.php`), so the insert throws `Illuminate\Database\UniqueConstraintViolationException`. `NoteCopyController` only catches `NoteOperationException`, so this exception is uncaught and becomes a 500. I reproduced this directly against the service (unlink the file, do **not** reconcile first — exactly what happens when a `missing` 409 from `NoteService::save()` opens the banner, since `save()` never touches the registry row, and even more so whenever `app.check_external_changes` is off, FR-18): <br>`FAILURE: Illuminate\Database\UniqueConstraintViolationException: SQLSTATE[23000]: … UNIQUE constraint failed: notes.vault_id, notes.relative_path`. <br>The only test that exercises "deleted source → recreate at original path" (`NoteCopyTest.php`) reconciles first, which removes the stale row and therefore never reaches this code path — it does not actually verify deviation #2. | `createCopy()` must succeed in this exact scenario (this is FR-15's own headline acceptance criterion: "Given a deleted note, Then the file is recreated at its original path with a new UUID"), regardless of whether the registry has been reconciled yet. The fix should delete (or update) the stale `$sourceNote` row as part of the same operation before/while inserting the new one (ideally in one transaction, mirroring how `relocate()` handles its own row), and add a regression test that does **not** reconcile before calling `createCopy`/`POST vaults.notes.copy`. | 0 |
| QA-02 | Medium | MINOR | `resources/js/components/editor/NoteEditor.vue:500-519` (`saveAsNewNote`) | The `useHttp(...).post(copy.url(...))` call in `saveAsNewNote()` provides only `onSuccess`/`onError` (422 validation) handlers — unlike every other `useHttp` call added in this phase (`send()`, `NoteCompareDialog`, `useExternalChanges`), it has no `onHttpException`/`onNetworkError` handler. A 500 (e.g. QA-01) or a network failure during "Save as a new note" therefore shows no toast and leaves the user with no feedback or retry path, compounding QA-01's impact and diverging from the pattern used everywhere else in this phase. | Add `onHttpException`/`onNetworkError` handlers consistent with the rest of the phase's `useHttp` calls, showing an error toast and keeping the banner/edit intact. | 0 |

---

## 4. Code Review Notes
- **Security & Authorization**: all three new routes (`vaults.changes.check`, `vaults.notes.copy`, `notes.disk.show`) are declared in `routes/notes.php`, which is `require`d from `routes/web.php` and therefore inherits the `web` middleware group (session + CSRF), matching the plan. `source_path` in `createCopy()` is checked for backslashes, NUL, a leading `/`, a drive letter, and `.`/`..`/empty segments, and must end in `.md` — no path traversal found; `StoreNoteCopyRequest`'s `source_path` validation test list (`../x.md`, `C:/x.md`, `a\\b.md`, `x.txt`) confirms this at the HTTP layer. `open_note` and copy-source lookups are correctly scoped to `$vault->notes()` (`ExternalChangeService::openNoteState`/`check`, confirmed by the "uuid from another vault" test), so a cross-vault UUID never leaks state. JSON responses expose no `id`/`vault_id` (tested). No authentication is expected per the existing local-app ADR.
- **Validation & Data Integrity**: the reconcile engine (`plan()`/`apply()`) is read-only on disk and applies all DB writes in one transaction behind a fresh-fingerprint stale guard, exactly as ADR `external-change-reconciliation` describes; `touch` correctly runs `withoutTimestamps` and is excluded from `hasChanges()`. The 4-step pairing (exact → case-insensitive → unique hash → unique basename) is implemented with strict 1:1 pairing in `pairByKey()`, matching G3. **The one real data-integrity gap found is QA-01**: `createCopy()`'s conflict pre-check and the actual insert can disagree, because the pre-check's `$except` exclusion isn't mirrored by any corresponding row deletion/update before the write. Everywhere else, writes are compensated correctly (e.g. `registerNewFile`'s DB-failure compensation, `replaceFile`'s guard).
- **Performance (N+1, indexes)**: `plan()`/`apply()` are O(files) for the stat walk and O(changed files) for hashing, as designed; no obvious N+1 (bulk `get()`/`pluck()` calls). No new index was requested for `file_mtime` and none is needed (it's read by exact-path lookup only, inside an already-vault-scoped query).
- **Conventions (AGENTS.md, `.ai/rules/`)**: no filesystem/hashing logic found in `ExternalChangeService` (confirmed by the arch-test addition and by inspection — it only calls `VaultService`/`VaultIndexService`/`SettingsService`); `VaultIndexService`/`FileStorageService` remain the only filesystem boundary. No new tables, no `content` column, relative paths only, stable UUIDs preserved across renames/moves per the pairing rules. No AI attribution found in any changed file.
- **Frontend (Inertia/Vue/Wayfinder)**: all new/changed Vue components are single-root (`NoteConflictAlert`'s `<div class="contents">`, `NoteCompareDialog`'s `<Dialog>`); no `v-html` anywhere; Wayfinder-generated `check`/`copy`/`show` helpers are used instead of hardcoded URLs; the `isEditorSafeVisit` guard exemption is correctly scoped to same-pathname GETs whose `only` excludes `note`. `changeChecker.ts`/`openNoteStatus.ts`/`visitSafety.ts` are framework-free with no `@/` imports, matching the plan's boundary rule. Aside from QA-02, error-handling coverage on the new `useHttp` calls is otherwise consistent and thorough (`NoteCompareDialog`, `useExternalChanges`).

---

## 5. Routing Recommendation
- [ ] **PASS** → move feature to `.ai/features/completed/`
- [x] **FAIL — MINOR/MODERATE** → Senior Developer: **QA-01** (fix `createCopy()` to handle/clear the stale source row before or during the insert when recreating at the original path, and add a regression test that does *not* reconcile first), **QA-02** (add missing error handlers to `saveAsNewNote()`'s `useHttp` call).
- [ ] **FAIL — MAJOR** → System Analyst: none. Both issues are implementation-level fixes within the existing, approved design (ADRs and plan.md are not in question); no replanning is needed.
