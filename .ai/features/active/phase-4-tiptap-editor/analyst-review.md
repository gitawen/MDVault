# Phase 4 Level 4 analyst review: REQUEST CHANGES

The design is implemented faithfully and QA's fixes are sound. But my review found **two High-severity defects that QA did not catch**. Both break the fidelity ADR's promise that a note is never rewritten without the user's consent. The fixes are local, so this is **MODERATE** routing (back to `senior-developer`, not a replan). While the developer is in that code, **R2-01 and R2-02 should also be fixed now**: both fixes are small.

**Sign-off is pre-authorised, on one condition:** QA round 3 passes the scope listed at the end. If it does, the orchestrator applies the edits in §4 and completes the feature without another analyst review.

## 1. New defects found in review

**A1 (High, MODERATE): an in-place mode switch builds the source text from the rich serialization. This loses frontmatter and writes an unconsented reformat.**
- **Where:** `resources/js/components/editor/NoteEditor.vue`, `switchMode()`, the in-place branch (reached when `writtenSinceMount` is false):
  ```ts
  if (next === 'source') { sourceText.value = readContent(); }   // mode is still 'rich' here
  ```
- **What goes wrong:**
  - `readContent()` returns the Tiptap body only: no frontmatter, already reformatted.
  - The saver keeps its rich baseline. So the first keystroke in Source mode saves `composeSource(body-without-frontmatter)`.
  - Result: the frontmatter is deleted from disk. For a `reformat` note, the "Edit as source" button, which the user picked specifically to avoid reformatting, writes the reformatted body anyway.
- **The reverse direction (Source → Rich in place) has a related problem.** `ensureSaver()` returns early, so the rich editor's `ready` baseline is ignored. The saver keeps the full source text as its baseline. A Ctrl+S then sends the rich serialization, even while a `reformat` note is still read-only and not yet accepted.
- **Fix spec** (replace the whole in-place branch):
  ```ts
  // flush() just returned 'clean' and nothing was written, so the props still equal the disk.
  saver?.dispose();
  saver = null;
  if (next === 'source') {
      sourceText.value = props.note.content ?? '';   // full file, incl. frontmatter, original syntax
      mode.value = 'source';
      ensureSaver(sourceText.value);
  } else {
      mode.value = 'rich';   // TiptapEditor must mount via v-if so its `ready` emit creates the new saver
  }
  ```
  Confirm that the Rich/Source editors are rendered with `v-if`/`v-else` (not `v-show`), so that a remount happens and `ready` fires.
- **Checks:** add manual checks **M14** and **M15** to plan T12:
  - **M14:** open a frontmatter note in Rich → Source → type one character → the frontmatter is byte-identical on disk.
  - **M15:** open a `reformat` note → "Edit as source" → type one character → the external diff shows only that character.

**A2 (High, MODERATE): the bare-URL override is applied by the converter but not by the visible editor.**
- **Where:** `resources/js/components/editor/TiptapEditor.vue`, lines 35 and 65–66. Both use a raw `editor.getMarkdown()`. Only `converter.ts` wraps it in `applyMarkdownOverrides()`.
- **What goes wrong:**
  - A note with bare URLs is assessed `exact`.
  - Its first real edit then rewrites every bare URL in the note as `[url](url)`, without consent.
  - Every note saved that way reopens as `reformat`. MDVault's own output then fails its own fidelity check.
- **Fix spec:**
  1. In `resources/js/lib/markdown/extensions.ts`, add:
     ```ts
     export function serializeEditor(editor: Editor): string { return applyMarkdownOverrides(editor.getMarkdown()); }
     ```
  2. Use `serializeEditor` in `converter.ts` (both call sites) and in `TiptapEditor.vue` (the `onCreate` emit and `getMarkdown()`).
  3. Add a test to `tests/js/markdown/roundTrip.test.ts`: a headless editor built exactly like the visible one, loaded with `exact/links.md`; append a paragraph with `insertContentAt(end, …)`; assert that `serializeEditor()` still contains the bare-URL line unchanged.
  4. New T12 grep: `rg -n "\.getMarkdown\(\)" resources/js` must match only inside `serializeEditor`.

## 2. R2-01 and R2-02

**R2-01: fix now.** A keystroke typed during the replayed navigation's own round trip is lost when the editor unmounts. That sits directly against FR-17 ("no lost edits").
- **Do not** add a "flush on unmount". After unmount, `tiptapRef` is null, so `readContent()` returns `''` and the flush would save an empty note.
- **Fix spec: freeze the editor for the duration of any navigation the guard lets through.**
  - `useUnsavedChangesGuard` options gain `freeze: () => void` and `unfreeze: () => void`.
  - In `handleBefore`:
    - on the clean path (not dirty), call `options.freeze()` before returning;
    - on the saved/clean path after `flush()`, call `options.freeze()` immediately before `replay(visit)`;
    - on `discardAndContinue`, call `options.freeze()` before `replay`.
  - Register `router.on('finish', () => options.unfreeze())` (unsubscribe on unmount). Visits that keep this `NoteEditor` mounted must unfreeze: re-index, partial reloads of the same note, and cancelled visits.
  - `NoteEditor.vue`:
    - add `const frozen = ref(false)`;
    - pass `:editable="!richReadOnly && !frozen"` to `TiptapEditor`;
    - `SourceEditor` gains a `readonly` prop, bound to `frozen` (textarea `readonly`);
    - `freeze` also calls `flush()` if `isDirty()`. This belt-and-braces call covers the synchronous gap between `flush` resolving and `freeze`.
  - Add manual check **M16**: click another note and keep typing → no characters are accepted after the click, and the first note's file holds everything typed before the click.

**R2-02: fix the dispose half now; defer the iteration cap.**
- In `noteSaver.ts` → `runFlushLoop()`, at the top of each iteration add `if (disposed) { return 'failed'; }`.
- Add one test to `noteSaver.test.ts`: `dispose()` during an in-flight send → after the send resolves, no further send happens even though the content changed.
- No iteration or time cap is needed. The loop only continues while the content changes, which is user-driven, and serialization is deterministic. Record this as accepted in the fidelity ADR addendum below.

## 3. The developer's deviations (§4 of implementation.md)

| # | Deviation | Verdict |
|---|---|---|
| 1 | No `happy-dom`; blank input special-cased in `converter.ts` | Accept. F10b is correctly unused. |
| 2 | `applyMarkdownOverrides()` as a string pass, not a mark hook | Accept the mechanism: the placeholder limitation is real. It **must** be applied on every serialization path (A2). The fenced-code caveat is acceptable. For existing notes the fidelity check already contains it: a body with `[https://x](https://x)` inside a code block round-trips to a different code text, fails doc-equality, and is classified `unsupported('unstable')` (Source mode). The residual risk is only newly typed text inside a code block. Record it in the addendum. |
| 3 | Literal `*` / `[` / `]` always escaped; `a * b` dropped from `exact/emphasis.md` | Accept. This matches the ADR, because escaping is document-equal, so these notes are `reformat` (consent required). Consequence: the footnote and wiki-link checks (step 3) are effectively always active. Record it in the addendum. |
| 4 | "Wiki link inside an exact note" tested with a stub converter | Accept. The real case is unreachable (see #3), and the ordering itself is still tested. |
| 5 | `markDirtyAndSchedule()` re-entrancy fix | Superseded by Fix Round 1's flush loop. Accept. |
| 6 | Assessment and initial mode resolved in `onMounted` | Accept. Neither editor mounts while `assessing` is true. |
| 7 | `useHttp` contract read from `.d.ts`; mapping extracted to `saveTransport.ts` | Accept. `send()` creates a `useHttp` per call outside `setup`: acceptable, and exercised end-to-end by M3, M4 and M9. The unplanned `lib/editor/useEditorTick.ts` is also accepted. |

**The fidelity ADR needs an addendum** (text in §4).

## 4. Line-level edits, applied by the orchestrator after QA round 3 passes

`C:\Users\cherw\Herd\MDVault\.ai\decisions\markdown-conversion-and-fidelity.md`
- Replace the line `- **Status**: Proposed (Phase 4 plan; pending user approvals F1, F2, F3, F10)` with:
  `- **Status**: Accepted (F1, F2, F3, F10 approved; delivered in Phase 4, 2026-09-29)`
- Append at the end of the file:
  ```markdown

  ## Addendum (Phase 4 delivery, 2026-09-29)
  - **Canonical style** emitted by `@tiptap/markdown` 3.31.3: `-` bullets, `*em*`, `**strong**`, `~~strike~~`, 2-space nesting, ordered lists keep their start number, `- [ ]`/`- [x]`, backtick fences with the language kept, `---`, ATX headings only, runs of blank lines collapse to one, two-space hard breaks kept. Other syntax is `reformat`.
  - **Escaping**: literal `*`, `[` and `]` in text are always backslash-escaped. Notes containing them are `reformat` (document-equal), and wiki links and footnotes can never be `exact`, so check 3 is effectively always applied.
  - **Serializer override mechanism**: overrides are a string pass (`applyMarkdownOverrides`) over the finished Markdown, because mark-level `renderMarkdown` only sees a placeholder. Every serialization, in the visible editor and the headless converter alike, goes through `serializeEditor()`. Known limitation: the bare-URL pass also rewrites the literal text `[https://x](https://x)` inside code; existing notes with it fail doc-equality and open in Source mode.
  - **Flush loop**: `noteSaver.flush()` re-sends until the content matches the last save. It is bounded by user input, not by an iteration cap, and a disposed saver stops the loop.
  ```

`C:\Users\cherw\Herd\MDVault\.ai\decisions\note-save-atomic-replace.md`
- Replace the line `- **Status**: Proposed (Phase 4 plan; pending user approvals F4, F5, F6)` with:
  `- **Status**: Accepted (F4, F5, F6 approved; delivered in Phase 4, 2026-09-29)`
- In **Decision → Client**, append this bullet:
  `  - While a guarded navigation is in progress the editor is read-only (frozen until the visit finishes), so no keystroke can land after the final flush. A mode switch re-baselines the saver from the pristine props (full file for Source), never from the other mode's serialization.`

`C:\Users\cherw\Herd\MDVault\.ai\features\active\phase-4-tiptap-editor\plan.md`
- Replace the line `- **Status**: BLOCKED (awaiting approvals F1–F11 in §6)` with:
  `- **Status**: COMPLETE (F1–F11 approved; QA round 3 PASS; Level 4 analyst sign-off 2026-09-29)`
- Add to §3 T12, after M13:
  `    - **M14**: Frontmatter note, Rich → Source, type one character → frontmatter byte-identical.`
  `    - **M15**: A reformat note, "Edit as source", type one character → the external diff shows only that character.`
  `    - **M16**: Click another note while typing → no input is accepted after the click, and everything typed before it is saved.`
- Add to §7 Revision Log, after the Revision 1 row:
  `| 2 | 2026-09-29 | Level 4 analyst review | Fix round 2: A1 (in-place mode switch re-baselines from props), A2 (serializeEditor on all serialization paths), R2-01 (editor frozen during guarded navigation), R2-02 (dispose stops the flush loop; no iteration cap); M14–M16 added; ADR addendum. |`

Also tick the §6 approval boxes F1–F11 (`- [ ]` → `- [x]`) if they weren't ticked in T0.

## 5. Routing

- **Next:** `senior-developer`, fix round 2. Issues A1, A2 and R2-01 are fix attempt 1 each; R2-02 is attempt 1. Everything goes on branch `phase-4-tiptap-editor`, with no AI attribution anywhere.
- **Then QA round 3.** Scope:
  - `npm run test:js`
  - `php artisan test --compact tests/Feature/Notes tests/Feature/Services/NoteServiceTest.php`
  - `npm run types:check`
  - `npm run check`
  - `npm run build`
  - the new `getMarkdown()` grep
  - a code review of `switchMode()`, `useUnsavedChangesGuard` (freeze/unfreeze, `finish` unsubscribe) and `runFlushLoop()`

  M14–M16 are manual checks for the user.
- **If QA round 3 passes:** apply §4, move the folder to `.ai/features/completed/`, and ask the user to run `php artisan test --compact` and M1–M16.
