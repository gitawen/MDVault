# QA Report: Phase 4: Tiptap Editor, Round 3

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Author**: Senior QA Engineer
- **QA Round**: 3
- **Date**: 2026-09-29
- **Source**: Level 4 analyst review (`analyst-review.md`), routed MODERATE back to `senior-developer` for A1 (High), A2 (High), R2-01, R2-02 — all fix attempt 1
- **Baseline**: `802d4cf`, same branch `phase-4-tiptap-editor`, everything still uncommitted
- **Verdict**: **PASS**

> PASS requires zero open Critical or High issues. Two new Low-severity, non-blocking observations are opened (R3-01, R3-02); neither is Critical or High, and neither is a regression of a fixed defect.

---

## 1. Status of Each Item

| ID | Severity | Status | Verification |
|---|---|---|---|
| A1 | High | **RESOLVED** | `switchMode()`'s in-place branch now disposes the stale saver (`saver?.dispose(); saver = null;`) before doing anything else, then for Rich→Source seeds `sourceText` from `props.note.content` (the pristine full file, frontmatter included — never `readContent()`), and for Source→Rich only flips `mode.value`, letting the existing `v-if="mode==='rich'"`/`v-else` template force a real remount whose `ready` emit creates a fresh saver from the editor's own baseline. Traced both directions by hand (see §2) — no residual defect found. |
| A2 | High | **RESOLVED** | `serializeEditor()` (in `extensions.ts`) is now the single call site for `editor.getMarkdown()` anywhere in `resources/js`, used by both `converter.ts` call sites and by `TiptapEditor.vue`'s `onCreate` and its renamed `getContent()`. Confirmed by grep (§3) and by reading the new `roundTrip.test.ts` case, which reproduces the exact "first edit after opening an `exact` bare-URL note" scenario the review described and asserts the bare URL survives. |
| R2-01 | (follow-up) | **RESOLVED** | `useUnsavedChangesGuard` gains `freeze`/`unfreeze`; `handleBefore` freezes on the not-dirty pass-through path and immediately before every `replay()` (saved/clean and discard-and-continue); `router.on('finish', …)` unfreezes and is unsubscribed on unmount. `NoteEditor.vue` wires a `frozen` ref into `TiptapEditor`'s `editable` and a new `SourceEditor` `readonly` prop. Traced against Inertia's documented `finish` semantics (fires for completed/interrupted/cancelled/errored visits alike — confirmed via `search-docs`) — no path found that leaves the editor frozen forever (see §2). One narrow, non-blocking residual noted as R3-01. |
| R2-02 | (follow-up) | **RESOLVED** | `runFlushLoop()` now checks `if (disposed) { return 'failed'; }` at the top of every iteration, exactly per spec. New test reproduces the misuse case (dispose() without awaiting flush() first) and asserts no further send happens. No iteration/time cap was added, per the review's explicit instruction — accepted, see §4. |

---

## 2. Code Review

### `switchMode()` (A1)
- **Neither direction writes the other mode's serialization.** Rich→Source seeds from `props.note.content`, never `readContent()` (which only ever returns the Tiptap body). Source→Rich never reads Source content at all — it relies entirely on the remounted `TiptapEditor`'s own `onCreate`/`ready` emit, itself fed from the static `:markdown="note.body ?? ''"` prop.
- **The saver baseline is correct after the switch**, in both directions: `saver?.dispose(); saver = null;` runs first, so the guard `if (saver) return;` inside `ensureSaver()` can never short-circuit and silently keep a stale baseline (which was exactly the reverse-direction half of A1's bug). By the time this runs, `flush()` has already been awaited to a non-`'failed'` result, and `isDirty()` is provably false at that point (`runFlushLoop()`'s `.finally()` clears `flushPromise` synchronously as part of settling the very promise being awaited), so `dispose()`'s dev-time "called while dirty" warning does not fire spuriously here.
- **This in-place branch's core invariant** — that `props.note.content`/`props.note.body` still equal what's on disk — holds because it is only reached when `writtenSinceMount.value` is false, i.e. nothing was written to disk during this mount by MDVault itself. (An external edit during the same window is a pre-existing, already-covered case: the stale-hash guard at save time would 409, per FR-11 — not a new regression.)
- **`writtenSinceMount = true` takes the non-in-place path correctly.** `writtenSinceMount.value` is set inside `send()`'s `onSuccess` callback, which runs synchronously as part of resolving the `send()` promise the awaited `flush()` chains through — so by the time `switchMode()` reads `writtenSinceMount.value` right after `await flush()`, it already reflects any write that *this very flush call* just performed, including the first one of the mount. Confirmed the `router.reload({ only: ['note'] })` branch is taken correctly in that case.
- Confirmed `v-if="mode === 'rich'"` / `v-else` on `TiptapEditor`/`SourceEditor` (not `v-show`), so mode switches genuinely unmount/remount rather than toggle visibility — required for A1's `ready`-driven re-baseline to fire at all.

### `useUnsavedChangesGuard` (R2-01)
- **Freeze/unfreeze on every path**: not-dirty pass-through (before an unblocked visit), the saved/clean-after-flush path (before `replay`), and `discardAndContinue` (before its `replay`) all freeze; `router.on('finish', …)` unfreezes unconditionally, regardless of the visit's outcome.
- **No permanent-freeze path found.** Checked against Inertia's documented event semantics (`search-docs`): the `finish` event "fires after an XHR request has completed for both successful and unsuccessful responses" and is explicitly "not cancelable"; the progress-indicator example in Inertia's own docs inspects `visit.completed`/`visit.interrupted`/`visit.cancelled` *from inside* the `finish` handler, confirming `finish` fires for interrupted and cancelled visits too, not just clean successes. Traced each named scenario:
  - **Cancelled visits**: our own `event.preventDefault()` (dirty path) aborts the *original* visit before it starts, but we never rely on that visit's `finish` — we freeze and then create a brand-new `replay()` visit, whose own `finish` is guaranteed once it starts.
  - **Errors**: a 4xx/5xx/network failure during a *replayed* visit still fires `finish` (per the doc above), so `unfreeze()` still runs even though the destination page didn't load.
  - **Re-index**: `runReindex()` is a full Inertia visit through the same `router`, so it goes through the identical `before`/`finish` lifecycle; whether or not it ends up remounting `NoteEditor` (via the `:key="note.uuid:base_hash"` change), either the instance is destroyed (frozen state moot) or `finish` reliably unfreezes the surviving one.
  - **Partial reloads of the same note** (`router.reload({ only: ['note'] })` from `switchMode`/`conflictReload`): same reasoning — either a remount happens (key changes) or `finish` unfreezes the persisting instance.
  - `unsubscribeFinish?.()` is present in `onUnmounted` alongside `unsubscribeBefore?.()` — confirmed no leaked subscription.
- **One narrow residual, not a regression**: if the user triggers a *second* Inertia visit (e.g. clicking a different note) while a first, already-frozen visit is still in flight, Inertia's default visit-interruption behavior means the first visit's own `finish` fires (unfreezing) before the second visit's `finish` does — which could theoretically re-open a very brief typing window mid-navigation-to-the-second-visit. This is narrower than R2-01's original bug (bounded by a rapid double-navigation, not a single save-or-visit round trip) and isn't something the fix round claimed to close. Logged as **R3-01** (Low, non-blocking).
- **Minor observation**: the mode-toggle buttons and the Save button are not `:disabled` while `frozen`. Clicking them mid-navigation is harmless (traced: `switchMode()`'s `flush()` resolves `'clean'` immediately since typing is blocked by `readonly`/non-`editable`, and the mode switch itself writes nothing), just a small, cosmetic UX inconsistency. Logged as **R3-02** (Low, non-blocking).

### `runFlushLoop()` (R2-02)
- `if (disposed) { return 'failed'; }` sits at the very top of each loop iteration, before `readContent()` and before `sendOnce()`, so a `dispose()` racing an in-flight send correctly prevents the loop from starting a *further* send once that send resolves. The new test reproduces exactly this. No new hazard introduced; the accepted "no iteration cap" design point (deterministic serializer, user-driven termination) is unchanged from round 2's review and is now recorded in the ADR addendum (§4 of the analyst review, to be applied on PASS).

### A1/R2-01: no automated test coverage — judged acceptable
Confirmed via `package.json` that there is genuinely no Vue component-mounting harness in this project (no `@vue/test-utils`, no `happy-dom`, no `jsdom`) — consistent with the project's established test architecture (framework-free `lib/` unit tests under Vitest's `node` environment, headless Tiptap editors, Pest for the backend). Given:
1. the gap is structural to the whole project, not a shortcut invented for this fix round;
2. both A1 and R2-01's logic is fundamentally about Vue lifecycle/props/refs/template conditionals and Inertia router-event wiring, not easily extractable into a pure, `lib/`-style testable function the way QA-02's `saveTransport.ts` was;
3. I traced both fixes by hand against the actual, current source and found them correct (no defect surfaced that a test would have caught and I missed);
4. manual checks M14/M15 (A1) and M16 (R2-01) exist and target these exact scenarios;

this is classified as an **accepted, documented test-coverage gap** (Low), not a blocking defect. Adding a component-mount harness is a new test-infrastructure decision that reasonably belongs outside an urgent fix round.

---

## 3. Test Execution (scope per the analyst)

| Command | Result |
|---|---|
| `npm run test:js` | `6 test files, 74 tests, all passed` |
| `php artisan test --compact tests/Feature/Notes tests/Feature/Services/NoteServiceTest.php` | `104 tests, 102 passed, 318 assertions, 2 skipped` (pre-existing platform skips) |
| `npm run types:check` | clean, no output |
| `npm run check` | "All 81 files are correctly formatted" / "no warnings or lint errors in 74 files" |
| `npm run build` | succeeds (same pre-existing chunk-size warning only) |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `rg -n "\.getMarkdown\(\)" resources/js` | 2 matches, both inside `serializeEditor` in `extensions.ts` (its docblock and its body) — matches the required outcome exactly |
| AI-attribution grep (`Co-Authored-By\|Generated with\|Claude\|Anthropic`, case-insensitive, over `resources/js`, `app`, `tests`, and the feature folder) | zero hits in any source/test file; the only hits anywhere in the repo are pre-existing framework docs (`CLAUDE.md`, `AGENTS.md`, the bootstrap-prompt doc) and this feature's own `plan.md`/`implementation.md`/prior `qa-report*.md` quoting the "no AI attribution" rule |

No PHP files were touched this round, so the full Pest suite wasn't re-run per the analyst's stated scope; round 2's full-suite result (484/476/8-skipped) stands unaffected.

---

## 4. New Issues (Round 3)

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| R3-01 | Low | MINOR (follow-up, not blocking) | `resources/js/composables/useUnsavedChangesGuard.ts` | A second Inertia visit started while a first (already-frozen) guarded visit is still in flight can have its own `finish` unfreeze the editor before the second visit completes, narrowly reopening a typing window mid-navigation. Narrower than the original R2-01 bug (needs two overlapping navigations, not one); not something this fix round claimed to close. | Document as an accepted residual, or close fully later (e.g. track an in-flight-visit counter and only unfreeze when it reaches zero). Recommend adding a manual check alongside M16 that tries rapid clicks across two different notes. | 0 |
| R3-02 | Low | MINOR (follow-up, not blocking) | `resources/js/components/editor/NoteEditor.vue` (mode-toggle buttons, Save button) | These aren't `:disabled` while `frozen`, though clicking them mid-navigation is harmless (traced: no data loss, no unwanted write). | Optional: disable them while `frozen` for UI clarity. | 0 |

Both are Low severity and do not block PASS.

---

## 5. Manual Checks (M14–M16, user-verified — not defects)

- **M14**: open a frontmatter note in Rich → Source → type one character → the frontmatter is byte-identical on disk.
- **M15**: open a `reformat` note → "Edit as source" → type one character → the external diff shows only that character.
- **M16**: click another note while typing → no characters are accepted after the click, and the first note's file holds everything typed before the click. (Suggest also trying a rapid click across two *different* notes in quick succession, per R3-01.)

(Carried over from earlier rounds, still applicable and still the user's: M1–M13.)

---

## 6. Routing Recommendation

- [x] **PASS** → the orchestrator may apply the analyst review's §4 line-level edits (ADR status/addendum updates, `plan.md` status/M14–M16/revision log) and move the feature folder to `.ai/features/completed/` without a further analyst review, per the review's pre-authorised sign-off condition.
- [ ] FAIL — MINOR/MODERATE → Senior Developer
- [ ] FAIL — MAJOR → System Analyst

Optional, non-blocking follow-ups for the Developer if there's appetite: R3-01, R3-02 (both Low/MINOR).
