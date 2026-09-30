# Analyst Review: Phase 5, Filesystem Intelligence (Level 4 sign-off)

## Metadata
- **Feature**: Phase 5, Filesystem Intelligence (mdv-p5). Master Plan §54.
- **Reviewer**: System Analyst
- **Date**: 2026-09-30
- **Inputs**:
  - `requirements.md`, `plan.md` (Revision 1)
  - `implementation.md` (including Fix Round 1)
  - `qa-report.md` (round 1: FAIL), `qa-report-round-2.md` (PASS)
  - ADRs `external-change-detection`, `external-change-reconciliation`, `open-note-external-conflicts`
  - The actual code (uncommitted, branch `phase-5-filesystem-intelligence`)
- **Verdict**: **NOT YET APPROVED. Route to `senior-developer` for fix round 2** (AR-01 is MODERATE, AR-02 and AR-03 are MINOR). No replan and no ADR decision changes are needed.
- **Conditional sign-off**: once QA round 3 confirms AR-01 to AR-03 with the tests listed below and finds no new High/Critical issue, this review counts as the Level 4 sign-off. A second analyst pass is not required unless QA raises a MAJOR.

---

## 1. Summary judgement
The server side faithfully realises the ADRs and is of high quality:
- `plan()` / `apply()` / `reconcile()` implement the four-step 1:1 pairing.
- The Quick shortcut and the racy-mtime rule are in place.
- `touch` does not bump timestamps and is not reported.
- The fingerprint stale guard runs inside a single transaction, with one retry.
- `QueryException` is treated as stale.
- Reconcile makes no filesystem writes.
- The tree signature is computed from identical inputs in `browse()` and `apply()`.
- `ExternalChangeService` is orchestration only.

`createCopy` uses exclusive create only, with exact-bytes compensation. All §54 acceptance criteria are met at the service and HTTP level.

The client-side lifecycle has one real defect QA missed (AR-01). In the **dirty + deleted** case, the tree refresh partially reloads a URL whose note no longer exists. That produces an Inertia 404 modal in a tight loop. There are also two small robustness gaps (AR-02, AR-03).

## 2. Non-negotiable rules
| Rule | Assessment |
|---|---|
| No silent overwrite of external edits (§54, Rule 9) | **Holds.** Every write path is guarded:<br>- Every save still goes through `NoteService::save`'s disk base-hash guard plus `replaceFile`'s guard.<br>- `resume()` only re-flushes through that guard.<br>- `overwrite()` is reachable only from the confirmed "Keep mine" and re-sends with the hash the check observed, so a further change still 409s.<br>- `externalConflict` pauses autosave.<br>- `createCopy` uses `fopen('x')` only.<br>- Reconcile writes no files. |
| Stable UUIDs (Rule 4) | **Holds.** Pairing is 1:1 only (exact path, then case-insensitive path, then unique hash, then unique basename). The fingerprint guard prevents a stale plan from resurrecting or re-pointing rows. Caveat F3 (checks off, move mistaken for delete) is Low and documented below. |
| DB/FS independent failure (Rule 10) | **Holds.**<br>- Reconcile applies all-or-nothing and changes nothing on a missing root.<br>- `registerNewFile` wraps the stale-row delete and the insert in one transaction, with file compensation outside it.<br>- `create()` behaviour is unchanged. |
| Filesystem logic only in services (Rule 5) | **Holds.** `VaultIndexService` uses only `FileStorageService` and `FileHashService`. `ExternalChangeService` has no filesystem calls (arch test). Vue passes `relative_path` back verbatim, and there is no `v-html`. |
| No new tables / no content column / relative paths / SHA-256 | **Holds.** One nullable `notes.file_mtime` column (G2). |

## 3. Rulings on the developer's plan deviations
- **Deviation 1: `ReconcilePlan` stores ids, not models. ACCEPTED.**
  - It is safer than the plan: `apply()` writes through models fetched fresh inside the transaction.
  - The `$byId[...]` lookups cannot miss, because the fingerprint map equality guarantees the same id set.
- **Deviation 2: `createCopy` excludes the source row, and since Fix Round 1 clears a stale row at the target path. ACCEPTED.** I re-derived QA's safety argument independently. The `clearStaleRow` delete runs only for a candidate that has passed all three of:
  - `exists()`, which ignores `$except`;
  - a case-insensitive scan of every other row;
  - an exclusive create.

  It can therefore only ever remove the source's own stale row, and only at its exact original path. It matches FR-15 ("recreated … with a new UUID"). Residual caveat: F3.

## 4. Rulings on QA-03 and QA-04
- **QA-03 (Low): accepted as a coverage gap, and to be closed in this fix round** because it is cheap. Add a `NoteCopyTest` case:
  - leave a stale row at the recreate path (unlink the file, don't reconcile);
  - register a throwing `Note::creating` listener;
  - call `createCopy`;
  - assert the exception is rethrown, the new file is removed, and the stale row **still exists with its original uuid**.
- **QA-04 (Low): accepted, no action.** The race window between the conflict check and the write exists only with concurrent writers, and MDVault has a single user and a single instance by design (§44). The same pattern already exists in `create()` and `relocate()`. Revisit only if multi-process writers ever appear.

---

## 5. New findings

### AR-01: Tree refresh on a deleted open note hits a 404 and loops (Severity: High, Classification: MODERATE)
**Where**:
- `resources/js/composables/useExternalChanges.ts` (`run()`, the `start`/`finish` handlers)
- `routes/notes.php` (`notes.show`)

**What happens (dirty + deleted, and 409 `missing` followed by `request-check`)**:
1. The Workspace URL is `/notes/{uuid}`. The check reconciles and deletes the row, so `tree_signature` changes.
2. `router.reload({ only: ['tree','folders','treeSignature'] })` GETs `/notes/{uuid}`. Implicit binding `{note:uuid}` throws `ModelNotFoundException`, which becomes an HTML 404.
3. Inertia core (`fireHttpExceptionEvent` then `dialog_default.show(response.data)`) opens a **modal** containing the 404 page. That blocks the conflict banner the user needs ("Save as a new note").
4. `props.treeSignature` never updates. `router.on('finish')` in `useExternalChanges` calls `setActive(true)`, which calls `trigger()` 250 ms later, so the check runs again, mismatches again, reloads again and opens another 404 modal.

This is a tight loop, not a 5 s cadence, and it also hammers the single-threaded desktop server.

The clean + deleted case is probably masked, because `leave` visits `/` and Inertia cancels in-flight async requests to the old page. That depends on timing and isn't guaranteed.

This breaks FR-14 and FR-15 in the exact scenario D13 (second half) exercises. QA missed it because it can't be seen in Pest or Vitest.

**Required fix (within existing design)**:
1. `routes/notes.php`: add a `->missing(...)` handler to the `notes.show` route (keep `whereUuid` and the name):
   - When the request is an Inertia **partial** reload (a non-empty `X-Inertia-Partial-Data` header), render the Workspace **without a note**: `return app()->call([app(WorkspaceController::class), '__invoke'], ['note' => null]);`. The partial reload then returns `tree`/`folders`/`treeSignature` normally. If `note` is requested, it returns `null`.
   - Otherwise keep today's behaviour (`abort(404)`), so the existing test "an unknown uuid and an integer id both 404" (`NoteManagementTest.php:174`) stays unchanged.
   - Do **not** redirect partial reloads: a redirect would change the page URL under a mounted editor.
2. `useExternalChanges.ts`: in the `router.on('start')` / `router.on('finish')` handlers, ignore visits with `event.detail.visit.async === true`. Tree reloads and `NoteEditor.refresh()` are async and are not navigations. A failed background reload must never re-trigger a check within 250 ms; the normal interval or back-off applies instead. Verify the `async` field name on the event's visit in `@inertiajs/core` types.
3. Tests, in `tests/Feature/WorkspaceTest.php`:
   - A partial Inertia GET to `route('notes.show', $deletedUuid)` returns 200, with component `Workspace`, `treeSignature` equal to `ExternalChangeService::check()['tree_signature']`, and a tree that no longer contains the note. Use the headers `X-Inertia: true`, `X-Inertia-Version` (the app's current version), `X-Inertia-Partial-Component: Workspace` and `X-Inertia-Partial-Data: tree,folders,treeSignature`.
   - The same request with `X-Inertia-Partial-Data: note` returns `note` as `null`.
   - A full (non-partial) GET to a deleted uuid still 404s. The existing test covers this; it must stay green.

### AR-02: Stale check results can act on a replaced editor or unfreeze a guarded navigation (Severity: Low, Classification: MINOR)
**Where**: `useExternalChanges.ts` (`run()`), `NoteEditor.vue` (`applyExternalStatus`), `useUnsavedChangesGuard.ts` (`finish` handler).

**What can happen**:
- `run()` captures `target` before `await postCheck(...)` and calls `target.applyExternalStatus` afterwards, without checking that the same editor is still mounted. In browser dev, where requests are concurrent, a result for note A can arrive after the user opened note B. A `leave` action on A's unmounted instance would then `router.visit('/')` away from B. On the desktop the server is serial, so this is unlikely but not impossible.
- Separately, the guard's `finish` handler unfreezes on **any** visit finish. That includes an exempt tree reload, which can finish while a guarded navigation (which froze the editor) is still in flight. Keystrokes typed in that window can then be lost on unmount (R2-01 regression risk). The frequency of this rises with Phase 5 polling.

**Required fix**:
1. `useExternalChanges.run()`: after the `await`:
   - if the checker was disposed, or `navigating` is true, return `'skipped'` without reloading or applying;
   - call `applyExternalStatus` only when `options.editor() === target`.
2. `NoteEditor.vue`: keep a local `let unmounted = false` (set it in `onUnmounted`), and make `applyExternalStatus` return immediately when `unmounted` is true.
3. `useUnsavedChangesGuard.ts`: in the `finish` handler, skip `options.unfreeze()` when `isEditorSafeVisit(event.detail.visit, window.location.href)` is true.
4. Tests: extend `tests/js/editor/visitSafety.test.ts` only if a pure helper is introduced. Otherwise cover this by code review and manual check D25.

### AR-03: In-app hash updates leave a stale trusted `file_mtime` (Severity: Low, Classification: MINOR)
**Where**: `app/Services/NoteService.php`, in `preview()` (both `$note->update([...file_hash, file_size])` calls) and private `reconcile()` (used by `save()`).

**What happens**: these update `file_hash` and `file_size` but keep the old trusted `file_mtime`. That breaks the ADR invariant "`file_mtime` non-null ⇒ (size, mtime) attests to `file_hash`". A later external restore of the previous version with a preserved mtime and the same size (for example a cloud-sync or `cp -p` restore) would then be skipped by Quick mode, leaving the stored hash stale for a closed note.

**Required fix**: whenever these calls actually change `file_hash` or `file_size`, also set `'file_mtime' => null`. In `reconcile()`, add it only when the hash or size differs from the stored values, so that no-op saves still make no write. Do not change `present()`.

**Tests** (in `tests/Feature/Services/NoteServiceTest.php`, or `VaultReconcileTest.php` if the fixtures are there):
- After a Full reconcile with trusted mtimes, a content-changing `save()` leaves `file_mtime` `null`.
- A `preview()` after an external edit leaves `file_mtime` `null`.
- A no-op save leaves `file_mtime` unchanged. Assert it with a `DB::listen` update counter of 0.

---

## 6. Low follow-ups (documented, not in this fix round)
- **F1: vault vanishes while the editor is dirty.** The plan's `unavailable → router.reload()` is guarded, the flush fails, and the Stay/Discard dialog appears. Choosing Stay repeats the cycle on every check (about 5 s). No data is lost, but it nags. Future fix: expose `isDirty()` on `ExternalChangeTarget` and skip the full reload while dirty, showing an inline "vault unavailable" banner instead.
- **F2: remount on "moved" after a save.** `NoteEditor` is keyed `uuid:base_hash` from props. If any save happened since mount, a `refresh()` after an external move brings a new `base_hash`, so the editor remounts: cursor and scroll reset, no data loss. The ADR's "same key, no remount" holds only when there has been no save since mount. Observe during D4 and D14.
- **F3: "Save as a new note" with automatic checks off, on a note that was *moved* (not deleted).** The stale source row is cleared, and the moved file gets a new UUID on the next reconcile. Content is safe; identity is lost. Future fix: run `reconcile(Quick)` in `NoteCopyController` before `createCopy` when the source row's file is missing.
- **F4: resume loop.** If a row is kept for a missing file (under an unreadable directory), a dirty editor can cycle resume, 409 `missing`, then `request-check`. This is an edge case (chmod'd directories). Future fix: resume at most once per conflict.
- **F5:** `notes.disk.show` and `vaults.notes.copy` do not require the vault to be the current one. This is acceptable under ADR `local-app-without-authentication`.
- **F6:** "Save mine as a new note" from a moved + changed conflict uses the pre-move `source_path`, so the copy lands at the old location with default EOL/BOM. It never overwrites anything, and the banner names the new path.

---

## 7. Routing
**Senior Developer, fix round 2** (plan Revision 2, task T11):
- AR-01 (MODERATE): required.
- AR-02 (MINOR): required.
- AR-03 (MINOR): required.
- QA-03 (MINOR): add the test.

Rules: no ADR decision changes, no new dependencies, no new folders. Run `php vendor/bin/pint --dirty --format agent` and `php artisan wayfinder:generate --with-form --no-interaction` (the route is unchanged in name and URI; regenerate anyway), and record everything in `implementation.md` under "Fix Round 2".

**QA round 3 scope**:
```
php artisan test --compact tests/Feature/WorkspaceTest.php tests/Feature/Notes tests/Feature/Services/NoteServiceTest.php tests/Feature/Services/VaultReconcileTest.php tests/Feature/Services/ExternalChangeServiceTest.php
npm run test:js
npm run types:check
php artisan test --compact
vendor/bin/phpstan analyse
```
Code review focus:
- the `missing()` handler renders only for partial reloads, and full visits still 404;
- the `start`/`finish` handlers ignore async visits;
- the post-await target guard;
- the guard's `finish` exemption;
- `file_mtime` is nulled only when the hash or size changes.

---

## 8. Line-level edits

### 8a. Apply now (before routing to the developer)
**`plan.md`**:
- Line 11: replace it with
  `- **Status**: APPROVED, Revision 2 (fix round from Level 4 analyst review: AR-01 to AR-03, QA-03). G1–G8 approved as recommended 2026-09-30.`
- Insert immediately before the line `## 4. Test Plan` (after the T10 block):
  ```
  - [ ] **T11 (Revision 2): Analyst-review fixes** (see `analyst-review.md` §5)
    - AR-01: `routes/notes.php` `notes.show` gets a `->missing()` handler that, for Inertia partial reloads only (non-empty `X-Inertia-Partial-Data`), renders `WorkspaceController` with `note = null`; full visits still 404. `useExternalChanges.ts` ignores async visits in its `start`/`finish` handlers. Tests in `tests/Feature/WorkspaceTest.php` (partial tree reload on a deleted uuid → 200 with the check's signature; partial `note` reload → `note` null; full GET still 404).
    - AR-02: `useExternalChanges.run()` skips results after dispose or navigation and only applies them to the same editor instance; `NoteEditor.applyExternalStatus` ignores calls after unmount; `useUnsavedChangesGuard` does not unfreeze on the finish of an editor-safe visit.
    - AR-03: `NoteService::preview()` and private `reconcile()` set `file_mtime` to null whenever they change `file_hash`/`file_size` (no write on a no-op save). Tests for save, preview and the no-op case.
    - QA-03: `NoteCopyTest` DB-failure variant with a stale row at the recreate path; the stale row survives with its uuid and the new file is removed.
    - Covers: FR-06, FR-10, FR-14, FR-15, FR-17
  ```
- Revision Log: append the row
  `| 2 | 2026-09-30 | Level 4 analyst review | T11 added: AR-01 (404 loop on tree refresh of a deleted open note), AR-02 (stale-result and unfreeze guards), AR-03 (null file_mtime on in-app hash updates), QA-03 test. No ADR decision change. |`

**`implementation.md`**: the developer sets `Plan Revision Implemented` to `Revision 2` and adds a "Fix Round 2" section.

### 8b. Apply on completion (after QA round 3 PASS confirming AR-01 to AR-03)
**`plan.md`**:
- Line 11: replace it with
  `- **Status**: DELIVERED (Level 4 analyst sign-off 2026-09-30, conditional on QA round 3 PASS; G1–G8 approved as recommended)`
- Change every task checkbox `- [ ] **T0` … `- [ ] **T11` to `- [x]`.
- Revision Log: append the row
  `| 3 | 2026-09-30 | Delivered | Level 4 sign-off; follow-ups F1–F6 recorded in analyst-review.md §6 |`

**`requirements.md`**:
- Line 10: replace it with
  `- **Status**: DELIVERED (G1–G8 approved as recommended 2026-09-30; Phase 5 signed off 2026-09-30)`

**`.ai/decisions/external-change-detection.md`**:
- Line 3: replace it with
  `- **Status**: Accepted; delivered in Phase 5 (G1, G6, G8 approved 2026-09-30; signed off 2026-09-30)`
- Follow-ups: append the bullet
  `- Vault disappearing while the editor is dirty re-prompts the unsaved-changes dialog on each check (F1 in the Phase 5 analyst review); skip the full reload while dirty in a later phase.`

**`.ai/decisions/external-change-reconciliation.md`**:
- Line 3: replace it with
  `- **Status**: Accepted; delivered in Phase 5 (G2, G3 approved 2026-09-30; signed off 2026-09-30)`
- In Decision, "Own writes" bullet: append the sentence
  ` In-app hash updates (\`NoteService::save\`, \`preview\`) set \`file_mtime\` to null, so a non-null \`file_mtime\` always attests to the stored hash (AR-03).`
- Follow-ups: append the bullet
  `- With automatic checks off, "Save as a new note" on a note that was moved (not deleted) clears its stale row, so the moved file is re-registered with a new UUID (F3); consider a Quick reconcile before the copy.`

**`.ai/decisions/open-note-external-conflicts.md`**:
- Line 3: replace it with
  `- **Status**: Accepted; delivered in Phase 5 (G4, G5, G7 approved 2026-09-30; signed off 2026-09-30)`
- In Decision, "Transport" list: append the bullet
  `- A partial reload of \`notes.show\` for a uuid whose record no longer exists renders the Workspace with \`note = null\` (full visits still 404), so tree refreshes while the \`missing\` banner is shown never error. Background (async) reloads do not pause or re-trigger the checker (AR-01).`
- Follow-ups: append the bullet
  `- The editor remounts on a "moved" refresh when a save happened since mount (key \`uuid:base_hash\`), resetting cursor/scroll (F2).`

Then move the feature folder:
`mv .ai/features/active/phase-5-filesystem-intelligence .ai/features/completed/phase-5-filesystem-intelligence`

---

## 9. Manual desktop checks (user)
**Before you start**:
- Run `php artisan native:migrate` once, which adds `notes.file_mtime` to the desktop DB. Never run `migrate:fresh`.
- Then run `composer native:dev`, with VS Code and Explorer side by side and DevTools Network open.

**Checks D1–D22** (plan T10):
- **D1**: Edit a closed note in VS Code. Within about 5 s, or immediately on focusing MDVault, opening it shows the edit, and the footer hash matches.
- **D2**: Create `Ideas/New.md` in Explorer. It appears in the tree with no click.
- **D3**: Delete a note in Explorer. It disappears from the tree.
- **D4**: Rename the open, clean note (no edits since opening). The header path updates with no flicker, and the URL is unchanged.
- **D5**: Move the open, clean note to another folder. Same result as D4.
- **D6**: Rename a folder containing the open note. The tree updates and the open note follows (same URL).
- **D7**: Create and delete an empty folder. The tree updates each time.
- **D8**: The open note is clean; edit and save it in VS Code. MDVault reloads it with an info toast.
- **D9**: Type continuously in MDVault while saving in VS Code. The banner appears, and VS Code's text is still on disk.
- **D10**: From D9, choose Keep my version and confirm. The disk has MDVault's text.
- **D11**: From D9, choose Save mine as a new note. `X (my version).md` opens with your text, and the original keeps VS Code's text.
- **D12**: From D9, choose Compare. Removed and added lines are marked; text only.
- **D13**:
  - Delete the open, clean note. The Workspace shows no note, with a toast.
  - Repeat while typing. The missing banner appears **and no error dialog or 404 overlay appears; the tree drops the note (AR-01)**.
  - Save as a new note recreates it at the original path, with a new URL.
- **D14**: Move the open note while typing. Editing continues, the text lands at the new location (check in VS Code), and no banner remains. A remount (cursor reset) is acceptable (F2).
- **D15**: Turn the setting off. No `changes` requests are sent. Opening a note still shows the disk content, and a save over an external edit still conflicts. The missing banner shows "Re-index vault".
- **D16**: Minimise MDVault. No `changes` calls are made. Restore it: exactly one immediate call.
- **D17**: Rename the vault folder while it is open. The missing-vault alert appears within about 5 s. Rename it back: the vault recovers without a restart.
- **D18**: Generate 5,000 notes. Typing stays responsive, and after the first pass `changes` calls take under about 500 ms.
- **D19**: `git checkout` another branch in a vault repository. The tree updates, and unchanged notes keep their URLs.
- **D20**: Place `.mdvault-save-test` with an mtime more than 60 s old. The orphan notice lists it, and the file is untouched after you dismiss the notice.
- **D21**: Do an in-app create, rename, move, delete and save. No external-change toasts or banners appear.
- **D22**: With Wi-Fi off, all of the above still works.

**New checks**:
- **D23 (AR-01, Network tab)**: While the missing banner from D13 is shown, watch Network for 30 s:
  - `changes` calls continue at about 5 s intervals, not in a tight loop;
  - the tree partial reload returns 200.
- **D24 (F1, observe only)**: Rename the vault folder away while typing unsaved edits. Record whether the unsaved-changes dialog re-appears on each check. This is expected behaviour for now and is logged as follow-up F1.
- **D25 (AR-02)**: Delete note A in Explorer, then immediately click note B in the tree. You end on B and are not redirected to the Workspace root. At most, a toast about A appears.
