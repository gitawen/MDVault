# Requirements: Retire TipTap and the Rich/Source Editor Bifurcation

## Metadata
- **Feature Name**: Retire TipTap and the Rich/Source Editor Bifurcation
- **Feature ID**: feat-retire-tiptap
- **Author**: System Analyst
- **Created Date**: 2026-10-07
- **Task Complexity**: Level 3 — Complex Development
- **Master Plan Phase**: Phase 4 — Editor (`docs/Masterplan.md` §53; also §20 toolbar, §21 round trip, §41 `MarkdownService` responsibilities), realised with CodeMirror 6 per `.ai/decisions/codemirror-unified-editor.md`
- **Status**: APPROVED FOR PLANNING — **implementation BLOCKED on precondition P1** (see §7) and on the approval items Q2–Q5 in `plan.md` §6

---

## 1. Problem Statement

`.ai/decisions/codemirror-unified-editor.md` §Decision-1 was accepted on 2026-10-07 and says, in two sentences:

> Make CodeMirror 6 the **single, unified editor engine** for all notes.
> Delete the rich vs. source mode bifurcation.

The feature that implemented it (`.ai/features/completed/codemirror-unified-editor/`) did not carry it out. Its `implementation.md` T5 line reads "Set default mode to `'source'`, made on-mount loading instant without fidelity conversion delay" — it made CodeMirror the *default* and left TipTap fully alive behind a toggle. QA passed it on that basis (`qa-report.md` FR-01 "Implemented: yes — `NoteEditor.vue` default mode"), and the analyst review signed it off as "strictly adheres to the ADR". **None of those three documents records a reason for keeping TipTap.** It was unfinished work that three gates each read as finished, not a deliberate trade-off. This requirement therefore does not have to re-litigate a prior design objection; there is none on record.

The cost of the half-finished state, as it stands in the working tree today:

1. **Two editors, one of which can silently rewrite the user's file.** TipTap's save path goes through `MarkdownService::composeRich()`, which reconstructs the file from a serialised ProseMirror AST plus a separately-posted frontmatter string. `assessMarkdown` (`resources/js/lib/markdown/assess.ts`) exists solely to warn the user when that rewrite will change their bytes, via a consent dialog ("Edit in rich text (reformat on save)" / "Edit as source", `NoteEditor.vue:822-846`). CodeMirror's path posts the document verbatim. Master Plan non-negotiable #1 ("Markdown files are the source of truth") and #9 ("never silently overwrite") are both strictly better served by having only the verbatim path.
2. **TipTap is already unavailable for most real notes.** `assessMarkdown` returns `unsupported` — forcing source mode and showing a banner — for any note containing an image, inline or block HTML, a reference-style link, a footnote or a wiki link (`assess.ts:51-102,151-163`). `isLargeNote` (>150 KB) disables the Rich button outright (`NoteEditor.vue:676-680`). `too_large` (>1 MB) and invalid-UTF-8 notes bypass both editors. The toggle therefore advertises a mode that is unavailable or consent-gated for a large share of a real vault, which is itself a UX defect.
3. **Every shared editor module carries two branches forever.** `toolbarCommands.ts` is 323 lines of `isTipTap(editor) ? … : …` for 17 commands. `EditorToolbar.vue`, `LinkDialog.vue` and `useEditorTick.ts` each fork on editor shape. The `codemirror-ui-fixes` pass had to preserve both branches (its `requirements.md` §7 Assumption 2) while fixing 22 defects in one of them, and its own post-mortem names the dual-branch table as the reason the CodeMirror branch had zero coverage (`plan.md` §4 item 2: the shared table was driven "**only** through TipTap", which is how `clearFormatting`'s literal `: true` no-op shipped).
4. **Nine `@tiptap/*` packages, plus two now-unused CodeMirror packages**, ship in the production bundle for a mode most notes cannot use.
5. **The product UI still exposes the bifurcation.** `codemirror-ui-fixes` FR-22/T11 relabelled the toggle from "CodeMirror"/"TipTap" to "Markdown"/"Rich text" — a copy fix explicitly scoped as a stopgap for this feature (that plan's Q2: "Full removal of the rich/source bifurcation per ADR §Decision-1 remains a separate feature").

The user has now asked for ADR §Decision-1 to be executed.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- **One editor.** `NoteEditor.vue` mounts the CodeMirror 6 editor unconditionally. No mode state, no mode toggle, no mode switching, no per-note mode memory.
- **Delete the fidelity machinery.** `assessMarkdown`, the Markdown↔TipTap converter, the TipTap extension list, the reformat-consent flow and the "unsupported Markdown" banner all go. Notes that previously could not open in rich mode now simply open.
- **Delete the rich-mode frontmatter panel.** YAML frontmatter becomes ordinary text at the top of the Markdown document, which is what is on disk.
- **One code path in every shared editor module.** `toolbarCommands.ts`, `EditorToolbar.vue`, `LinkDialog.vue` lose their TipTap branch; `useEditorTick.ts` is deleted.
- **Remove the dependencies** that become unused: nine `@tiptap/*` packages, plus `@codemirror/theme-one-dark` and the `codemirror` meta-package, both already unreferenced. **Requires user approval** (Q2).
- **Close the one capability gap the ADR itself named** — "ProseMirror WYSIWYG tables … replaced by standard Markdown table editing" — by exposing the already-implemented, already-tested `insertTable` command in the toolbar.
- **Make the documentation true.** Amend `docs/Masterplan.md` §20/§53, which still name Tiptap as the editor, and set `.ai/decisions/markdown-conversion-and-fidelity.md` to Superseded. **Requires user approval** (Q4).

### Out of Scope (Non-Goals)
- **Any backend change.** `NoteSaveMode::Rich`, `MarkdownService::composeRich()`, `App\Support\FrontmatterEdit`, `InteractsWithNoteContent::frontmatterEdit()` and the `has_frontmatter`/`frontmatter` request fields become unreachable from the client but are **left in place**. The client keeps sending `mode: 'source'`, which is already the only mode it will use. Rationale and follow-up: `plan.md` §6 Q7 — roughly 25 Pest tests across `MarkdownServiceTest`, `NoteServiceTest`, `NoteContentTest` and `NoteCopyTest` assert the Rich path, it threads through `EncryptedNoteService::save()`/`createCopy()`, and removing it produces **zero** user-visible change. It belongs in its own feature.
- **Trimming `body` / `frontmatter` / `frontmatter_yaml` out of the Inertia `note` payload.** They become unread by the client (today the page ships the note's bytes up to three times over), but removing them changes `NoteService::preview()`/`EncryptedNoteService::preview()` and ~8 Pest assertions. Same follow-up feature as above.
- **Obsidian-style syntax hiding** (concealing `**`/`#` markers on inactive lines). The accepted ADR specifies styled-but-visible markers; `codemirror-ui-fixes` already ruled this a separate feature.
- **New editor capabilities**: images, autocompletion, a search panel, wiki-link resolution, callouts.
- **Adding a DOM test environment** (`jsdom`/`happy-dom`/`@vue/test-utils`). `vite.config.ts:45-48` stays `environment: 'node'`. This remains the governing verification constraint and is why P1 exists.

---

## 3. User Personas & Stories

- **As a** note author,
  **I want** every note to open in the same editor with the same toolbar, with no banner telling me my own Markdown is unsupported and no dialog asking me to consent to my file being reformatted,
  **So that** the editor never second-guesses what is already on disk.

- **As a** note author with images, HTML snippets, footnotes or `[[wiki links]]` in my notes,
  **I want** those notes to open normally,
  **So that** I stop seeing "This note uses Markdown the rich-text editor can't preserve yet".

- **As a** note author who edits YAML frontmatter,
  **I want** to edit it in the document where it actually lives,
  **So that** there is one place to look and the bytes I type are the bytes that are saved.

- **As a** maintainer,
  **I want** one editor engine and one toolbar command path,
  **So that** a toolbar fix cannot be correct in one branch and a no-op in the other, which is exactly what happened to Paragraph, Divider and Clear formatting.

---

## 4. Functional Requirements

### Category 1 — Remove the bifurcation

| ID | Requirement | Description (mechanism + what the user sees) | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | A single editor, mounted unconditionally | `NoteEditor.vue:103-137` declares `type Mode = 'rich' \| 'source'`, seeds it from `editorSession.getMode()`, and the template (`:647-731`, `:848-872`) renders either `FrontmatterPanel` + `TiptapEditor` or `SourceEditor`. All of it collapses: no `Mode` type, no `mode` ref, no `switchMode()`, no `writtenSinceMount`, no segmented control. The CodeMirror editor is the only child. | Given any editable note, When it opens, Then the CodeMirror editor is mounted directly and the header contains no editor-mode control; the Code / Split / Preview switcher inside the editor toolbar is unchanged. |
| **FR-02** | No fidelity assessment and no consent flow on open | `assessMarkdown` (up to 6 headless `@tiptap/core` `Editor` creations plus two `JSON.stringify` AST comparisons, `assess.ts:135-180`) is deleted along with the `assessing` / `assessment` / `reformatAccepted` state, the "Checking formatting…" line (`NoteEditor.vue:799-801`), the `unsupported` banner (`:811-820`) and the reformat consent alert with its two buttons (`:822-846`). | Given a note containing an image, a raw HTML block, a reference-style link, a footnote or a `[[wiki link]]`, When it opens, Then it opens in the editor with no banner and no consent dialog; Given any note, Then no "Checking formatting…" state is ever rendered. |
| **FR-03** | Frontmatter is edited as document text | `FrontmatterPanel.vue` is rich-mode-only (`NoteEditor.vue:848-853`, `v-if="mode === 'rich'"`) and is deleted with its `richContent.ts` dependency. The leading `---` … `---` block is part of the CodeMirror document, as it is on disk. The live-preview plugin already excludes it from decoration and the rendered preview already strips it (`codemirror-ui-fixes` FR-11/FR-19), so no new work is needed to display it correctly. | Given a note whose file begins with a YAML frontmatter block, When it opens, Then the block is visible and editable as plain text at the top of the editor, is not decorated as a horizontal rule or heading, and is not rendered as body content in Preview or Split; When the user edits a frontmatter line and the note autosaves, Then the file's bytes are exactly what the editor shows. |
| **FR-04** | Every save is a verbatim source save | `NoteEditor.vue:184-192` and `:514-526` fork their payload on mode, with the rich branch posting `{content: body, has_frontmatter, frontmatter}` through `richSavePayload`. Both collapse to `{content, base_hash, mode: 'source'}` / `{content, mode: 'source', source_path}`, and `readContent()` (`:150-157`) collapses to the editor's `getText()`. The backend contract is unchanged. | Given any edit, When autosave or Save fires, Then the request body contains `mode: 'source'` and a `content` equal to the full editor document, and contains no `has_frontmatter` or `frontmatter` key; Given an untouched note, Then no save is sent and the file's bytes are unchanged. |
| **FR-05** | No per-note mode memory | `resources/js/lib/editor/editorSession.ts` is a module-level `Map`/`Set` holding the last-used mode and the reformat consent per note UUID. Nothing is persisted: there is **no** DB column, no `settings` row (`App\Enums\SettingKey` has eight `editor.*` keys — font size, family, line height, word wrap, line numbers, indent size, new-note template enabled/body — and none for a mode) and no migration. The file is deleted outright. | Given the removal, Then `grep -rn "editorSession\|'rich'" resources/js app database` returns no editor-mode match, and **no migration is created**. |
| **FR-06** | The large-note banner and threshold go with the mode they gated | `isLargeNote`/`LARGE_NOTE_THRESHOLD` (`largeNote.ts`) exist only to (a) skip `assessMarkdown` and (b) disable the Rich button. With one editor they gate nothing, and the banner "This note is large (…) and is open in Markdown mode for optimal performance" names a mode that no longer exists. The note's size is already shown in the footer (`NoteEditor.vue:880-882`). `largeNote.ts` and `tests/js/editor/largeNoteThreshold.test.ts` are deleted. The **backend** `PREVIEW_LIMIT` (1 MB → `state: 'too_large'`, read-only) is a different mechanism and is untouched. | Given a 500 KB note, When it opens, Then it opens in the editor with no mode banner and the footer shows its size; Given a note over the backend 1 MB preview limit, Then the existing read-only "larger than 1 MB" alert is unchanged. |

### Category 2 — Dead code, one code path, dependencies

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-07** | All TipTap-coupled modules deleted | `resources/js/components/editor/TiptapEditor.vue`, `resources/js/components/editor/FrontmatterPanel.vue`, `resources/js/lib/markdown/extensions.ts`, `resources/js/lib/markdown/converter.ts`, `resources/js/lib/markdown/assess.ts`, `resources/js/lib/editor/richContent.ts`, `resources/js/lib/editor/editorSession.ts`, `resources/js/lib/editor/useEditorTick.ts`, `resources/js/lib/editor/largeNote.ts`. `resources/js/lib/markdown/` becomes empty and is removed. | Given the removal, Then none of those paths exists, `npm run types:check` reports 0 errors and `npm run build` succeeds. |
| **FR-08** | One code path per shared editor module | `toolbarCommands.ts`: delete `isTipTap`, the `SupportedEditor` union and all 17 `isTipTap(editor) ? … : …` ternaries; commands target CodeMirror only. `EditorToolbar.vue`: drop the `editor` prop, the `activeEditor` computed, the `useEditorTick` call and the `tick` reads, and the TipTap branch of `currentHref()` — the `view` prop plus the `revision` prop are the whole input surface. `LinkDialog.vue`: drop the `Editor` type import, the `editor` prop and the `isTipTap` branches of `apply()`/`remove()`. | Given the removal, Then `grep -rn "@tiptap\|isTipTap\|useEditorTick" resources/js` returns nothing; Then no editor component passes an editor through `as any`; Then every toolbar button and the Link dialog behave exactly as `codemirror-ui-fixes` FR-01…FR-10 specified. |
| **FR-09** | Unused dependencies removed from `package.json` | Nine packages exist only for TipTap: `@tiptap/starter-kit`, `@tiptap/vue-3`, `@tiptap/pm`, `@tiptap/markdown`, `@tiptap/extension-list`, `@tiptap/extension-table`, `@tiptap/extension-table-cell`, `@tiptap/extension-table-header`, `@tiptap/extension-table-row`. Two more are **already** unreferenced anywhere in `resources/` after `codemirror-ui-fixes` T8: `@codemirror/theme-one-dark` (that plan deliberately left it installed) and the `codemirror` meta-package (no import of it exists). `marked` **stays** — `markdownPreview.ts` uses it. **Requires user approval (Q2).** | Given approval, When `package.json` is updated and `npm install` is re-run, Then no `@tiptap/*`, `@codemirror/theme-one-dark` or bare `codemirror` entry remains, `package-lock.json` is regenerated, `npm run build` succeeds and the reported bundle size is recorded in `implementation.md`. |
| **FR-10** | No TipTap identifiers in the CSS | `resources/css/app.css:186-372` defines `.tiptap-content` (and `.tiptap-nowrap`), which `SourceEditor.vue:317` reuses for the **CodeMirror** rendered-preview pane. Rename it to an engine-neutral `.markdown-content`, and drop the three selector families `marked` can never emit: `ul[data-type='taskList'] …` (TipTap's task DOM — `marked`'s `<li><input type="checkbox">` output is already styled by `.markdown-preview-content` in `SourceEditor.vue`'s `<style>`), `.tiptap-nowrap .tiptap-content`, and `.tiptap-content { min-h-48 outline-none }` (TipTap's `contenteditable` surface). | Given the rename, Then `grep -rin tiptap resources/` returns nothing; Given Preview and Split view, Then headings, paragraphs, links, lists, task checkboxes, blockquotes, inline code, fences, rules, images and tables render exactly as before (visual check, walkthrough §C). |

### Category 3 — Capability preservation and documentation

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-11** | A Table toolbar button, replacing TipTap's table UI | TipTap provided `@tiptap/extension-table` with Tab/Shift-Tab cell navigation (`TiptapEditor.vue:100-120`). ADR §Consequences already accepts replacing it with "standard Markdown table editing". `insertTable` (`codemirrorCommands.ts:274-289`) is implemented and unit-tested but reachable from no UI (`codemirror-ui-fixes` Q3, left unanswered). Expose it as a `table` toolbar command. It must first be corrected: it currently **replaces the selection** with the template (`changes: { from, to, insert }`), so clicking Table with text selected deletes it. It must insert after the current line, following `insertHorizontalRule`'s convention. | Given the caret on `- item` with nothing selected, When the user clicks Table, Then a GFM table skeleton is inserted on its own lines below, `- item` is intact, and the caret is inside the first header cell; Given a non-empty selection, When the user clicks Table, Then the selected text is **not** deleted; Given Preview view, Then the skeleton renders as a table. |
| **FR-12** | No regression in the save, conflict, copy, compare, guard or external-change behaviour | These are the highest-value behaviours in the editor and all of them have a mode branch being deleted: `send()`, `saveAsNewNote()`, `conflictCopy()`, `openCompare()`, `readContent()`, `ensureSaver()`'s baseline, the save epoch, `freeze()`/`unfreeze()`, `applyExternalStatus()`. Only the rich branch is removed; the source branch's behaviour must be byte-identical to today. | Given each of: autosave debounce, Ctrl+S flush, a 409 `changed` conflict (reload / overwrite / copy-to-clipboard / save-as-new / compare), a 409 `missing` conflict, an external change while clean and while dirty, a guarded navigation freeze and a note moved on disk — When exercised, Then behaviour matches today; `tests/js/editor/noteSaver.test.ts`, `saveTransport.test.ts`, `copyTransport.test.ts`, `visitSafety.test.ts`, `tests/js/external/*` and `php artisan test --compact tests/Feature/Notes/` all pass unchanged. |
| **FR-13** | The product and decision documents stop naming TipTap as the editor | `docs/Masterplan.md` is authoritative per `CLAUDE.md` §7 and §20/§53 still say "Tiptap will provide the primary Markdown editing interface". Amend §20, §21 and §53 to name the CodeMirror 6 unified editor and reference the ADR, keeping the toolbar list and the round-trip/acceptance criteria intact. Set `.ai/decisions/markdown-conversion-and-fidelity.md` Status to Superseded (it was already marked as replaced by the CodeMirror ADR). Add `.ai/decisions/retire-tiptap-rich-mode.md` recording the capability deltas accepted here. **Requires user approval (Q4).** | Given the amendment, Then §20/§21/§53 name no editor engine the codebase does not contain, and a future `system-analyst` reading the Master Plan alone would not plan TipTap work. |

---

## 5. Non-Functional Requirements

- **Security & Authorization**: No authorization surface changes; no controller, route, policy or middleware is touched. The two security fixes from `codemirror-ui-fixes` must survive intact: the shared `isAllowedHref` protocol allowlist (`linkProtocols.ts`) used by both `LinkDialog.vue` and `markdownPreview.ts`'s escaped link/image renderers, and the unconditional `preventDefault()` in `handlePreviewClick`. Deleting the TipTap branch of `LinkDialog.apply()` removes one `isAllowedHref` call site; the CodeMirror call site must remain the gate. Per Master Plan non-negotiable #11, nothing may log document content.
- **Performance**: Strictly improving. Note open loses the `assessMarkdown` pass entirely (up to 6 headless ProseMirror editor create/destroy cycles plus two full-document `JSON.stringify` comparisons, previously on the main thread in `onMounted`). The production bundle loses nine `@tiptap/*` packages. `implementation.md` must record the `npm run build` bundle size before and after so the saving is a measured number, not a claim.
- **Accessibility & UX**: Vue components keep a single root element. The toolbar's `aria-pressed` and `disabled` must stay truthful — after FR-08 they are driven solely by `props.revision` + the CodeMirror state, which is the path `codemirror-ui-fixes` FR-01/FR-02 fixed and is now the only path. The new Table button needs a label and tooltip consistent with the others, and must appear in the overflow menu at narrow widths like the other secondary commands.
- **Reliability & Data Integrity**: Markdown files remain the source of truth. The removal **increases** fidelity: the only remaining write path is the verbatim source save, so the "reformat on save" risk class that `assessMarkdown` existed to detect is eliminated structurally rather than detected and consented to. No change to `createNoteSaver`, the autosave baseline, SHA-256 hashing, the atomic-replace save (ADR `note-save-atomic-replace`) or the external-change reconciliation. No migration, no new table, no new base folder.
- **Verification honesty**: `vite.config.ts` pins `environment: 'node'`, so no test in this repo can mount a Vue component or an `EditorView`. This feature **cannot** be certified by the automated suite alone, exactly as `codemirror-ui-fixes` could not. QA must say so, and the plan's final task is a manual browser walkthrough.

---

## 6. Technical Constraints & Context

- Framework: Laravel 13 / PHP 8.4 — **no PHP file is expected to change**; `vendor/bin/pint` should not need to run.
- Frontend: Inertia v3 + Vue 3 + Tailwind CSS v4; CodeMirror 6 (`@codemirror/view` ^6.43, `@codemirror/state` ^6.7, `@codemirror/commands` ^6.11, `@codemirror/lang-markdown` ^6.5, `@codemirror/language` ^6.12), `marked` ^17, `@vueuse/core` ^12.8, `diff` ^9.
- `vite.config.ts:45-48` pins `test.environment: 'node'`. All new coverage must be exercisable against an `EditorState`, a `CommandTarget`, a pure function or the filesystem.
- `vite.config.ts` `lint`/`fmt.ignorePatterns` excludes `resources/js/components/ui/**` (shadcn-vue vendor files) — `npm run check` will not lint files there.
- Code style: `npm run check:fix`, `npm run types:check`. Removing a dependency requires `npm install` to regenerate `package-lock.json`.
- Git: the working tree already carries uncommitted `package.json`/`package-lock.json` changes and the untracked CodeMirror modules from the two prior passes. Per `CLAUDE.md`, commit on a feature branch, never on `main`, and add no AI attribution.
- The `.ai/rules/` directory does not exist in this repository; the Laravel Boost guidelines in `AGENTS.md` apply directly.

---

## 7. Risks & Assumptions

- **P1 — BLOCKING PRECONDITION (and the honest answer to "is there a reason not to do this yet?").**
  There is no *architectural* reason TipTap must stay. There is a live *verification* reason it must not be deleted **this minute**: CodeMirror mode's 22 UI fixes have never been confirmed in a browser by a human. `.ai/features/active/codemirror-ui-fixes/qa-report.md` §R2.3 lists FR-01 (live toolbar state), FR-08 (Link dialog round trip), FR-10 (keyboard shortcuts suppressing native `contenteditable` behaviour), FR-13/14/15/18 (the rendered CSS cascade and dark mode), FR-17a (checkbox click/focus) and FR-21 (typing latency) as **unverified by any automated suite**, and both QA rounds end with "Do not move this feature to `.ai/features/completed/` until a human has run `implementation.md` §6". Two further bugs — `Ctrl+B` also toggling the app sidebar, and the Split-view panes overlapping — were found only by the user's own walkthrough and were fixed afterwards, and the second fix is itself recorded as visually unconfirmed (`implementation.md` §8 Bug 2 caveat).
  While TipTap exists, a user who hits a broken CodeMirror surface has a working editor to fall back to. After this feature there is no fallback, and `npm run test:js` cannot tell us whether that is safe.
  **Mitigation / gate**: implementation starts only after the user confirms the `codemirror-ui-fixes` walkthrough (`implementation.md` §6, items 1–28, plus §8's two re-checks) passes and that feature folder is moved to `.ai/features/completed/`. This is a schedule gate, not a design objection, and it is why the plan's status is BLOCKED rather than APPROVED.

- **Risk — a genuine, permanent capability loss (Q5).** Four things TipTap can do that the CodeMirror editor cannot, stated plainly rather than dropped quietly:
  1. **True WYSIWYG.** CodeMirror shows styled-but-visible Markdown markers; `**bold**` keeps its asterisks. Master Plan §20 says the editor "should feel like a modern note-taking application rather than a raw code editor". *Assessment*: the largest delta, and the one the user should consciously accept. *Mitigation*: the live-preview decorations (heading scale, interactive checkboxes, blockquote bars, fence containers) plus the Split and Preview views cover the rendered-reading need, and marker-hiding on inactive lines remains available as a future feature on top of the same decoration plugin. *Counterweight*: TipTap's WYSIWYG was never available for notes with images/HTML/footnotes/wiki-links or over 150 KB, and for `reformat` notes it was only available after consenting to have the file rewritten.
  2. **Table cell navigation** (Tab/Shift-Tab between cells, `goToNextCell`). *Mitigation*: FR-11's Table button plus plain-text table editing — the exact trade-off ADR §Consequences already accepted.
  3. **Semantic list indent/outdent on Tab** (`sinkListItem`/`liftListItem`). CodeMirror's `indentWithTab` inserts `indent_size` spaces instead. *Assessment*: for Markdown lists, inserting the configured indent is the correct textual operation and round-trips exactly; TipTap's version normalised nesting to 2 spaces (`extensions.ts:74`). Net wash, arguably better.
  4. **Autolink and link-on-paste** (`StarterKit.link: { autolink: true, linkOnPaste: true }`). *Assessment*: real but minor; a bare URL is already a valid GFM autolink on disk and renders as a link in Preview. Noted as a candidate future CodeMirror paste handler.

- **Risk — deleting a test corpus that cannot be reconstructed.** Five JS test files and 28 Markdown fixtures exist solely to prove TipTap's round trip (`tests/js/markdown/{roundTrip,assess,spike}.test.ts`, `tests/js/editor/{richContent,tabIndent}.test.ts`, `tests/js/fixtures/markdown/{exact,reformat,unsupported}/`). With no AST round trip there is nothing for them to assert. — **Mitigation**: explicit approval (Q3), a per-file disposition table in `plan.md` §4, and named replacement coverage for the two behaviours that are *not* TipTap-specific (the `indent_size` Tab preference, and "the toolbar table has no `link` command"). Nothing is deleted silently.

- **Risk — the preview pane regresses when `.tiptap-content` is renamed.** It is a 190-line CSS block that the CodeMirror preview depends on, and Tailwind v4 `@layer components` + `@apply` makes a partial rename easy to get wrong. — **Mitigation**: FR-10's task is a mechanical selector rename plus three deletions, done in one isolated commit, and the walkthrough checks every rendered element class.

- **Risk — the shared toolbar modules are load-bearing and were only just repaired.** Collapsing `toolbarCommands.ts`'s 17 ternaries touches every button fixed last pass. — **Mitigation**: the existing CodeMirror-branch table-driven test (`toolbarCommands.test.ts:145-183`) asserts the resulting document text for every command id and must pass **unchanged** across the collapse; it is the regression net for the whole task.

- **Assumption 1**: No TipTap usage exists outside the editor — verified: `grep -rn "@tiptap" resources/ app/ tests/` matches only `extensions.ts`, `converter.ts`, `TiptapEditor.vue`, two type-only imports (`toolbarCommands.ts`, `LinkDialog.vue`) and five test files. There is no read-only TipTap renderer anywhere; `NoteCompareDialog.vue` uses `diff`.
- **Assumption 2**: No stored editor-mode preference exists — verified: `editorSession.ts` is module-level and session-only; `App\Enums\SettingKey` has no mode key; no `notes` or `settings` column holds one. **No migration.**
- **Assumption 3**: The backend accepts `mode: 'source'` for every note including encrypted vaults — verified: `SaveNoteContentRequest` / `StoreNoteCopyRequest` validate `Rule::enum(NoteSaveMode::class)`, and `NoteService::save()`/`EncryptedNoteService::save()` route `Source` to `MarkdownService::composeSource()` (verbatim apart from CRLF→LF, re-applied by `encode()`). `tests/Feature/Vaults/EncryptedVaultHttpTest.php:182` already posts `mode: 'source'`.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [ ] **Blocked on P1** (user confirmation of the `codemirror-ui-fixes` manual walkthrough) and on Q2–Q5 in `plan.md` §6
- [ ] Approved to proceed to implementation
