# Plan: Retire TipTap and the Rich/Source Editor Bifurcation

## Metadata
- **Feature Name**: Retire TipTap and the Rich/Source Editor Bifurcation
- **Feature ID**: feat-retire-tiptap
- **Author**: System Analyst
- **Created Date**: 2026-10-07
- **Task Complexity**: Level 3 — Complex Development
- **Master Plan Phase**: Phase 4 — Editor (`docs/Masterplan.md` §53; §20, §21, §41 also amended by T12)
- **Requirements**: `requirements.md`
- **Status**: **UNBLOCKED — approved to implement.** User answers, 2026-10-07: **P1 satisfied** (`codemirror-ui-fixes` walkthrough confirmed working; folder moved to `.ai/features/completed/codemirror-ui-fixes/`). **Q2 yes** (remove the 11 dependencies). **Q3 yes** (delete the TipTap test corpus). **Q4 yes** (amend `docs/Masterplan.md`). **Q5 yes** (accept the four capability deltas, no WYSIWYG). **Q6 yes** (rename `SourceEditor.vue` → `MarkdownEditor.vue`; T11 proceeds). **Q7**: not asked separately — the plan's own recommendation (defer the backend `NoteSaveMode::Rich` removal to a follow-up feature) stands; T1–T13 all proceed.

---

## 1. Summary

Execute `.ai/decisions/codemirror-unified-editor.md` §Decision-1, which the feature that nominally implemented it did not carry out: delete TipTap, the rich/source mode bifurcation, the Markdown↔ProseMirror fidelity machinery (`assessMarkdown`, the converter, the reformat-consent flow, the unsupported-Markdown banner) and the rich-mode frontmatter panel, leaving the CodeMirror 6 editor as MDVault's only editor. Collapse every shared editor module to a single code path, remove eleven now-unused npm packages, rename the TipTap-named CSS the CodeMirror preview pane borrows, close the one capability gap the ADR itself named by wiring the existing `insertTable` command to a toolbar button, and amend `docs/Masterplan.md` so the authoritative product spec stops naming an editor the codebase will not contain. Scope is `resources/js/**`, `tests/js/**`, `resources/css/app.css`, `package.json` and `docs/`; **no backend change, no migration**.

---

## 2. Architecture & Design

- **Approach**:
  - **Delete, do not abstract.** There is no second engine to keep a seam for. `toolbarCommands.ts` goes from `Record<ToolbarCommandId, ToolbarCommand>` with 17 `isTipTap(editor) ? … : …` ternaries to the same record with one implementation per command, targeting `CommandTarget` (`codemirrorCommands.ts:5-9`). `SupportedEditor` and `isTipTap` are deleted. `useEditorTick.ts` — which exists only for TipTap's `.on('transaction')` emitter and whose silent fallthrough was the root cause of `codemirror-ui-fixes` FR-01 — is deleted; `props.revision` from `SourceEditor.vue`'s `onUpdate` is already the only live reactivity source and becomes the sole one.
  - **Widen the command target from `EditorView` to `CommandTarget`** where `vue-tsc` allows it. `@codemirror/commands`' `undo`/`redo` are `StateCommand`s, so `executeUndo`/`executeRedo` (`codemirrorCommands.ts:501-507`) can take `CommandTarget` like every other command in the file. This removes the `command.run(target as any)` casts in `toolbarCommands.test.ts:153,173` — and `as any` on an editor binding is precisely what hid `codemirror-ui-fixes` FR-08 from `vue-tsc`. **If `vue-tsc` objects, stop and keep `EditorView` plus the test casts** (T3 note); do not add new `any` to force it.
  - **`NoteEditor.vue` keeps every non-mode behaviour byte-identical.** The saver, save epoch, conflict handling, copy, compare, freeze/unfreeze, external-change reconciliation and the unsaved-changes guard are not redesigned — only their rich branch is deleted. Each deleted branch is enumerated in T5 so the diff is auditable line by line.
  - **Frontmatter becomes document text.** This is not a feature removal that needs a replacement; it is the removal of a second representation of data that already lives in the file. The CodeMirror live-preview plugin already excludes a leading frontmatter block from decoration and `markdownPreview.stripFrontmatter()` already removes it from the rendered preview (`codemirror-ui-fixes` FR-11/FR-19), so frontmatter already displays correctly inside the one editor. `MarkdownService::renderNewNoteTemplate()` and the `editor.new_note_template` setting are untouched: they write frontmatter into the file source, which is now the only representation.
  - **Backend left alone deliberately.** The client keeps posting `mode: 'source'`. `NoteSaveMode::Rich`, `MarkdownService::composeRich()`, `App\Support\FrontmatterEdit`, `InteractsWithNoteContent::frontmatterEdit()` and the `has_frontmatter`/`frontmatter` request fields become unreachable but keep working and keep their ~25 Pest tests green. See Q7.
- **Alternatives Considered**:
  - *Keep TipTap behind a hidden flag / "legacy editor" escape hatch* — rejected. It preserves the dual-branch cost in every shared module (the exact cost this feature exists to remove) and preserves a path that can rewrite the user's file, while giving a fallback nobody discovers. The right fallback for a CodeMirror defect is a git revert of this feature, which P1 and the task ordering keep cheap.
  - *Remove the backend Rich save mode in the same pass* — rejected for this feature (Q7). Zero user-visible change, ~25 Pest tests to rework across four files, and it threads through the encrypted save/copy path. Removing it is a follow-up; leaving it does not block anything.
  - *Keep `assessMarkdown` as a general "unusual Markdown" linter* — rejected. Its entire definition of "unusual" is "my ProseMirror schema cannot hold this" (`assess.ts:51-102`): images, HTML, reference links, footnotes, wiki links. With no schema, the classification is meaningless, and all five of those now open fine.
  - *Rename `.tiptap-content` with a CSS alias (`.tiptap-content, .markdown-content { … }`)* — rejected. It leaves a TipTap identifier in the shipped CSS and in search results forever for no benefit; there is exactly one consumer to update.
  - *Add `jsdom` + `@vue/test-utils` so the removal can be verified automatically* — rejected: two new dev dependencies and a `vite.config.ts` test-environment change, already declined in `codemirror-ui-fixes` Q1. The gap is handled by P1 and the T13 walkthrough instead.
- **Decision Records**:
  - Executes `.ai/decisions/codemirror-unified-editor.md` §Decision-1 (in force).
  - `.ai/decisions/codemirror-markdown-editor.md` §Decision-1 (the 150 KB `assessMarkdown` bypass) and §Decision-2's "Preserves WYSIWYG for Normal Files" rationale are **retired by this feature** — there is nothing left to bypass. Recorded in the new ADR, not edited in place.
  - `.ai/decisions/markdown-conversion-and-fidelity.md` → Status **Superseded** (T12). It was already named as replaced by the CodeMirror ADR.
  - **New**: `.ai/decisions/retire-tiptap-rich-mode.md` (supplied with this plan) — records the four accepted capability deltas, the fidelity-corpus deletion, the backend deferral and the Master Plan amendment.

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| — | **none** | No migration. The editor mode was never persisted: `editorSession.ts` is a module-level `Map`, `App\Enums\SettingKey` has no mode key, and no `notes`/`settings` column holds one (FR-05, verified). No new table, no sync infrastructure, no new base folder. |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| — | — | **No PHP file changes.** `NoteService`, `EncryptedNoteService`, `MarkdownService`, `NoteContentController`, `NoteCopyController` and both Form Requests are untouched; the client keeps sending `mode: 'source'`, which they already handle. `vendor/bin/pint` should not need to run — if it does, the developer went outside scope. |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Component (**delete**) | `resources/js/components/editor/TiptapEditor.vue` | The rich editor |
| Component (**delete**) | `resources/js/components/editor/FrontmatterPanel.vue` | Rich-mode-only YAML panel |
| Library (**delete**) | `resources/js/lib/markdown/extensions.ts` | TipTap extension list + `serializeEditor` |
| Library (**delete**) | `resources/js/lib/markdown/converter.ts` | Headless Markdown↔TipTap converter |
| Library (**delete**) | `resources/js/lib/markdown/assess.ts` | `assessMarkdown`, `UNSUPPORTED_LABELS` |
| Library (**delete**) | `resources/js/lib/editor/richContent.ts` | Rich save payload encode/decode, `frontmatterProblem` |
| Library (**delete**) | `resources/js/lib/editor/editorSession.ts` | Per-note mode + reformat consent |
| Library (**delete**) | `resources/js/lib/editor/useEditorTick.ts` | TipTap `transaction` subscription |
| Library (**delete**) | `resources/js/lib/editor/largeNote.ts` | 150 KB `assessMarkdown` bypass threshold |
| Directory (**remove**) | `resources/js/lib/markdown/` | Empty after the three deletions above |
| Library (modify) | `resources/js/lib/editor/toolbarCommands.ts` | Single-target command table; new `table` command |
| Library (modify) | `resources/js/lib/editor/codemirrorCommands.ts` | `insertTable` insert-after-line fix; `executeUndo`/`executeRedo` widened to `CommandTarget` |
| Component (modify) | `resources/js/components/editor/NoteEditor.vue` | One editor, no mode state, single-branch save/copy/compare |
| Component (modify, **rename** — Q6) | `resources/js/components/editor/SourceEditor.vue` → `MarkdownEditor.vue` | The one editor |
| Component (modify) | `resources/js/components/editor/EditorToolbar.vue` | `view` + `revision` only; no `editor` prop, no `tick`; Table button |
| Component (modify) | `resources/js/components/editor/LinkDialog.vue` | CodeMirror-only `apply()`/`remove()` |
| Stylesheet (modify) | `resources/css/app.css` | `.tiptap-content` → `.markdown-content`; drop TipTap-only selectors |
| Types (modify) | `resources/js/types/notes.ts` | Comment `body`/`frontmatter`/`frontmatter_yaml` as server-side-only, pending Q7 |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| — | — | — | — | **No route change.** No `wayfinder:generate` run is needed; the already-modified generated files in the working tree are from prior work. |

---

## 3. Implementation Tasks

*Ordered: pure logic → command table → component collapse → deletion → dependencies → docs → verification. Each task leaves the application working and is independently revertible. Run `npm run check:fix` and `npm run types:check` after every task. Do not run `vendor/bin/pint` — no PHP file should change.*

**Before T1**: confirm P1 is satisfied (`.ai/features/active/codemirror-ui-fixes/` has been moved to `.ai/features/completed/` after the user's walkthrough) and that Q2–Q5 are answered. Work on a feature branch; never commit to `main`.

- [ ] **T1 — Fix `insertTable` so it cannot destroy a selection, then add a `table` toolbar command** *(gated on Q5's accept answer)*
  - Files: `resources/js/lib/editor/codemirrorCommands.ts`, `resources/js/lib/editor/toolbarCommands.ts`, `tests/js/editor/codemirrorCommands.test.ts`
  - Command: `npx vitest run tests/js/editor/codemirrorCommands.test.ts`
  - Details:
    - `insertTable` (`:274-289`) currently dispatches `changes: { from, to, insert: tableTemplate }`, replacing the selection — clicking Table with text selected deletes it. Rewrite it on `insertHorizontalRule`'s pattern (`:297+`): insert after `state.doc.lineAt(selection.main.to).to`, normalise the separating blank line the same way (one blank line, never doubled), insert exactly once regardless of selection size, never modify the current line's text, and set the selection over `Header 1` so it can be typed over. Keep the template GFM-shaped: `| Header 1 | Header 2 |` / `| --- | --- |` / `| Cell 1 | Cell 2 |`.
    - Add `'table'` to `ToolbarCommandId` and a `table` entry to `toolbarCommands` (`label: 'Table'`, `shortcut: null`, `run: insertTable`, `isActive: () => false`, `canRun: () => true`). Keep it next to `horizontalRule`.
    - Tests: `insertTable` with an empty selection on `- item`, with a multi-line selection (selection preserved, one table inserted), at end of document, and the resulting selection range.
  - Covers: FR-11

- [ ] **T2 — Collapse the toolbar command table to a single target**
  - Files: `resources/js/lib/editor/toolbarCommands.ts`, `resources/js/lib/editor/codemirrorCommands.ts`
  - Details:
    - Delete `import type { Editor } from '@tiptap/core'`, the `SupportedEditor` union and `export function isTipTap`. Replace the type throughout with `CommandTarget` (imported from `codemirrorCommands`) if T2's type widening below holds, otherwise `EditorView`.
    - Rewrite all 18 entries (17 existing + `table`) to the CodeMirror implementation only — delete every `isTipTap(editor) ? … : …` ternary. `undo`/`redo` keep `undoDepth`/`redoDepth` for `canRun`; `bold`/`italic`/`strike`/`code` keep `isInlineMarkActive`; `h1`–`h3`/lists/`taskList`/`blockquote` keep `isLinePrefixActive`; `paragraph`/`codeBlock`/`horizontalRule`/`clearFormatting`/`table` keep `isActive: () => false`. Replace the `Boolean(editor)` `canRun`s (a TipTap-era artefact) with `() => true`.
    - Widen `executeUndo`/`executeRedo` (`codemirrorCommands.ts:501-507`) from `EditorView` to `CommandTarget` (`@codemirror/commands`' `undo`/`redo` are `StateCommand`s). **If `vue-tsc` rejects this, revert both signatures to `EditorView`, keep `SupportedEditor = EditorView` in `toolbarCommands.ts`, keep the existing `as any` casts in the test, and record it as a deviation.** Do not add `any` to the production code to force the widening.
    - Keep the file's doc comment, updating the "supporting both CodeMirror 6 and TipTap" line.
  - Covers: FR-08

- [ ] **T3 — Rework `toolbarCommands.test.ts`: delete the TipTap suite, keep and extend the CodeMirror suite** *(gated on Q3)*
  - Files: `tests/js/editor/toolbarCommands.test.ts`
  - Command: `npx vitest run tests/js/editor/toolbarCommands.test.ts`
  - Details: Delete lines 1–98 — the `@tiptap/core` and `markdownExtensions` imports, `expectedMarkdown`, `editorWithSelectedText()`, the `describe('toolbarCommands')` loop and the two TipTap link tests (`setLink serialises a link`, `rejects an unsafe protocol`). **Move the editor-agnostic `it('has no link command in the table …')` into the surviving suite** — it must not be lost. Keep `describe('toolbarCommands (CodeMirror branch)')` (`:106-183`) and rename it to `describe('toolbarCommands')`. Add `table: 'para text\n\n| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |'` to `expectedCodeMirrorMarkdown` (adjust to T1's exact blank-line normalisation). Drop the `as any` casts if T2's widening landed. **The 18 table-driven assertions must pass with no change to their expected strings for the 17 pre-existing commands — this is the regression net for T2.**
  - Covers: FR-08, FR-11

- [ ] **T4 — Single code path in `EditorToolbar.vue` and `LinkDialog.vue`**
  - Files: `resources/js/components/editor/EditorToolbar.vue`, `resources/js/components/editor/LinkDialog.vue`
  - Details:
    - `EditorToolbar.vue`: delete the `editor?: SupportedEditor` prop, the `activeEditor` computed (`:63-65`), the `useEditorTick` call and `tick` (`:69-73`), and both `void tick.value` reads (`:76,86`) — `props.view` plus `props.revision` are the entire input surface. `isActive`/`canRun`/`run` read `props.view` directly. `currentHref()` (`:148-157`) keeps only the `linkAt(view.state, …)` path. Add the Table button: a `Table` icon from `@lucide/vue`, an entry in `overflowCommandIcons`, and `'table'` appended to `overflowCommands` so it is reachable below `md` like the other secondary commands. Keep `defineExpose({ openLinkDialog })` (the `Mod-k` relay) and the `v-model:display-mode` switcher untouched.
    - `LinkDialog.vue`: delete `import type { Editor } from '@tiptap/core'`, the `editor?: Editor | EditorView` prop, the `isTipTap` import and the `isTipTap(active)` branches of `apply()` (`:57-72`) and `remove()` (`:106-108`). `props.view` is the only editor input. **The `isAllowedHref` gate and the `error` message must survive unchanged** — it is the FR-09 security fix from the previous pass.
    - Update `EditorToolbar.vue`'s `<LinkDialog>` binding to pass only `:view` and `:initial-href`.
  - Covers: FR-08, FR-11

- [ ] **T5 — Collapse `NoteEditor.vue` to one editor**
  - Files: `resources/js/components/editor/NoteEditor.vue`
  - Details: *(every removal enumerated so the diff is auditable)*
    - **Imports deleted**: `FrontmatterPanel`, `TiptapEditor`, `* as editorSession`, `isLargeNote as checkIsLargeNote`, all four of `decodeRichContent`/`encodeRichContent`/`richContentAsText`/`richSavePayload`, `assessMarkdown`/`UNSUPPORTED_LABELS`/`MarkdownAssessment`, `getMarkdownConverter`, and the now-unused `Code2` and `FileText` icons.
    - **State and functions deleted**: `isLargeNote`, `assessment`, `needsAssessment`, `assessing`, `reformatAccepted`, `acceptReformat()`, `type Mode`, `mode`, `writtenSinceMount`, `richReadOnly`, `tiptapRef`, `frontmatterState`, `onEditorReady()`, `switchMode()`.
    - **Collapsed**: `readContent()` → `editorRef.value?.getText() ?? ''`. `SavePayload` → `{ content: string; base_hash: string; mode: 'source' }`. `send()` → the single source payload. `CopyPayload`/`saveAsNewNote()` → the single source payload. `conflictCopy()` and `openCompare()` → `readContent()`. The two `onMounted` blocks merge into one that calls `ensureSaver(props.note.content ?? '')` and registers the `keydown` listener.
    - **Kept exactly as-is**: `saveState`, the save `epoch` and `externalCheckToken()`, `ensureSaver`/`notifyChange`/`flush`/`isDirty`/`discard`, `onKeydown` (Ctrl+S), `frozen`/`freeze()`/`unfreeze()`, `conflictReload`/`conflictOverwrite`, `applyExternalStatus()` including the `if (!saver)` `conflict-changed` branch, `localSnapshot()`, `discardMissing`/`checkAgain`/`refresh`/`runReindex`, `defineExpose`, `useUnsavedChangesGuard`, `statusLabel`, `formatBytes`.
    - **Template**: delete the mode segmented control (`:649-693`), the `<p v-if="assessing">` line (`:799-801`) **and its `<template v-else>` wrapper** (`:803`, closing `:873`), the large-note alert (`:804-809`), the unsupported alert (`:811-820`), the reformat consent alert (`:822-846`), `<FrontmatterPanel>` (`:848-853`) and `<TiptapEditor>` (`:855-863`). The editor renders directly inside the `v-else-if="note.editable"` branch with `v-else` dropped from it. Keep `NoteConflictAlert`, the `saveState.status === 'error'` alert, the `missing`/`too_large`/`unreadable`/`invalid_utf8` branches, the status badge, the Save button, the footer, `UnsavedChangesDialog` and `NoteCompareDialog`. Single root element preserved.
    - Rename `sourceRef` → `editorRef` and `sourceText` → `noteText` for clarity.
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-06, FR-12

- [ ] **T6 — Delete the TipTap modules and components** *(gated on Q2 for the npm step in T9; the file deletions are not gated)*
  - Files (delete): `resources/js/components/editor/TiptapEditor.vue`, `resources/js/components/editor/FrontmatterPanel.vue`, `resources/js/lib/markdown/extensions.ts`, `resources/js/lib/markdown/converter.ts`, `resources/js/lib/markdown/assess.ts`, `resources/js/lib/editor/richContent.ts`, `resources/js/lib/editor/editorSession.ts`, `resources/js/lib/editor/useEditorTick.ts`, `resources/js/lib/editor/largeNote.ts`, and the now-empty `resources/js/lib/markdown/` directory.
  - Details: Delete in this order and run `npm run types:check` after each, so any surviving importer surfaces immediately. `npm run types:check` must report 0 errors and `npm run build` must succeed at the end. Confirm with `grep -rn "@tiptap\|isTipTap\|useEditorTick\|assessMarkdown\|richContent\|editorSession\|largeNote\|FrontmatterPanel\|TiptapEditor" resources/js` returning nothing.
  - Covers: FR-02, FR-03, FR-05, FR-06, FR-07, FR-08

- [ ] **T7 — Delete the TipTap test corpus and replace its two non-TipTap behaviours** *(gated on Q3)*
  - Files (delete): `tests/js/markdown/spike.test.ts`, `tests/js/markdown/assess.test.ts`, `tests/js/markdown/roundTrip.test.ts`, the whole `tests/js/fixtures/markdown/` tree (28 files), `tests/js/editor/richContent.test.ts`, `tests/js/editor/tabIndent.test.ts`, `tests/js/editor/largeNoteThreshold.test.ts`, and the now-empty `tests/js/markdown/` and `tests/js/fixtures/` directories.
  - Files (modify): `tests/js/editor/codemirror.test.ts`
  - Command: `npx vitest run tests/js/editor/codemirror.test.ts`
  - Details: `tabIndent.test.ts` covered one thing that is *not* TipTap-specific — that the `indent_size` preference drives Tab indentation. Replace it with an assertion in `codemirror.test.ts` that `buildCodeMirrorExtensions({ preferences: { indent_size: 2 } })` yields an `EditorState` whose `indentUnit` facet is two spaces, and the same for `4`, plus that the built keymap contains a `Tab` binding (`state.facet(keymap)`, the pattern already used in that file for `Mod-b`/`Mod-i`/`Mod-k`). The second behaviour — "the toolbar table has no `link` command" — is preserved by T3. Everything else in the deleted files asserts the TipTap round trip and has no successor by design.
  - Covers: FR-07 (coverage disposition); the full per-file rationale is §4

- [ ] **T8 — Engine-neutral preview CSS**
  - Files: `resources/css/app.css`, `resources/js/components/editor/MarkdownEditor.vue` (or `SourceEditor.vue` if Q6 is declined)
  - Details: Rename every `.tiptap-content` selector in the `@layer components` block (`:186-372`) to `.markdown-content`. **Delete** three families that `marked` can never emit or that belonged to TipTap's `contenteditable` surface: the `min-h-48 outline-none` base rule (`:187-189`), all nine `ul[data-type='taskList'] …` rules (`:283-317` — `marked` emits `<li><input type="checkbox">`, already styled by `.markdown-preview-content` in the editor SFC's `<style>`), and `.tiptap-nowrap .tiptap-content` (`:369-372`, whose only consumer was `TiptapEditor.vue`). **Keep** everything else, including the `> :first-child`, heading, `p`, `a`, `strong`, `s`, `ul`/`ol`/`li`, `blockquote`, `code`, `pre`, `hr`, `img`, `table`/`th`/`td` rules and the em-relative heading sizes (`text-[2em]`/`[1.5em]`/`[1.25em]`) that `codemirror-ui-fixes` FR-15 matched the editor decorations against — changing them would desynchronise Split view. Update the editor's preview container class from `markdown-preview-content tiptap-content` to `markdown-preview-content markdown-content`. Verify `grep -rin tiptap resources/` returns nothing.
  - Covers: FR-10

- [ ] **T9 — Remove the unused dependencies** *(gated on **Q2**)*
  - Files: `package.json`, `package-lock.json`
  - Command: `npm install` (regenerates the lockfile), then `npm run build`
  - Details: Remove from `dependencies`: `@tiptap/starter-kit`, `@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/markdown`, `@tiptap/extension-list`, `@tiptap/extension-table`, `@tiptap/extension-table-cell`, `@tiptap/extension-table-header`, `@tiptap/extension-table-row`, plus `@codemirror/theme-one-dark` (unreferenced since `codemirror-ui-fixes` T8 replaced `oneDark` with `appEditorTheme`, and deliberately left installed then) and the `codemirror` meta-package (no `from 'codemirror'` import exists anywhere). **Keep** `marked` (`markdownPreview.ts`), `diff` (`NoteCompareDialog.vue`), `@vueuse/core` and all six scoped `@codemirror/*` packages that are imported. Note that the working tree already has uncommitted `package.json`/`package-lock.json` changes — rebase onto them, do not discard them. Record the `npm run build` bundle size before and after in `implementation.md`.
  - Covers: FR-09

- [ ] **T10 — A regression guard against TipTap returning**
  - Files: `tests/js/editor/noTiptapDependency.test.ts` (new)
  - Command: `npx vitest run tests/js/editor/noTiptapDependency.test.ts`
  - Details: A `node`-environment test using `node:fs`/`node:path` that (a) reads `package.json` and asserts no `dependencies`/`devDependencies`/`optionalDependencies` key starts with `@tiptap/` or equals `codemirror` or `@codemirror/theme-one-dark`, and (b) walks `resources/js/**/*.{ts,vue}` and `resources/css/app.css` asserting no file contains `@tiptap`, `isTipTap`, `useEditorTick` or `tiptap-`. This is the one part of the removal that *is* automatically verifiable, and it stops a future paste re-introducing the dependency. Keep it fast (single synchronous walk) and skip `node_modules`.
  - Covers: FR-07, FR-08, FR-09, FR-10

- [ ] **T11 — Rename `SourceEditor.vue` → `MarkdownEditor.vue`** *(gated on **Q6**)*
  - Files: `resources/js/components/editor/SourceEditor.vue` → `resources/js/components/editor/MarkdownEditor.vue`, `resources/js/components/editor/NoteEditor.vue`
  - Details: `git mv` the file (preserve history), update the single import and the `InstanceType<typeof …>` ref type in `NoteEditor.vue`, and update the "Unified EditorToolbar supporting CodeMirror" / "CodeMirror 6 Editor Pane" template comments to drop the engine name from user-adjacent copy while keeping it in code comments where it is accurate. No logic change, no prop change, no test change. If Q6 is declined, skip this task and leave the filename.
  - Covers: FR-01 (naming only)

- [ ] **T12 — Make the documents true** *(gated on **Q4**)*
  - Files: `docs/Masterplan.md`, `.ai/decisions/markdown-conversion-and-fidelity.md`, `.ai/decisions/retire-tiptap-rich-mode.md` (new, supplied with this plan)
  - Details:
    - `docs/Masterplan.md`: amend §20 (retitle "Tiptap Editor" → "Markdown Editor"; replace "Tiptap will provide the primary Markdown editing interface" with the CodeMirror 6 unified editor and a pointer to `.ai/decisions/codemirror-unified-editor.md`; **keep the toolbar list and "Only implement features that have a reliable Markdown representation" verbatim**, and keep Underline/Alignment's existing not-implemented status unchanged), §21 (the round-trip diagram's `Tiptap` node becomes the editor buffer; the point that round-trip integrity is critical stands and is now trivially satisfied because the editor *is* the Markdown), §53 (retitle "Phase 4 — Tiptap Editor"; keep every acceptance criterion as-is — they are all still true and all still tested), and the three summary mentions at `:11`, `:77`/`:91`/`:107`/`:137` and `:2001`/`:2041`/`:2321`/`:2455`/`:2539`. Mark each amended section with a one-line "Amended 2026-10-07 per ADR codemirror-unified-editor §Decision-1" note so the change is traceable rather than silent.
    - `.ai/decisions/markdown-conversion-and-fidelity.md`: Status → `Superseded by .ai/decisions/codemirror-unified-editor.md (2026-10-07)`. Change nothing else — it is the historical record of why the converter existed.
    - Save the new ADR `.ai/decisions/retire-tiptap-rich-mode.md`.
  - Covers: FR-13

- [ ] **T13 — Verification and manual UI walkthrough**
  - Commands: `npm run test:js`, `npm run types:check`, `npm run check`, `npm run build`, `php artisan test --compact tests/Feature/Notes/`
  - Details: Run the whole JS suite, the type check, the linter (scoped to changed files; note any pre-existing unscoped failures — the previous pass recorded 17, two of which were in the now-deleted `FrontmatterPanel.vue`/`TiptapEditor.vue` and disappear here) and the build. Run the Pest `tests/Feature/Notes/` directory as a **regression** check: no PHP changed, so any failure means the developer went outside scope. Then write the manual walkthrough into `implementation.md` for the user, covering at minimum:
    1. Open a plain note: one editor, no Markdown/Rich text toggle in the header, Code/Split/Preview switcher present in the toolbar.
    2. Open a note **with YAML frontmatter**: the block is visible as plain text at the top, undecorated (no heading, no rule band), editable; Preview/Split do not render it as body; edit a frontmatter line, wait for autosave, reopen — bytes match.
    3. Open a note **with an image**, one **with a raw HTML block**, one **with a reference-style link**, one **with a footnote** and one **with a `[[wiki link]]`** — each opens normally, no banner, no consent dialog. These are the five cases that used to be blocked.
    4. Open a note **over 150 KB** — opens instantly, no "open in Markdown mode for optimal performance" banner, footer shows the size.
    5. Open a note **over 1 MB** and a **non-UTF-8** note — the existing read-only alerts are unchanged.
    6. Every toolbar button including the new **Table** (on a bullet line with nothing selected: list marker intact; with text selected: text not deleted), every advertised keyboard shortcut, and the Link dialog's prefill / edit / remove / `javascript:` rejection — i.e. re-run `codemirror-ui-fixes` walkthrough §A–§C, since T2/T4 rewrote those paths.
    7. **`Ctrl+B` in the editor applies bold and does not toggle the sidebar; `Ctrl+B` outside the editor still toggles the sidebar** (the `SidebarProvider.vue` `defaultPrevented` guard from the previous pass's Fix Round 2 must still hold).
    8. Preview and Split in light and dark mode at desktop and ~400px: headings, links, bold, strikethrough, lists, **task checkboxes**, blockquotes, inline code, fences, rules, **images** and **tables** all still styled after the `.tiptap-content` rename; panes side-by-side, not overlapping.
    9. Autosave status badge, Ctrl+S, Save button; a conflict flow (edit the file in another editor, then type) through Reload / Overwrite / Copy / Save as new note / Compare; a guarded navigation with unsaved changes; a note deleted on disk while open.
    10. Create a new note with the frontmatter template setting enabled — the rendered template appears as text at the top of the one editor.
  - Covers: All

---

## 4. Test Plan

### Test file dispositions
| Test File | Disposition | Reason / replacement |
|---|---|---|
| `tests/js/editor/toolbarCommands.test.ts` | **Modify** (T3) | Delete the TipTap `describe` (`:1-98`); keep and extend the CodeMirror table-driven `describe`; **move the editor-agnostic "no link command in the table" test into it**. The 17 existing expected strings must not change — this is T2's regression net. |
| `tests/js/editor/codemirrorCommands.test.ts` | **Modify** (T1) | Add `insertTable` cases: empty selection on a bullet line, multi-line selection (text preserved, one table), end of document, resulting selection over `Header 1`. |
| `tests/js/editor/codemirror.test.ts` | **Modify** (T7) | Add `indentUnit` facet assertions for `indent_size: 2` and `4` and a `Tab` keymap-binding assertion, replacing the only non-TipTap behaviour `tabIndent.test.ts` covered. |
| `tests/js/editor/noTiptapDependency.test.ts` | **New** (T10) | The automatically verifiable part of the removal: no `@tiptap/*`/`codemirror`/`@codemirror/theme-one-dark` in `package.json`, and no `@tiptap`/`isTipTap`/`useEditorTick`/`tiptap-` string in `resources/js/**` or `app.css`. |
| `tests/js/editor/tabIndent.test.ts` | **Delete** (T7) | Three tests, all driving TipTap's `sinkListItem`/`liftListItem`/`insertText` through `markdownExtensions()`. No TipTap, no commands to test. Preference coverage moves to `codemirror.test.ts`. |
| `tests/js/editor/richContent.test.ts` | **Delete** (T7) | Tests `richContent.ts` (`encodeRichContent`/`decodeRichContent`/`richSavePayload`/`richContentAsText`/`frontmatterProblem`) — the rich-mode save unit, deleted in T6. |
| `tests/js/editor/largeNoteThreshold.test.ts` | **Delete** (T7) | Tests `largeNote.ts`'s 150 KB `assessMarkdown` bypass threshold, deleted in T6 (FR-06). |
| `tests/js/markdown/assess.test.ts` | **Delete** (T7) | Tests `assessMarkdown`'s exact/reformat/unsupported classification, which is defined entirely by what TipTap's ProseMirror schema can hold. |
| `tests/js/markdown/roundTrip.test.ts` | **Delete** (T7) | Drives the 28 fixtures through `createMarkdownConverter()` + `assessMarkdown`. There is no round trip left to assert: the editor buffer *is* the Markdown. |
| `tests/js/markdown/spike.test.ts` | **Delete** (T7) | The Phase 4 `@tiptap/markdown` library spike. |
| `tests/js/fixtures/markdown/{exact,reformat,unsupported}/**` (28 files) | **Delete** (T7) | Input corpus for the three deleted files only; no other consumer (verified by grep). |
| `tests/js/editor/noteSaver.test.ts`, `saveTransport.test.ts`, `copyTransport.test.ts`, `visitSafety.test.ts`, `codemirrorLivePreview.test.ts`, `markdownPreview.test.ts` | **Unchanged — regression** | Autosave, conflict mapping, copy mapping, navigation guard, live-preview decorations and the preview renderer/protocol allowlist must all pass with no edit (FR-12). |
| `tests/js/external/changeChecker.test.ts`, `openNoteStatus.test.ts`, `tests/js/formatBytes.test.ts`, `tests/js/vault/keyring.test.ts` | **Unchanged — regression** | External-change classification and unrelated units. |
| `tests/Feature/Notes/**`, `tests/Feature/Services/**`, `tests/Feature/Vaults/**`, `tests/Feature/Security/**` | **Unchanged — regression** | No PHP changes. The backend Rich path keeps its ~25 tests green until the Q7 follow-up. A failure here means scope was exceeded. |

### Requirement → task → test matrix
| FR | Tasks | Proving test / verification |
|---|---|---|
| FR-01 one editor | T5, T11 | `npm run types:check` + `npm run build`; walkthrough 1 |
| FR-02 no assessment / no consent flow | T5, T6 | `noTiptapDependency.test.ts` (no `assessMarkdown`); walkthrough 3 |
| FR-03 frontmatter as text | T5, T6 | `codemirrorLivePreview.test.ts` (frontmatter not decorated, unchanged), `markdownPreview.test.ts` (`stripFrontmatter`, unchanged); walkthrough 2 |
| FR-04 verbatim source save | T5 | `noteSaver.test.ts` + `saveTransport.test.ts` (unchanged), `tests/Feature/Notes/NoteContentTest.php` (unchanged); walkthrough 2, 9 |
| FR-05 no mode memory, no migration | T5, T6 | `noTiptapDependency.test.ts`; grep for `editorSession`; absence of any `database/migrations/` change in the diff |
| FR-06 large-note banner/threshold gone | T5, T6, T7 | walkthrough 4, 5 |
| FR-07 modules deleted | T6, T7 | `noTiptapDependency.test.ts`; `npm run types:check`; `npm run build` |
| FR-08 single code path | T2, T3, T4 | `toolbarCommands.test.ts` (18 commands, expected strings unchanged for the 17 existing); walkthrough 6 |
| FR-09 dependencies removed | T9 | `noTiptapDependency.test.ts`; `npm run build` with before/after bundle size |
| FR-10 engine-neutral CSS | T8 | `noTiptapDependency.test.ts` (`tiptap-` string absent); walkthrough 8 |
| FR-11 Table button | T1, T3, T4 | `codemirrorCommands.test.ts` + `toolbarCommands.test.ts` `table` row; walkthrough 6 |
| FR-12 no save/conflict/copy regression | T5 | `noteSaver`, `saveTransport`, `copyTransport`, `visitSafety`, `tests/js/external/*`, `tests/Feature/Notes/` — all unchanged; walkthrough 9 |
| FR-13 documents true | T12 | Manual read of the amended §20/§21/§53; ADR files present |

**Test scope for QA**:
- `npm run test:js` — the whole JS suite (small). Required subset: `tests/js/editor/toolbarCommands.test.ts`, `codemirrorCommands.test.ts`, `codemirror.test.ts`, `codemirrorLivePreview.test.ts`, `markdownPreview.test.ts`, `noteSaver.test.ts`, `saveTransport.test.ts`, `copyTransport.test.ts`, `visitSafety.test.ts`, `noTiptapDependency.test.ts`, `tests/js/external/`.
- `npm run types:check` — must be 0 errors; this is the primary detector of a dangling import after nine file deletions.
- `npm run check` — scoped to changed files; report, do not fix, unrelated pre-existing issues.
- `npm run build` — must succeed; **record the bundle size** and compare with the pre-change build.
- `php artisan test --compact tests/Feature/Notes/ tests/Feature/Services/ tests/Feature/Vaults/` — **regression only**. No PHP file should appear in `git diff --name-only -- '*.php'`; if one does, that is a scope violation and QA must raise it.
- QA must verify by `git diff --stat` that: no file under `database/migrations/` changed, no file under `app/` changed, and `tests/js/editor/toolbarCommands.test.ts`'s surviving CodeMirror expected strings are unmodified for the 17 pre-existing commands.
- **QA must not report PASS on automated checks alone.** State explicitly in `qa-report.md` that `vite.config.ts` pins `environment: 'node'`, that this feature removes the only fallback editor, and that the T13 walkthrough is a mandatory outstanding user-verification item. Do not recommend moving the folder to `.ai/features/completed/` until the user confirms it.

---

## 5. Risks & Mitigations

- **Risk**: TipTap is deleted while CodeMirror mode is still unverified in a browser, leaving users with no working editor. — **Mitigation**: precondition **P1**; implementation does not start until the user confirms the `codemirror-ui-fixes` walkthrough. This is the single most important control in the plan.
- **Risk**: A user genuinely wants WYSIWYG and this removes it permanently. — **Mitigation**: **Q5** asks explicitly, listing all four capability deltas and their mitigations, and names the reversal (don't remove, or remove TipTap but schedule marker-hiding first). The ADR's "retire TipTap" has already been accepted once; Q5 confirms it with the delta spelled out rather than implied.
- **Risk**: Collapsing `toolbarCommands.ts`'s 18 entries regresses a button that was fixed last pass. — **Mitigation**: the table-driven CodeMirror test asserts the resulting document text for every command id and its 17 existing expectations must not change; walkthrough item 6 re-runs the previous pass's §A–§C by hand.
- **Risk**: The `.tiptap-content` → `.markdown-content` rename silently drops a rule the preview needs (190 lines, Tailwind `@apply`, three families being deleted). — **Mitigation**: T8 is an isolated task with an explicit keep/delete list; walkthrough item 8 names every rendered element to check, in both themes and at two widths.
- **Risk**: Deleting nine files leaves a dangling import that only surfaces at runtime. — **Mitigation**: T6 deletes one file at a time with `npm run types:check` after each; `npm run build` must succeed; `noTiptapDependency.test.ts` is a permanent guard.
- **Risk**: Removing a dependency breaks the build on another machine because the lockfile was hand-edited. — **Mitigation**: T9 edits `package.json` and runs `npm install` to regenerate `package-lock.json`; it must not hand-edit the lockfile, and must rebase onto the already-uncommitted lockfile changes rather than discarding them.
- **Risk**: Deleted tests are later wanted back. — **Mitigation**: **Q3** approval plus the per-file disposition table above; the files remain in git history and the new ADR records why each went.
- **Risk**: The Master Plan amendment drifts from what was actually built, re-creating the documentation lie in the other direction. — **Mitigation**: T12 keeps every toolbar item and every acceptance criterion verbatim and changes only the engine name, tagging each amended section with a dated ADR reference.
- **Risk**: The type widening in T2 (`EditorView` → `CommandTarget`) fights `vue-tsc` and tempts an `as any`. — **Mitigation**: T2 states the fallback explicitly — revert the signatures, keep the test casts, record a deviation. Never add `any` to production code to force it; `as any` on an editor binding is what hid a real defect last pass.
- **Risk**: Scope creep into the backend. — **Mitigation**: **Q7** defers it with a named reason; the QA scope includes a `git diff` check that no `app/`, `database/` or `*.php` file changed.

---

## 6. Open Questions

- [x] **P1 — ANSWERED: satisfied** (user, 2026-10-07). `codemirror-ui-fixes` walkthrough confirmed; folder moved to `.ai/features/completed/codemirror-ui-fixes/`.

- [x] **Q2 — Dependency removal (BLOCKING T9; requires user approval per project convention).** Remove eleven packages from `package.json`: the nine `@tiptap/*` packages, plus `@codemirror/theme-one-dark` (unreferenced since the previous pass replaced `oneDark` with `appEditorTheme`, and deliberately left installed then) and the `codemirror` meta-package (never imported). `marked`, `diff`, `@vueuse/core` and the six imported `@codemirror/*` packages stay. **Recommended: yes.** It is the main measurable win of the feature and is fully reversible by a lockfile revert.

- [x] **Q3 — Test and fixture deletion (BLOCKING T3, T7; requires user approval — tests are part of the application).** Delete five JS test files and 28 Markdown fixtures (`tests/js/markdown/{roundTrip,assess,spike}.test.ts`, `tests/js/editor/{richContent,tabIndent,largeNoteThreshold}.test.ts` — six files in total — and `tests/js/fixtures/markdown/**`), and delete the TipTap half of `tests/js/editor/toolbarCommands.test.ts`. Every one of them asserts TipTap behaviour that will not exist. The two non-TipTap behaviours they covered are explicitly re-homed (T7's `indentUnit`/`Tab` assertions; T3's "no link command in the table"). **Recommended: yes**, with the §4 disposition table as the record. *Alternative if declined: keep the files and skip them, which leaves `@tiptap/*` installed and blocks Q2 — the two answers must agree.*

- [x] **Q4 — Master Plan amendment (BLOCKING T12; requires user approval — `docs/Masterplan.md` is the authoritative spec).** `CLAUDE.md` §7 makes the Master Plan authoritative and §20/§53 still say "Tiptap will provide the primary Markdown editing interface". Amend §20, §21, §53 and the summary mentions to name the CodeMirror 6 unified editor, keeping every toolbar item and acceptance criterion verbatim, and tag each amended section with a dated ADR reference. **Recommended: yes.** Leaving it unamended guarantees a future analyst plans TipTap work and that §7's "if a request conflicts with the Master Plan, stop and escalate" fires on correct code.

- [x] **Q5 — Accept the permanent capability deltas (BLOCKING; this is the question that could reverse the feature).** After this feature MDVault has **no WYSIWYG editing mode at all**: the editor shows styled-but-visible Markdown markers (`**bold**` keeps its asterisks). Also lost: TipTap's table cell Tab-navigation (mitigated by T1's Table button and plain-text table editing — the exact trade-off ADR §Consequences already accepted), semantic list indent/outdent on Tab (replaced by inserting `indent_size` spaces, which is the correct textual operation and round-trips exactly), and autolink/link-on-paste (minor; a bare URL is already a valid GFM autolink). Counterweight: TipTap was never available for notes with images, HTML, reference links, footnotes, wiki links or over 150 KB, and for "reformat" notes it was only available after consenting to have the file rewritten. **Recommended: accept.** If WYSIWYG matters more than the cleanup, the smaller first step is to keep TipTap and instead build Obsidian-style marker-hiding on the existing CodeMirror decoration plugin, then revisit this feature — say so and this plan is withdrawn rather than reshaped.

- [x] **Q6 — Rename `SourceEditor.vue` → `MarkdownEditor.vue` (gates T11 only).** "Source" only means something next to a "rich" counterpart that will no longer exist. One `git mv`, one import, one ref type; no logic. **Recommended: yes.** If declined, skip T11; nothing else changes.

- [ ] **Q7 — Backend `NoteSaveMode::Rich` (gates nothing in this plan; recorded for the follow-up).** `NoteSaveMode::Rich`, `MarkdownService::composeRich()`, `App\Support\FrontmatterEdit`, `InteractsWithNoteContent::frontmatterEdit()`, the `has_frontmatter`/`frontmatter` request fields, and the `body`/`frontmatter`/`frontmatter_yaml` keys in the Inertia `note` payload (which currently ship a note's bytes up to three times per page load) all become unreachable from the client. **Recommended: defer to a follow-up Level 3 feature, `retire-rich-save-mode-backend`.** Reasons: zero user-visible change; roughly 25 Pest tests across `MarkdownServiceTest`, `NoteServiceTest`, `NoteContentTest` and `NoteCopyTest` assert the Rich path; it threads through `EncryptedNoteService::save()`/`createCopy()`; and keeping this feature's blast radius inside `resources/js/**` + `package.json` + `docs/` is what makes it reviewable and revertible on the one day TipTap is deleted. If the user prefers it in one pass, this plan needs a Revision 2 with ~6 further tasks and a materially larger QA scope.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-07 | User asked for ADR `codemirror-unified-editor` §Decision-1 to be executed, having confirmed the prior implementation left TipTap in place with no recorded reason | 13 FRs across removing the mode bifurcation, collapsing the shared editor modules, dependency and CSS cleanup, the Table-button capability replacement and the Master Plan amendment; 13 ordered tasks; a per-file test-disposition table; precondition P1 and approval items Q2–Q5 blocking the start |
