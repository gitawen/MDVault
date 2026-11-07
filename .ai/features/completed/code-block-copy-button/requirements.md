# Requirements: Code Block & Inline Code Copy Buttons

## Metadata
- **Feature Name**: Code Block & Inline Code Copy Buttons
- **Feature ID**: feat-code-block-copy
- **Author**: System Analyst
- **Created Date**: 2026-11-07
- **Revision**: 2
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
When viewing or editing notes in MDVault, users frequently need to copy code snippets and note content into their clipboard for use in terminal sessions, code editors, or other applications. Currently, users face several friction points:
1. **Fenced Code Blocks**: Users must manually select lines inside code blocks—which is prone to accidentally highlighting backtick fences (` ``` `), dragging across unintended lines, or misselecting text in CodeMirror.
2. **Inline Code**: Inline code spans (`` `code` ``) represent commands, identifiers, file names, or configuration flags. Manually selecting tiny inline text often misses boundary characters or accidentally grabs surrounding backticks.
3. **Full Note Content**: Users frequently need to copy the entire Markdown content of the open note without manually opening the raw file from disk or selecting all text in the editor.

Adding dedicated, unobtrusive one-click copy buttons for code blocks, inline code spans (in both rendered preview and CodeMirror live preview), and full note content in the note header creates a seamless, modern developer experience.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- **Fenced Code Block Copy**:
  1. Rendered Markdown Preview (Preview and Split views): copy button pinned to top-right of `<pre><code>`.
  2. CodeMirror Live Preview editor (Code and Split views): inline widget button on the opening fence line (`cm-md-copy-btn`).
  3. Extract **only the inner code content**, strictly excluding opening and closing fence lines and trailing newlines.
- **Inline Code Copy (`code` with single backticks)**:
  1. Rendered Markdown Preview: custom `codespan` renderer wrapping code in `.inline-code-wrapper` with a compact `.inline-code-copy-btn` appearing on hover/focus, handled via preview click delegation.
  2. CodeMirror Live Preview: `InlineCodeCopyWidget` attached at `inlineCode.to`, extracting inner code text without surrounding `CodeMark` backtick delimiters.
  3. Strictly preserve `RangeSetBuilder` non-decreasing monotonicity across all line, replace, mark, and widget decorations on the same line.
- **Full Note Content Copy**:
  1. Add a Copy button in the note header next to the Save button in `resources/js/components/editor/NoteEditor.vue`.
  2. Copy current in-memory text via `readContent()` (with fallback to `props.note.content`).
  3. Provide 2-second visual feedback switching the icon from `Copy` to `Check` and label from "Copy" to "Copied" with accessible `aria-label`.
- **Caret Isolation & Stability**:
  - Clicking any copy button in CodeMirror prevents default and stops propagation, leaving the editor selection, caret, and document unmodified.
  - Functions in both editable and read-only editor modes.
- **Architectural & Design Constraints**:
  - No vertical margins on `.cm-line`.
  - All Live Preview styling in CodeMirror theme (no Tailwind utility classes in decoration styles).
  - Testable in the `node` Vitest / Vite Plus environment without mounting a browser DOM.

### Out of Scope (Non-Goals)
- Syntax highlighting theme configuration UI.
- Language picker or code execution inside code blocks.
- Multi-note batch clipboard operations or export dialogs.

---

## 3. User Personas & Stories
- **As a** developer using MDVault,
  **I want to** click a single button to copy a command or code snippet from either a fenced block or an inline code tag,
  **So that** I don't have to manually select text or strip backtick delimiters.
- **As a** note-taker,
  **I want to** copy the entire contents of my note from the editor header with one click,
  **So that** I can paste it into an email, ticket, or chat without selecting all text or switching files.
- **As a** user editing a note in CodeMirror,
  **I want** clicking a copy button to never disrupt my cursor position or insert unwanted characters,
  **So that** my editing flow remains uninterrupted.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Preview Code Block Copy Button | Rendered Markdown preview wraps `<pre><code>` in `.code-block-wrapper` with an absolute-positioned copy button in the top-right corner. | Given a note with code blocks in Preview or Split view, When the user views or hovers over a code block, Then a copy button is visible at the top-right corner with `aria-label="Copy code"`. |
| **FR-02** | Preview Code Clipboard Copy | Clicking the fenced code copy button copies the code block's text content to the system clipboard, stripping any single trailing newline. | Given a code block with content `const a = 1;`, When clicked, Then `navigator.clipboard` receives exactly `const a = 1;` without extra trailing newlines. |
| **FR-03** | CodeMirror Fenced Code Copy Widget | In Code and Split views, fenced code blocks display a copy button widget integrated on the opening fence line at `line.to`. | Given a fenced code block ```` ```js\nconst x = 1;\n``` ````, When rendered in CodeMirror, Then an inline widget button is rendered on the opening fence line at `line.to` with `cm-md-copy-btn`. |
| **FR-04** | Inner Code Extraction (Fenced Blocks) | The CodeMirror copy widget copies exclusively the inner lines between opening and closing fences, omitting delimiter lines. | Given ```` ```ts\nhello world\n``` ````, When clicked, Then clipboard receives `hello world` without fence lines. |
| **FR-05** | Caret Isolation & Read-Only Support | Clicking any CodeMirror copy widget ignores editor events, does not move the caret or mutate the document, and functions in editable and read-only modes. | Given CodeMirror, When clicking a copy widget, Then caret position and selection do not change, no edit transaction is dispatched, and text is copied. |
| **FR-06** | Copy Feedback & A11y | Clicking any copy button transitions the icon to a checkmark, sets `aria-label="Copied!"` (or "Copied note"), and reverts after 2000ms. | Given a copy button, When clicked, Then icon changes to `Check` and label updates to copied state for 2000ms before returning to the copy icon. |
| **FR-07** | Preview Inline Code Copy Button | Rendered preview wraps inline code spans in `<code class="inline-code-wrapper group">` containing `<span class="inline-code-text">` and a compact `<button type="button" class="inline-code-copy-btn" data-inline-code-copy>`. | Given an inline code span `` `npm install` ``, When rendered in Preview, Then it includes `.inline-code-copy-btn` which copies `npm install` on click and shows checkmark feedback. |
| **FR-08** | CodeMirror Inline Code Copy Widget | Live Preview renders an `InlineCodeCopyWidget` at the end (`node.to`) of each `InlineCode` syntax node, with compact `.cm-md-inline-copy-btn` styling. | Given `` `foo` `` in CodeMirror, When rendered in Live Preview, Then a compact copy button widget is placed at `node.to`. |
| **FR-09** | Inner Inline Code Extraction & Monotonic Ordering | `extractInlineCodeContent` extracts code text between opening and closing `CodeMark` backticks. `decorateLine` adds all line, replace, mark, and widget decorations in strictly non-decreasing `from` order. | Given lines containing tasks, blockquotes, headings, and one or more inline code spans, Then `RangeSetBuilder` never throws an out-of-order range error, and inner code excludes backticks. |
| **FR-10** | Copy Full Note Content Button | Note header in `NoteEditor.vue` displays a Copy button next to the Save button, copying `readContent()` (or `props.note.content`) with 2s Check feedback. | Given NoteEditor, When the user clicks the Copy button in the header, Then the entire current note text is copied to the clipboard and the button displays `Check` + "Copied" for 2000ms. |

---

## 5. Non-Functional Requirements
- **Performance**: CodeMirror decoration building remains $O(N)$ with respect to visible ranges; text extraction is lazy upon click; no extra re-renders or allocations during normal typing.
- **Accessibility & UX**: All copy buttons have explicit `aria-label`, `type="button"`, keyboard focusability, and hover/focus visibility transitions conforming to Tailwind v4 theme variables.
- **Reliability & Browser Compatibility**: Supports `navigator.clipboard.writeText` with fallback to `document.execCommand('copy')` for non-secure / restricted contexts.
- **Maintainability & Architecture**: Adheres strictly to CodeMirror Live Preview constraints: no vertical margins on `.cm-line`, em-relative font sizes, strict `RangeSetBuilder` monotonic ordering, and unit testability in Vite Plus `node` environment.

---

## 6. Technical Constraints & Context
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4
- Editor: CodeMirror 6 (`@codemirror/view`, `@codemirror/state`, `@codemirror/language`, `@codemirror/lang-markdown`)
- Markdown Parser: Marked v17 (`marked`)
- Icons: Lucide SVG paths consistent with `@lucide/vue`
- Testing: Vitest via Vite Plus (`npm run test:js`) in `node` environment; `npm run types:check` for TypeScript verification.
- Formatting: Laravel Pint (`vendor/bin/pint --dirty --format agent`)

---

## 7. Risks & Assumptions
- **Risk**: CodeMirror's `RangeSetBuilder` throws if decoration `from` decreases or if line and point decorations at identical positions are inserted out of order.
  - *Mitigation*: On each line in `decorateLine`, all decoration entries (`Decoration.line`, `Decoration.replace`, `Decoration.mark`, `Decoration.widget`) are collected into a line array and sorted by `a.from - b.from || (a.value.startSide - b.value.startSide)` before being added to `builder`. Across lines, line numbers increase monotonically, guaranteeing strict global monotonicity.
- **Risk**: Inline code widgets could cause line reflow or visual clutter when reading.
  - *Mitigation*: Inline copy buttons have `opacity-0` by default and only fade in when hovering over the line/wrapper, focusing via keyboard, or in the copied feedback state. Ultra-compact dimensions (`1.1em` in CM, `h-4 w-4` in HTML) ensure zero disruption to reading flow.
- **Risk**: Unsaved edits in the note could be missed when clicking Copy in the header.
  - *Mitigation*: Header copy reads `editorRef.value?.getText()` (CodeMirror's live doc string) first, falling back to `props.note.content` only when the editor is unmounted.

---

## 8. Requirements Approval
- [x] Requirements fully defined (Revision 2)
- [x] Edge cases identified (unclosed fences, multiple inline codes on same line, task + inline code combinations, read-only mode, unsaved note changes)
- [x] Approved to proceed to Planning (`plan.md`)
