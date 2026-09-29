# QA Report: Phase 4: Tiptap Editor — Round 2

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-09-29
- **Baseline**: `802d4cf`, same branch `phase-4-tiptap-editor`, everything still uncommitted
- **Verdict**: **PASS**

> PASS requires zero open Critical or High issues. Two new Low/Medium follow-ups are opened as non-blocking documented risks (see §3); neither is Critical or High.

---

## 1. Status of QA-01 / QA-02 / QA-03

| ID | Round 1 Severity | Status | Verification |
|---|---|---|---|
| QA-01 | Critical | **RESOLVED** | `noteSaver.ts`'s `flush()` now runs `runFlushLoop()`, which re-reads `readContent()` after every send and keeps sending — with no debounce wait — until it matches `baseline`, or a send fails. Independently re-ran my round-1 standalone reproduction (the same script, unmodified, importing the real unmodified `noteSaver.ts` from outside the repo — no files changed in the project) against the new code: the edit typed during the in-flight save ("c") is now sent as call 2 (`send('c', 'h1')`) **before** the guard's `flush()` promise resolves, and `dispose()` afterward does not drop anything. Output: `OK: content "c" was sent.` New unit tests (`noteSaver.test.ts`, 3 added) reproduce the exact scenario, a 3-edit drain chain, and a mid-loop-failure case; all pass, and I re-derived each by hand against the code and agree with the assertions. |
| QA-02 | Medium | **RESOLVED** | `resources/js/lib/editor/saveTransport.ts` (`mapSaveResult`) is a pure, dependency-free function with 11 unit tests. `NoteEditor.vue`'s `send()` now only wires `useHttp`'s four callbacks to it, with no mapping logic left in the component. See §3 verification against the server's actual payloads. |
| QA-03 | Low | **RESOLVED** | Both mode-toggle buttons now carry `:disabled="assessing"` (Rich also keeps its `unsupported` disable), and `switchMode()` itself has an early `if (assessing.value || next === mode.value) return;` guard — defense in depth on top of the (already effectively unreachable, since the check runs synchronously) original gap. |

---

## 2. New-Hazard Review of the Flush Loop

Reviewed against every angle requested:

- **Infinite loop under continuously changing content**: not a hang — each iteration does a real `await options.send(...)`, so the loop yields to the event loop every cycle; it only keeps running as long as the user keeps typing (or a script keeps injecting content), and terminates as soon as `readContent()` stabilises. No stack growth (it's an iterative `for (;;)`, not recursion). The only way this could genuinely never terminate is if `readContent()` returned a different string on two consecutive calls for the *same* underlying document state (a non-idempotent serializer). Tiptap/ProseMirror's `getMarkdown()` is a pure function of document JSON with no randomness in this extension set (no unique-ID plugin, no timestamps), so this isn't reachable with the current converter. There is, however, no defensive iteration cap or max-loop-duration — a purely theoretical robustness gap, not a live bug (see R2-02, Low, non-blocking).
- **Debounce/max-wait interplay**: `runFlushLoop()` calls `clearTimers()` once at loop start; `notifyChange()` no-ops on timer bookkeeping while `flushPromise !== null` (the loop already re-reads content itself), so no duplicate/ghost timers are scheduled during or after a loop. Plain autosave (no explicit `flush()` involved) is unaffected — `'sends at most every 10s under continuous typing'` still passes unchanged. One accepted behavioural change, not a defect: once an explicit `flush()` loop is running (Ctrl+S, a mode switch, or the guard) and the user keeps typing, every keystroke is now sent immediately with no 1.5 s debounce until typing stops — a deliberate and correct trade-off for the correctness fix, but worth knowing it can produce a burst of PUT requests during "type continuously while also spamming Ctrl+S" (see R2-02).
- **`flushPromise` reuse after a failure**: `flush()`'s `.finally(() => { flushPromise = null; })` runs regardless of outcome, so a failed loop correctly clears `flushPromise`, and the next `flush()`/`notifyChange()` call starts a genuinely new loop (not a stale/reused promise). Verified by the new "failure mid-loop" test and by manual trace.
- **Guard / Ctrl+S / switchMode**: all three still call the same `flush(): Promise<'clean'|'saved'|'failed'>` contract unchanged in shape; none needed changes since the fix is internal to `noteSaver.ts`. Confirmed in `NoteEditor.vue` and `useUnsavedChangesGuard.ts`.
- **`dispose()` behaviour**: correctly warns (dev-only `console.warn`) when called while `isDirty()`. One residual, pre-existing (not introduced or claimed-fixed by this round) architectural gap surfaced while reviewing this: `useUnsavedChangesGuard`'s `handleBefore` awaits `flush()` and then calls `replay(visit)` synchronously in the same microtask (no window for new input there), but the *replayed* `router.visit()` itself performs its own async round-trip before the old `NoteEditor` actually unmounts. If the user types again during that navigation's own fetch (not the save's), that very last keystroke schedules a fresh 1.5 s debounce timer which `dispose()` will cancel if the page swaps first. This is a narrower window than the original QA-01 bug (bounded by a local Inertia partial-reload's round-trip rather than a save-plus-think-time gap) and is not something Fix Round 1 claimed to address — see R2-01. Separately, `dispose()` does not itself abort an in-flight `runFlushLoop()` if one is somehow still running when it's called (it only stops future timer scheduling via the `disposed` flag checked in `notifyChange()`) — but every current call site in this codebase awaits `flush()` before anything that leads to `dispose()`, so this is not reachable today; noted only as a defensive-coding observation, not a defect (folded into R2-02).

None of the above are Critical/High. Both are opened as low-priority, non-blocking follow-ups.

---

## 3. saveTransport Mapping vs. the Real Server Payloads

Compared `mapSaveResult()` directly against the current server code:

- **200** (`NoteContentController` → `$result->toArray()` / `NoteSaveResult::toArray()`): `{saved, file_hash, file_size, updated_at}` → matches `RawSaveResult{kind:'success', response}` → `SaveOutcome{kind:'saved', ...}` field-for-field.
- **409** (`NoteContentController`'s `catch (NoteSaveConflictException $e)`): `{reason: 'changed'|'missing', message, current_hash}` → matches `ConflictBody`/`isConflictBody()` exactly (checked `reason`, `message` types; `current_hash` is read through even if the type guard doesn't strictly validate its type, which is fine since the controller always includes it). Both the already-parsed-object and raw-JSON-string cases are handled (`parseConflictBody`), matching the documented uncertainty about what shape `httpResponse.data` arrives in.
- **422** (`NoteContentController`'s `catch (NoteOperationException $e) { throw ValidationException::withMessages([$e->field() => $e->getMessage()]); }`, and `SaveNoteContentRequest`'s own rule failures): Laravel's standard `{message, errors: {field: [msg, ...]}}` shape → `firstMessage()` correctly extracts the first message whether it arrives as a bare string or an array, and falls back to a generic message for an empty bag.
- **Any other status** (500, etc.) and **network failure**: mapped to sensible generic/network messages, matching what `NoteEditor.vue` shows in its error `Alert` and the conflict banner.

This is a correct, complete mapping of every response shape the server can actually produce for this endpoint. The 11 `saveTransport.test.ts` cases cover all of the above plus the edge cases (array field error, empty bag, unparseable 409 body).

---

## 4. Test Execution

| Command | Result |
|---|---|
| `php artisan test --compact` (full suite) | `484 tests, 476 passed, 1357 assertions, 8 skipped` (same pre-existing Windows/symlink skips) |
| `npm run test:js` | `6 test files, 72 tests, all passed` (was 58; +3 `noteSaver.test.ts`, +11 new `saveTransport.test.ts`) |
| `vendor/bin/phpstan analyse` (level 7) | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | clean, no output |
| `npm run check` | "All 81 files are correctly formatted" / "no warnings or lint errors in 74 files" |
| `npm run build` | succeeds (same pre-existing chunk-size warning only) |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| T12 greps | all as expected: `v-html` none; `unlink` only in `FileStorageService`; `replaceFile(` only defined + used in `NoteService::save`; `Underline/TextAlign` only in the doc comment; `openOnClick: false` one match; no `@/` imports in `lib/markdown`/`lib/editor`; no `content` in note migrations; no `NoteViewer`; broadened AI-attribution grep (`Co-Authored-By\|Generated with\|Claude\|Anthropic`) over every changed `.php`/`.vue`/`.ts`/`.md` file hits only pre-existing framework/docs files (`CLAUDE.md`, `AGENTS.md`, `.ai/README.md`, `plan.md`/`implementation.md` quoting the "no AI attribution" rule) and a packaged `nativephp/electron/dist` build artifact — **zero** hits in any new/changed feature file (`noteSaver.ts`, `saveTransport.ts`, `NoteEditor.vue`, their tests) |
| `npm ls @tiptap/core marked` (re-checked) | still a single deduped `@tiptap/core@3.31.3`/`marked@17.0.6`; no new dependencies were added in this fix round |

All commands pass; no regressions in the rest of the suite.

---

## 5. New Issues (Round 2)

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| R2-01 | Low | MINOR (follow-up, not blocking) | `resources/js/composables/useUnsavedChangesGuard.ts` (`handleBefore`/`replay`) | Pre-existing architectural gap, not a regression introduced by this round and not something Fix Round 1 claimed to close: after a successful `flush()`, `replay(visit)` re-issues the Inertia visit, which performs its own async round-trip before the old `NoteEditor` unmounts. If the user types again during *that* window (not the save's), the resulting fresh debounce timer is cancelled by `dispose()` on unmount, silently dropping that last edit. The window is bounded by a local Inertia partial-reload's latency rather than a save's, so it's materially narrower than QA-01, but it is the same class of problem. | Document as an accepted residual risk, or close it fully in a later phase (e.g. re-check `isDirty()`/re-flush immediately before the component actually unmounts, or have the guard hold the visit open until a short "settle" window passes). Not required to block this feature. | 0 |
| R2-02 | Low | MINOR (follow-up, not blocking) | `resources/js/lib/editor/noteSaver.ts` (`runFlushLoop`) | No iteration cap / max-loop-duration safeguard, and `dispose()` doesn't abort an actively-running loop (it only stops future timer scheduling). Not reachable today given every call site awaits `flush()` before disposing, and not a real infinite-loop risk given the converter's serializer is deterministic — but there is no defensive guard if that assumption is ever violated (e.g. a future extension introduces non-deterministic output), and continuous typing during an explicit `flush()` now bypasses the 1.5 s debounce entirely (sends on every keystroke until typing stops), which is a correct trade-off but worth documenting. | Optional: add a loop-iteration/time cap that falls back to `'failed'` with a clear message, and have `dispose()` set a flag the loop checks between iterations, purely as defense in depth. | 0 |

Both are Low severity and do not block PASS per the stated verdict rule (zero open Critical/High).

---

## 6. Routing Recommendation

- [x] **PASS** → move feature to `.ai/features/completed/` (Level 4: analyst sign-off review still applies before completion, per the workflow)
- [ ] FAIL — MINOR/MODERATE → Senior Developer
- [ ] FAIL — MAJOR → System Analyst

Optional, non-blocking follow-ups for the Developer if there's appetite before completion: R2-01, R2-02 (both Low/MINOR). Neither requires System Analyst involvement.
