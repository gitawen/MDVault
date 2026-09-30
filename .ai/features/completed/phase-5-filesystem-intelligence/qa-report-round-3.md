# QA Report: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Author**: Senior QA Engineer
- **QA Round**: 3 (Level 4 sign-off verification)
- **Date**: 2026-09-30
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 0. Pre-test hygiene
Ran `php artisan route:clear` and `php artisan config:clear` before any test execution, per instructions (the stale local `bootstrap/cache/routes-v7.php` that previously masked the new `missing()` handler was already cleared by the developer during Fix Round 2, per their note in `implementation.md`; re-clearing confirmed a clean state regardless). *(Orchestrator note: `bootstrap/cache/*` is git-ignored; the cache was local, not committed.)*

---

## 1. Re-verification of AR-01, AR-02, AR-03, QA-03 (all fix attempt 1)

### AR-01 (High, MODERATE) — tree refresh on a deleted open note 404s and loops — RESOLVED

**Server side** (`routes/notes.php`): reviewed the diff directly.
```php
Route::get('notes/{note:uuid}', WorkspaceController::class)
    ->whereUuid('note')
    ->name('notes.show')
    ->missing(function (Request $request) {
        if ($request->header('X-Inertia-Partial-Data')) {
            return app()->call([app(WorkspaceController::class), '__invoke'], ['note' => null]);
        }
        abort(404);
    });
```
- Traced how Laravel actually invokes this: `ImplicitRouteBinding::resolveForRoute` throws `ModelNotFoundException` on a missing `{note:uuid}`; `Illuminate\Routing\Middleware\SubstituteBindings` catches it and calls `$route->getMissing()($request, $exception)` — a **positional** call, so the closure's single-parameter signature `function (Request $request)` is fine (PHP silently drops the unused second positional argument; no error).
- Confirmed `HandleInertiaRequests` runs in the `web` middleware group (`bootstrap/app.php`), which executes before `SubstituteBindings` (a route-level middleware) — so Inertia's version/shared-prop setup is already in place when the `missing()` closure calls `Inertia::render` inside `WorkspaceController::__invoke`. No missing-bootstrap risk.
- `app()->call([$instance, '__invoke'], ['note' => null])`: verified this is a sound use of Laravel's `Container::call()` — parameters matched by name in the override array are used directly without going through the container/route-model-binding resolution, so `?Note $note = null` in `WorkspaceController::__invoke` is set to `null` directly; the other four service dependencies are still resolved by type-hint as normal. `WorkspaceController::__invoke` itself already guards `$note !== null && (...)` before touching `$note->vault`, so `note = null` is handled safely and nothing about the requested uuid leaks — the closure returns only the current vault's own tree/folders/note-null, identical to what any request to `notes.show` on the current vault would already see (single-user local app per the `local-app-without-authentication` ADR). No new information disclosure for arbitrary uuids: a full visit to an unknown uuid still 404s unchanged.
- **Client side** (`useExternalChanges.ts`): confirmed `async` is the correct field on Inertia's `PendingVisit`/`Visit` types (`node_modules/@inertiajs/core/types/types.d.ts:214`, `Visit.async: boolean`) and that `router.reload()` (`doReload()` in `@inertiajs/core/dist/index.js`) always sets `async: true` on every reload, including `NoteEditor.refresh()`'s tree/note-partial reload. The `router.on('start')`/`router.on('finish')` handlers in `useExternalChanges.ts` return early on `event.detail.visit.async === true`, so a background reload — successful or failed — never sets `navigating = true` and never toggles `checker.setActive(...)`. A failed background reload can therefore no longer suppress/reactivate the checker at all; the normal 5 s interval (or back-off) continues untouched. This closes the tight loop AR-01 described.
- **Tests** (`tests/Feature/WorkspaceTest.php`, read in full): three new tests exercise exactly the required scenarios — a partial `tree,folders,treeSignature` reload on a uuid the check just reconciled away (200, `treeSignature` matches `ExternalChangeService::check()`, tree no longer lists the uuid); a partial `note` reload on the same setup (200, `note` is `null`); a full GET on the same setup (still 404). All three send the correct `X-Inertia-Version` computed the same way `inertiajs/inertia-laravel`'s `Middleware::version()` does (`hash_file('xxh128', public_path('build/manifest.json'))` — verified byte-for-byte against `vendor/inertiajs/inertia-laravel/src/Middleware.php:53-54`). The pre-existing `NoteManagementTest.php:174` ("an unknown uuid and an integer id both 404") is untouched and still passes.
- **Verdict: resolved.** No 404/loop reachable for a partial tree reload on a deleted open note; full visits are unchanged.

### AR-02 (Low, MINOR) — stale check results could act on a replaced editor or unfreeze a guarded navigation — RESOLVED

- **Post-await guards in `useExternalChanges.run()`**: a `disposed` flag is set in `onUnmounted()` (alongside the existing `checker?.dispose()`); immediately after `await postCheck(...)`, `run()` returns `'skipped'` when `disposed || navigating`, before any reload or `applyExternalStatus` call. Separately, `target.applyExternalStatus(...)` is now gated on `options.editor() === target` still holding after the await — a result whose target editor has since been replaced (different note opened) is discarded.
- **`NoteEditor.applyExternalStatus` no-op after unmount**: `let unmounted = false` is set `true` in the existing `onUnmounted()`; `applyExternalStatus` returns immediately when `unmounted` is true, checked alongside the pre-existing epoch/`saving` guard — so a result resolving post-unmount can no longer call `router.visit(workspace.url())` (the `leave` action) or any other side effect on a defunct instance.
- **Guard's `finish` exemption cannot leave the editor frozen forever**: `useUnsavedChangesGuard`'s `finish` handler now skips `options.unfreeze()` when `isEditorSafeVisit(event.detail.visit, currentUrl)` is true. I traced the symmetry with `handleBefore`, which uses the identical `isEditorSafeVisit` check to decide whether to `event.preventDefault()`/eventually `freeze()` in the first place: a visit is only ever frozen-for if it is **not** editor-safe, and a `finish` only skips unfreezing when it **is** editor-safe. Because the freeze-then-`replay()` sequence for a real guarded navigation happens synchronously in one `.then()` callback (freeze immediately followed by issuing the real, non-editor-safe visit), there's no ordering in which the genuine navigation's own `finish` event could itself be misclassified as editor-safe (different pathname/method/`only` in every real-navigation case reviewed: `router.visit()` to a different URL, `replay()` of the original prevented visit, or `discardAndContinue`'s replay). No permanent-freeze path was found under code review.
- **Tests**: none added for AR-02, matching the plan's own instruction ("extend `visitSafety.test.ts` only if a pure helper is introduced... otherwise cover this by code review and manual check D25") — no new pure helper was introduced; all three fixes are inline state guards. This is an acceptable, explicitly plan-sanctioned test gap; manual check D25 remains the closing verification (desktop-only, cannot be run here).
- **Verdict: resolved** by code review, as the plan anticipated.

### AR-03 (Low, MINOR) — in-app hash updates left a stale trusted `file_mtime` — RESOLVED

- `preview()`: both existing `$note->update([...])` calls (the `too_large` branch and the normal branch) were already gated on an actual hash/size change; both now also set `'file_mtime' => null` in the same call. No new condition needed, matching the analyst's guidance.
- Private `reconcile()` (used by `save()`): a new early-return guard, `if ($note->file_hash === $hash && $note->file_size === $size) { return; }`, precedes the `try`/`update()` — so a genuine no-op save now makes zero database calls (not even a same-value `UPDATE`). When hash/size do differ, `update()` now also sets `file_mtime => null`, restoring the ADR invariant ("`file_mtime` non-null ⇒ (size, mtime) attests to `file_hash`").
- **The developer's reasoning about the pre-existing "leaves the modification time untouched" test** (`NoteServiceTest.php:657`, unchanged this round): that test only asserts `updated_at` is untouched, not `file_mtime` directly, and its DB row already matches the disk before the save is attempted (both content and hash/size are already in sync from `create()`), so it was already exercising the guard's `return` path implicitly; the new `reconcile()` guard doesn't change its outcome. I confirmed this by reading the test: `save()` is called with content identical to what's on disk, so `written` is `false` and `reconcile()`'s new early return fires — `updated_at` provably stays untouched. The developer's added test `a no-op save makes no database write at all (AR-03)` (line 673) goes further and asserts zero SQL writes via `DB::listen`, which is the stronger, more precise assertion AR-03 asked for; it deliberately starts from `preview()`'s own already-reconciled output to guarantee a true end-to-end no-op, as the developer's comment explains. This is sound and does not weaken or contradict the older test.
- **Tests** (`NoteServiceTest.php`, read in full): three new tests — a content-changing `save()` on a trusted mtime nulls it; an external edit via `preview()` on a trusted mtime nulls it; a no-op save makes zero DB writes (via `DB::listen` counter). All three were run and pass.
- **Verdict: resolved.**

### QA-03 (Low, MINOR) — missing DB-failure test for a stale row at the recreate path — RESOLVED

- New test `a DB failure recreating a deleted source leaves the stale row and its uuid intact (QA-03)` in `tests/Feature/Notes/NoteCopyTest.php` (read in full, lines 173-197): writes `X.md`, reconciles (registering the note), deletes the file **without** reconciling (leaving a stale registry row exactly as required), registers a throwing `Note::creating` listener, calls `createCopy()`, asserts the exception rethrows, the newly-created file is removed by the existing `deleteNewFileWithContents` compensation, and — the assertion this test specifically adds — the stale row **survives with its original uuid and `relative_path`**. This genuinely exercises the transaction-rollback path: `registerNewFile()`'s `clearStaleRow` delete and the failing `Note::create()` insert run inside the same `DB::transaction()` closure, so the `Note::creating` throw rolls back both statements together, leaving the stale row exactly as it was pre-delete. Confirmed by reading `NoteService::registerNewFile()` directly: the delete and the `create()` call are both inside the single `$this->database->connection()->transaction(function () {...})` closure, with the file-compensation `catch` outside it.
- **Verdict: resolved**, genuinely exercises the stale-row-at-recreate-path scenario, exactly as `analyst-review.md` §4 specified.

---

## 2. Test Execution (this round, after `route:clear` + `config:clear`)

| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/Notes tests/Feature/Services/NoteServiceTest.php tests/Feature/Services/VaultReconcileTest.php tests/Feature/Services/ExternalChangeServiceTest.php` | `198 tests, 195 passed, 644 assertions, 3 skipped` (pre-existing `skipOnWindows()` symlink/chmod skips) |
| `php artisan test --compact` (full suite) | `609 tests, 599 passed, 1718 assertions, 10 skipped` (matches the developer's own Fix Round 2 record exactly) |
| `npm run test:js` | `11 files, 138 tests, all passed` |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean, no output |
| `php vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` (no dirty files needed fixing) |

No failures to paste. No CSRF/419 flakiness recurred this round (the round-2 report's transient sandbox artifact did not reappear).

---

## 3. Regression check (Phase 5, broader)
- `git diff --stat` confirmed `VaultIndexService.php`, `IndexResult.php`, `FileStorageService.php`, `ExternalChangeService.php` are **unchanged** in this fix round (not listed in Fix Round 2's file table) — no new regression surface there; their behaviour is already covered by the unmodified `VaultReconcileTest.php`/`ExternalChangeServiceTest.php`, both of which pass.
- Confirmed `assertNoConflict()`'s `$except`-based logic (relied on by `createCopy`'s deviation 2, approved in `analyst-review.md` §3) is untouched this round — read the current implementation directly, matches the analyst's description exactly.
- Grepped changed Vue/TS files for `v-html` and hardcoded `notes/${...}`-style URLs: none found.
- `Note` model: `file_mtime` is `nullable integer` cast, fillable — consistent with the migration and the AR-03 fix.
- The pre-existing `NoteManagementTest.php:174` 404 test and the full Phase 5 suite (609 tests) both pass unmodified, confirming no collateral regressions from the `routes/notes.php` `missing()` handler or the `useExternalChanges.ts`/`useUnsavedChangesGuard.ts` async-visit changes.

No new issues found. No open issues remain from any prior round (QA-01, QA-02 resolved round 2; QA-04 accepted no-action by the analyst; F1–F6 are documented follow-ups, not fix-round items).

---

## 4. Issues

| ID | Severity | Classification | Location | Description | Fix Attempts |
|---|---|---|---|---|---|
| AR-01 | High | MODERATE | `routes/notes.php`, `resources/js/composables/useExternalChanges.ts` | **RESOLVED.** See §1. | 1 (resolved) |
| AR-02 | Low | MINOR | `useExternalChanges.ts`, `NoteEditor.vue`, `useUnsavedChangesGuard.ts` | **RESOLVED.** See §1. | 1 (resolved) |
| AR-03 | Low | MINOR | `app/Services/NoteService.php` | **RESOLVED.** See §1. | 1 (resolved) |
| QA-03 | Low | MINOR | `tests/Feature/Notes/NoteCopyTest.php` | **RESOLVED** (test added). See §1. | 1 (resolved) |
| QA-04 | Low | informational | `app/Services/NoteService.php` (`createCopy` candidate loop) | Pre-existing TOCTOU note; analyst accepted no-action in `analyst-review.md` §4. | 0 (no action, per analyst) |

No open Critical/High/Medium issues. No issue at 3 fix attempts; no escalation to System Analyst needed beyond the already-scheduled conditional sign-off.

---

## 5. Routing Recommendation
- [x] **PASS** — this closes QA round 3. Per `analyst-review.md` (Conditional sign-off), this PASS (confirming AR-01–AR-03 and finding no new High/Critical issue) completes the Level 4 sign-off without requiring a second analyst pass. The orchestrator should now apply `analyst-review.md` §8b's line-level edits and move the feature folder to `.ai/features/completed/phase-5-filesystem-intelligence`, then ask the user to run `php artisan test --compact` and the manual desktop checks D1–D25.
- No issues route to Senior Developer.
- No issues route to System Analyst.
