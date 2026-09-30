# QA Report: Phase 4: Tiptap Editor

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-09-29
- **Baseline**: `802d4cf` ("Before Phase 4: tiptap editor plan and ADRs"), uncommitted working tree on `phase-4-tiptap-editor`
- **Verdict**: **FAIL**

> PASS requires zero open Critical or High issues. One Critical issue (QA-01) is open.

---

## 1. Requirements Coverage

| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 Open in rich text | yes | `tests/js/markdown/roundTrip.test.ts` (`exact/*`), `NoteContentTest` | ✅ |
| FR-02 Fidelity check | yes | `tests/js/markdown/assess.test.ts`, `roundTrip.test.ts` (`unsupported/*`) | ✅ |
| FR-03 No write without an edit | yes (client baseline + server no-op) | `noteSaver.test.ts`, `NoteServiceTest` ("No-op" scenarios), `NoteContentTest` | ✅ — see QA-01 for a related in-flight edge case |
| FR-04 Toolbar | yes | `toolbarCommands.test.ts` | ✅ |
| FR-05 Links | yes (default Tiptap protocol allow-list; no explicit protocol list configured, but verified) | `toolbarCommands.test.ts` | ✅ |
| FR-06 Source mode | yes | `SourceEditor.vue`, `roundTrip.test.ts` | ✅ (manual M5/M6 pending) |
| FR-07 Frontmatter preserved | yes | `MarkdownServiceTest` (frontmatter dataset), `NoteContentTest` | ✅ |
| FR-08 Envelope preserved | yes | `MarkdownServiceTest` (identity/EOL/BOM datasets) | ✅ |
| FR-09 Autosave and explicit save | **partial** | `noteSaver.test.ts` | ❌ see QA-01 (in-flight content can be lost when `flush()` is called explicitly while a save is already in flight) |
| FR-10 Atomic save | yes | `FileStorageServiceTest` (`replaceFile` scenarios) | ✅ |
| FR-11 Stale-write protection | yes | `NoteServiceTest`, `NoteContentTest` (409 changed) | ✅ |
| FR-12 Missing file on save | yes | `NoteServiceTest`, `NoteContentTest` (409 missing) | ✅ |
| FR-13 Save failures | yes | `NoteServiceTest` (lock/short-write/read-only), `NoteContentTest` | ✅ |
| FR-14 DB failure after write | yes | `NoteServiceTest` ("DB failure after a successful write…") | ✅ |
| FR-15 Read-only cases and size cap | yes | `NoteServiceTest`, `MarkdownServiceTest`, `NoteContentTest` | ✅ |
| FR-16 Preferences | yes | `TiptapEditor.vue`/`SourceEditor.vue` share `preferences`; settings copy updated | ✅ (no dedicated JS test, low risk) |
| FR-17 Unsaved-changes guard | **partial** | `useUnsavedChangesGuard.ts` (no automated test) | ❌ same root cause as QA-01: the guard's `flush()` can report `'saved'` and navigate while content typed during an in-flight save is still unsent |
| FR-18 Round-trip tests | yes | `roundTrip.test.ts`, `spike.test.ts` | ✅ |
| FR-19 Boundaries | yes | `ArchitectureTest`, T12 greps | ✅ |
| FR-20 Tree folder field | yes | `VaultIndexServiceTest` | ✅ |
| FR-21 ADR clarification | yes | `.ai/decisions/note-registry-and-indexing.md` | ✅ |
| FR-22 Security and privacy | yes | T12 greps (`v-html`), `NoteContentTest` (no `id`/`vault_id`) | ✅ |

---

## 2. Test Execution

| Command | Result |
|---|---|
| `php artisan test --compact` (full suite) | `484 tests, 476 passed, 1357 assertions, 8 skipped` (pre-existing Windows/symlink skips) |
| `npm run test:js` | `5 test files, 58 tests, all passed` |
| `vendor/bin/phpstan analyse` (level 7) | `0 errors` |
| `npm run types:check` | clean, no output |
| `npm run check` | "All 79 files are correctly formatted" / "no warnings or lint errors in 72 files" |
| `npm run build` | succeeds (`Workspace` chunk 519 kB, pre-existing chunk-size warning only) |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan route:list --path=notes` | shows `PUT notes/{note:uuid}/content .. notes.content.update` |
| `npm ls @tiptap/core marked` | single deduped `@tiptap/core@3.31.3`, `marked@17.0.6` |
| T12 greps | all as expected (`v-html` none; `unlink` only in `FileStorageService`; `replaceFile(` only defined + used in `NoteService::save`; `Underline/TextAlign` only in a doc comment; `openOnClick: false` one match; no `@/` imports in `lib/markdown`/`lib/editor`; no `content` in note migrations; no `NoteViewer`; no AI-attribution text outside `CLAUDE.md`/`plan.md` quoting the rule) |

All commands pass. The full test suite result and every static gate match `implementation.md`'s §3 claims exactly.

**Independent verification of QA-01** (see below): I wrote a standalone Node script (`--experimental-strip-types`) that imports the real, unmodified `resources/js/lib/editor/noteSaver.ts` and exercises the exact race described. It is **not** part of the repository (run from a scratch temp directory, nothing was written into the project). Output:

```
after debounce, sendCalls = 1
guardResult = saved
sendCalls after guard flush resolved = [["b","base"]]
sendCalls after dispose + wait = [["b","base"]]
BUG CONFIRMED: content "c" was never sent before navigation.
```

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | **Critical** | MODERATE | `resources/js/lib/editor/noteSaver.ts` (`flush()`, lines 208–220), consumed by `resources/js/composables/useUnsavedChangesGuard.ts` and `resources/js/components/editor/NoteEditor.vue` (`onKeydown` Ctrl/Cmd+S, `switchMode`) | `flush()` only *returns* the existing `inFlight` promise when a save is already in flight; it never re-checks content after that promise settles. If the user types again while an autosave is in flight (`notifyChange()` sets `rescheduleAfterFlight`), and then something calls `flush()` explicitly before that first save resolves — the navigation guard's `router.on('before', …)` handler, `beforeunload`, or a mode switch — the caller receives `'saved'` as soon as the *first* send completes, and immediately navigates/closes/reloads. The newer content is only *scheduled* (via `markDirtyAndSchedule()`, a fresh 1.5 s debounce timer) inside that same resolution, and `NoteEditor`'s `onUnmounted` calls `saver.dispose()` on navigation, which cancels that timer before it ever fires. The plan (`plan.md` §2/T8) specifies flush() should "await [the in-flight save], then re-check content and resend if changed" — this step is missing. Confirmed empirically (see §2) against the unmodified source. | `flush()` must, after the in-flight save settles, re-read content and perform a further synchronous send (looping if necessary) before resolving, so that a caller awaiting `flush()` never observes `'saved'`/`'clean'` while unsent edits exist. This is the exact scenario named in the task brief: "no lost edits when typing during an in-flight save," and is also what FR-17 ("no navigation away with unsaved text") and §24 (never silently lose an edit) require. | 0 |
| QA-02 | Medium | MODERATE | `resources/js/components/editor/NoteEditor.vue` (`send()`, lines 129–191) | The `useHttp` response/error-shape mapping (`onSuccess`/`onError`/`onHttpException`/`onNetworkError` → `SaveOutcome`) — the entire save-transport boundary — has no automated test. The developer's own implementation notes (§4/§5, deviation 6) flag this as unexercised and as "the single highest-value item for manual verification." This is a core, non-trivial mapping (409 JSON body parsing, 422 error-bag flattening, network-error fallback) sitting on the critical data-safety path. | Extract the mapping (`httpResponse`/`errors` → `SaveOutcome`) into a small pure function (mirroring `noteSaver.ts`'s framework-free style) so it can be unit-tested with mocked `httpResponse`/`errors` shapes, without needing a full Vue/HTTP test harness. Until then, this relies entirely on manual checks M1/M3/M4/M9. | 0 |
| QA-03 | Low | MINOR | `resources/js/components/editor/NoteEditor.vue` (mode-toggle buttons in the header, lines 331–352; `switchMode`, lines 249–273) | The Rich/Source toggle buttons are rendered and clickable even while `assessing` is `true` (the fidelity check hasn't set the real mode/assessment yet). If `switchMode('source')` runs before `onMounted`'s assessment logic has completed, `readContent()` (mode still `'rich'` at that point) reads from `tiptapRef`, which is `null` because the `TiptapEditor` isn't mounted yet (`v-if="assessing"` gates it out) — `sourceText` would be set to `''`, clobbering the note's real content in the Source editor. In practice this is very hard to trigger: `assessMarkdown()` runs synchronously inside `onMounted`, so the JS thread cannot yield to a user click between "assessing = true" and "assessing = false." This makes the window effectively unreachable in a real browser, but it is a latent bug if that computation is ever made async (e.g. moved to a worker) or takes long enough for a scripted/automated click. | Guard `switchMode`/the toggle buttons with `:disabled="assessing"` (or an early `if (assessing.value) return;` in `switchMode`) for defense in depth. | 0 |

**Severity**
- **Critical**: security hole, data loss/corruption, app crash, core requirement missing.
- **High**: requirement not met, broken behaviour on a main path, missing authorization/validation.
- **Medium**: edge case bug, missing test coverage, convention violation with real impact.
- **Low**: style, naming, minor clean-up.

**Classification (routing)**
- **MINOR** → Developer fixes directly.
- **MODERATE** → Developer fixes within the existing design (the plan/ADRs already describe the correct behaviour; this is an implementation gap, not a design gap).
- **MAJOR** → System Analyst replans.

None of the above reach the 3-fix-attempt circuit breaker (all are round-1, fix attempts 0).

---

## 4. Code Review Notes

- **Security & Authorization**: no auth by design (ADR `local-app-without-authentication`); `SaveNoteContentRequest::authorize()` returns `true` consistently with existing controllers. CSRF applies via `routes/web.php` → `web` middleware group (unchanged convention). No `id`/`vault_id` leak in JSON (verified by `NoteContentTest` and by reading `NoteSaveResult::toArray()`/the 409 payload, both of which only ever expose hash/size/reason fields). The Link extension uses Tiptap's default protocol allow-list (no explicit widening), rejecting `javascript:` as verified by `toolbarCommands.test.ts`.
- **Validation & Data Integrity**: `SaveNoteContentRequest` mirrors `NoteService::EDIT_LIMIT` for the pre-check; `NoteService::save` independently re-checks size after `encode()` and re-checks the base hash both before *and* immediately before the rename (`replaceFile`'s `beforeReplace` guard), exactly matching ADR `note-save-atomic-replace`. `replaceFile` never touches the target file on any failure path (verified by dedicated tests asserting the target survives `GuardFailed`/`WriteFailed`/`ReplaceFailed`). `discardTempFile` is private and only ever unlinks `.mdvault-save-*` names. A DB failure after a successful replace is reported and never compensated (file wins), matching Rule 9 — confirmed by test and by manual code read. **The one genuine data-integrity gap found is QA-01** (client-side, not server-side): the server-side save pipeline itself is sound; the bug is entirely in the client's autosave/flush sequencing.
- **Performance (N+1, indexes)**: no new Eloquent queries of concern; `preview()`/`save()` do a bounded number of file reads/hashes per request. No N+1 introduced.
- **Conventions (AGENTS.md, `.ai/rules/`)**: `FileStorageService::replaceFile` is confirmed (by grep and by `ArchitectureTest`) as the sole file-replacing call; `unlink`/`file_put_contents`/`fsync` are confined to `FileStorageService`. `MarkdownService` is pure-string (added to the "no raw filesystem" architecture rule). No AI attribution anywhere in the diff (only `CLAUDE.md`/`plan.md` quoting the rule itself, as expected).
- **Frontend (Inertia/Vue/Wayfinder)**: every new/reworked component (`NoteEditor.vue`, `TiptapEditor.vue`, `EditorToolbar.vue`, `LinkDialog.vue`, `SourceEditor.vue`, `NoteConflictAlert.vue`, `UnsavedChangesDialog.vue`) has a single root element. `updateContent`/`reindex`/`move` all go through Wayfinder-generated helpers (`@/routes/notes/content`, `@/routes/vaults`, `@/routes/notes`), no hardcoded URLs. No `v-html` anywhere. `lib/markdown`/`lib/editor` use only relative imports (verified by grep), keeping them test-harness-friendly. `useEditorTick.ts` is a reasonable, well-documented workaround for `useEditor()`'s lack of reactive transaction notification.
- **The bare-URL link override** (`applyMarkdownOverrides`, a whole-string regex pass) has the documented, accepted caveat that a fenced code block whose literal text reads `[https://x](https://x)` would also be rewritten. This is disclosed, has a fixture for the intended case, and is a narrow enough edge case to accept as a documented trade-off rather than a defect.

---

## 5. Deviations Assessment (implementation.md §4)

1. **No `happy-dom`, blank-input special-cased in `converter.ts`** — reasonable; the special-casing is narrow (only blank/whitespace-only input) and doesn't affect any fixture's correctness.
2. **Bare-URL link override as a string post-pass instead of a mark-level `renderMarkdown` override** — reasonable and well-justified (the placeholder-substitution constraint in `@tiptap/markdown` is real); caveat accepted (see §4 above).
3. **`exact/emphasis.md` drops the literal-asterisk line** — reasonable; the escaping behavior is a genuine, unconditional upstream behavior, not something the fixture can route around, and FR-01/FR-18 are still fully covered by what remains.
4. **`assess.test.ts`'s wiki-link-inside-exact-note test uses a stub converter** — reasonable; the real, reachable case is covered elsewhere, and the stub correctly isolates the ordering guarantee the ADR requires.
5. **`noteSaver.ts`'s reschedule uses a private `markDirtyAndSchedule()` helper, bypassing the in-flight check** — this is the fix for the *pure-autosave* re-entrancy case, and it works correctly for that case (confirmed by test). However, as detailed in QA-01, it does **not** fully close the loop for explicit `flush()` callers (the navigation guard, Ctrl/Cmd+S, mode switch) racing against an in-flight save — the underlying issue this deviation was meant to address is only half-fixed.
6. **`useHttp` shape resolved by reading shipped `.d.ts` files, with no automated coverage** — see QA-02.
7. **Stray unused `Badge` import removed** — no concern.

---

## 6. Manual Desktop Checks (M1–M13)

Not run in this session (no native shell/browser available to QA, consistent with every prior phase). These are the plan's own user-verified checks, **not** treated as defects:

- **M1**: VS Code-authored note → rich + editable, or a reformat notice.
- **M2**: type, wait 2 s → external tool shows the change; footer hash updates; closing untyped leaves mtime unchanged.
- **M3**: Ctrl+S saves immediately, status shows "Saved".
- **M4**: external edit while typing → conflict banner; Reload and Keep-mine both tested.
- **M5**: a table note opens in Source; a one-line edit produces a one-line diff externally.
- **M6**: CRLF + BOM Notepad file stays CRLF + BOM after editing in both modes.
- **M7**: a frontmatter note edited in Rich mode keeps the frontmatter byte-identical.
- **M8**: type then switch notes (saves first); type then close the window (saves, then closes). **Given QA-01, this check should specifically try to trigger the race**: type, pause ~1.5 s so autosave fires, then within roughly a second type one more character and immediately click another note, and confirm the last character is not lost.
- **M9**: a read-only file in Explorer shows the read-only message and keeps the text; clearing read-only lets the save through.
- **M10**: a ~1.2 MB note is read-only; a ~900 KB note opens in about 1 s.
- **M11**: the Move dialog preselects the current folder (automated coverage exists via `VaultIndexServiceTest`; M11 is the desktop confirmation only).
- **M12**: each toolbar button, then close/reopen → formatting preserved.
- **M13**: `C++ and C++` round-trips unchanged after save and reopen (automated coverage exists via `exact/emphasis.md`; M13 is the desktop confirmation only).

---

## 7. Routing Recommendation

- [ ] PASS → move feature to `.ai/features/completed/`
- [x] **FAIL — MODERATE** → **Senior Developer**: QA-01 (Critical/MODERATE — must fix before this can pass; the fix is localized to `noteSaver.ts`'s `flush()`), QA-02 (Medium/MODERATE — recommended before completion, not itself blocking), QA-03 (Low/MINOR — recommended before completion, not itself blocking)
- [ ] FAIL — MAJOR → System Analyst

No issue requires System Analyst replanning: the plan and ADRs already correctly specify the intended `flush()` behaviour (§2/T8: "await [the in-flight save], then re-check content and resend if changed") — QA-01 is a gap between that specification and the implementation, not a design flaw.
