# Plan: Code Block & Inline Code Copy Buttons

## Metadata
- **Feature Name**: Code Block & Inline Code Copy Buttons
- **Feature ID**: feat-code-block-copy
- **Author**: System Analyst
- **Created Date**: 2026-11-07
- **Revision**: 2
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md` (Revision 2)
- **Status**: APPROVED

---

## 1. Summary
Expand the MDVault copy functionality to provide three unified copying mechanisms:
1. **Fenced Code Blocks**: Copy button on multi-line fenced code blocks in both Rendered Preview (`.code-block-wrapper`) and CodeMirror Live Preview (`CodeBlockCopyWidget` on opening fence).
2. **Inline Code Spans**: Compact copy button for single-backtick code spans in both Rendered Preview (`codespan` renderer in `markdownPreview.ts`, click delegation in `MarkdownEditor.vue`, CSS in `app.css`) and CodeMirror Live Preview (`InlineCodeCopyWidget` at `InlineCode.to` with monotonic `RangeSetBuilder` ordering in `codemirrorLivePreview.ts`).
3. **Full Note Content**: Copy button in the Note header next to the Save button in `NoteEditor.vue` using `readContent()` and `copyToClipboard` with 2-second visual feedback.

---

## 2. Architecture & Design

### Approach

#### 1. Clipboard Utility & Feedback (`clipboard.ts`, `copyButton.ts`)
- `copyToClipboard(text: string): Promise<boolean>` in `resources/js/lib/editor/clipboard.ts` uses `navigator.clipboard.writeText` with an invisible `textarea` fallback.
- `resources/js/lib/editor/copyButton.ts` exports `COPY_ICON_SVG`, `CHECK_ICON_SVG`, and `triggerCopyFeedback(button, code, durationMs)` to handle icon transitions, `aria-label` updates, and timeout cleanup via a `WeakMap`.

#### 2. Rendered Markdown Preview (`markdownPreview.ts`, `MarkdownEditor.vue`, `app.css`)
- In `markdownPreview.ts`:
  - Fenced code blocks: wrapped in `.code-block-wrapper` with `<button type="button" class="code-copy-btn" data-code-copy>`.
  - Inline code spans: override Marked's `codespan({ text }: Tokens.Codespan)`:
    ```html
    <code class="inline-code-wrapper group"><span class="inline-code-text">${escapeHtml(text)}</span><button type="button" class="inline-code-copy-btn" aria-label="Copy code" title="Copy code" data-inline-code-copy>${COPY_ICON_SVG}</button></code>
    ```
- In `MarkdownEditor.vue`:
  - `handlePreviewClick(event: MouseEvent)` checks `target.closest('button[data-inline-code-copy]')`. When matched, finds parent `.inline-code-wrapper`, retrieves text from `.inline-code-text`, and calls `triggerCopyFeedback`.
- In `resources/css/app.css`:
  - `.markdown-content .inline-code-wrapper`: `@apply relative inline-flex items-center gap-1 align-baseline;`
  - `.markdown-content .inline-code-copy-btn`: compact `@apply inline-flex h-4 w-4 cursor-pointer items-center justify-center rounded border border-border/70 bg-muted/80 text-muted-foreground opacity-0 transition-all duration-150 hover:bg-accent hover:text-foreground focus-visible:opacity-100 focus-visible:outline-1 focus-visible:outline-ring;`
  - `.markdown-content .inline-code-copy-btn svg`: `@apply h-2.5 w-2.5;`
  - Visible on `.inline-code-wrapper:hover`, `:focus-visible`, and `.copied`.

#### 3. CodeMirror Live Preview (`codemirrorLivePreview.ts`)
- Pure function `extractInlineCodeContent(state: EditorState, inlineCode: SyntaxNode): string`:
  - Checks if `firstChild` and `lastChild` are `CodeMark` nodes and `firstChild !== lastChild`.
  - Slices `state.sliceDoc(firstChild.to, lastChild.from)`.
  - Fallback regex strips leading/trailing backticks if no child mark nodes exist.
- `InlineCodeCopyWidget extends WidgetType`:
  - `eq(other)` compares `this.code === other.code`.
  - `ignoreEvent()` returns `true`.
  - `toDOM()` creates `<button type="button" class="cm-md-inline-copy-btn" aria-label="Copy code" title="Copy code">` with `COPY_ICON_SVG`.
  - `mousedown` and `click` handlers call `event.preventDefault()` and `event.stopPropagation()` so CodeMirror's caret never moves.
- Decoration Monotonic Ordering in `decorateLine`:
  - Collects all decorations for the line into an array `lineDecos: { from: number; to: number; value: Decoration }[]`.
  - Finds any `InlineCode` node whose `node.to` is on `line.number` via `tree.iterate({ from: line.from, to: line.to })`.
  - Sorts `lineDecos` by `a.from - b.from || (a.value.startSide - b.value.startSide)`.
  - Adds each decoration to `builder.add(deco.from, deco.to, deco.value)`.
  - Strictly preserves `RangeSetBuilder` invariant: non-decreasing `from` across line decorations, task markers, task-done marks, and inline code widgets.
- Live Preview Theme:
  - Adds `.cm-md-inline-copy-btn` rules: `inline-flex`, `width: '1.1em'`, `height: '1.1em'`, `marginLeft: '0.25em'`, `verticalAlign: 'middle'`, `opacity: '0'` fading in on `.cm-line:hover`, `:focus-visible`, and `.copied`.
  - Zero vertical margins, em-relative sizing, no Tailwind utility classes.

#### 4. Full Note Content Copy (`NoteEditor.vue`)
- In `resources/js/components/editor/NoteEditor.vue`:
  - Add Copy button next to Save button in the header action container.
  - Button uses `variant="outline"`, `size="sm"`, with `copiedNote` ref.
  - When `copiedNote` is false: `Copy` icon + text "Copy" + `aria-label="Copy note"`.
  - When `copiedNote` is true: `Check` icon + text "Copied" + `aria-label="Copied note"`.
  - `copyNoteContent()` gets text from `readContent()` (`editorRef.value?.getText()`) with fallback to `props.note.content ?? ''`, executes `copyToClipboard(content)`, and triggers the 2000ms feedback timeout.
  - Cleanup timeout on `onUnmounted`.

---

## 3. Implementation Tasks

- [ ] **T1 — Verify Clipboard Utility & Copy Helper**
  - Files: `resources/js/lib/editor/clipboard.ts`, `resources/js/lib/editor/copyButton.ts`
  - Details:
    - Ensure `copyToClipboard` and `triggerCopyFeedback` handle both code snippets and full note text.
  - Covers: [FR-02, FR-06]

- [ ] **T2 — Configure Marked Inline Code & Fenced Code Renderers in `markdownPreview.ts`**
  - Files: `resources/js/lib/editor/markdownPreview.ts`
  - Details:
    - Add `codespan({ text }: Tokens.Codespan)` to Marked renderer:
      `<code class="inline-code-wrapper group"><span class="inline-code-text">${escapeHtml(text)}</span><button type="button" class="inline-code-copy-btn" aria-label="Copy code" title="Copy code" data-inline-code-copy>${COPY_ICON_SVG}</button></code>`.
    - Ensure `escapeHtml` properly escapes HTML entities (`&`, `<`, `>`, `"`, `'`).
  - Covers: [FR-07]

- [ ] **T3 — Add Preview Click Handling & Compact CSS Styling in `MarkdownEditor.vue` & `app.css`**
  - Files: `resources/js/components/editor/MarkdownEditor.vue`, `resources/css/app.css`
  - Details:
    - In `MarkdownEditor.vue`'s `handlePreviewClick`:
      Detect `button[data-inline-code-copy]`, extract `.inline-code-text` textContent, prevent default, and call `triggerCopyFeedback`.
    - In `resources/css/app.css`:
      Add styles for `.markdown-content .inline-code-wrapper`, `.markdown-content .inline-code-copy-btn`, `.markdown-content .inline-code-copy-btn svg`, `.inline-code-copy-btn.copied`, and hover/focus transitions.
  - Covers: [FR-07]

- [ ] **T4 — Implement CodeMirror Inline Code Extraction, Widget & Monotonic Decoration Ordering in `codemirrorLivePreview.ts`**
  - Files: `resources/js/lib/editor/codemirrorLivePreview.ts`
  - Details:
    - Implement and export `extractInlineCodeContent(state: EditorState, inlineCode: SyntaxNode): string`: extracts inner text between `CodeMark` children with fallback regex.
    - Implement `InlineCodeCopyWidget extends WidgetType`:
      - `eq(other)` compares code strings.
      - `ignoreEvent()` returns `true`.
      - `toDOM()` creates `.cm-md-inline-copy-btn` with `COPY_ICON_SVG`, stopping `mousedown` and `click` propagation and calling `triggerCopyFeedback`.
    - Refactor `decorateLine` to collect all line decorations, task markers, task-done marks, and `InlineCode` widgets on that line into an array, sorted by `a.from - b.from || (a.value.startSide - b.value.startSide)` before calling `builder.add()`.
    - In `livePreview()` theme:
      - Add `.cm-md-inline-copy-btn` (`position: relative`, `display: inline-flex`, `width: 1.1em`, `height: 1.1em`, `marginLeft: 0.25em`, `verticalAlign: middle`, `opacity: 0`, hover/focus/copied states).
      - Add `.cm-md-inline-copy-btn svg` (`width: 0.75em`, `height: 0.75em`).
  - Covers: [FR-08, FR-09, FR-05]

- [ ] **T5 — Implement Copy Note Content Button in Note Header**
  - Files: `resources/js/components/editor/NoteEditor.vue`
  - Details:
    - Import `Check`, `Copy` from `@lucide/vue` and `copyToClipboard` from `@/lib/editor/clipboard`.
    - Add a Copy Note button in the header action container next to the Save button:
      `<Button size="sm" variant="outline" class="h-8 gap-1.5 px-3 text-xs shadow-xs" :aria-label="copiedNote ? 'Copied note' : 'Copy note'" :title="copiedNote ? 'Copied note' : 'Copy note'" @click="copyNoteContent">`.
    - Implement `copyNoteContent()`: reads `readContent()` or `props.note.content`, calls `copyToClipboard`, sets `copiedNote = true` for 2000ms.
    - Clean up timeout on `onUnmounted`.
  - Covers: [FR-10, FR-06]

- [ ] **T6 — Unit Tests for Markdown Preview Inline Code & Fenced Code**
  - Files: `tests/js/editor/markdownPreview.test.ts`
  - Details:
    - Test `renderMarkdownPreview` with inline code: asserts output contains `.inline-code-wrapper`, `.inline-code-text`, and `data-inline-code-copy`.
    - Test that special characters (`<tag>`, `"quotes"`, `&`) in inline code are properly escaped.
    - Test that code block rendering with copy button remains intact.
  - Covers: [FR-01, FR-07]

- [ ] **T7 — Unit Tests for CodeMirror Inline Code Extraction & Monotonic Decorations**
  - Files: `tests/js/editor/codemirrorLivePreview.test.ts`
  - Details:
    - Test `extractInlineCodeContent`:
      - Single backticks: `` `const x = 1;` `` -> `'const x = 1;'`.
      - Multiple backticks: ```` ``hello ` world`` ```` -> ``hello ` world``.
      - Inner spaces: `` ` foo ` `` -> `' foo '`.
      - Empty inline code.
    - Test `markdownLineDecorations`:
      - Line with inline code emits `InlineCodeCopyWidget` at `node.to`.
      - Line with both task and inline code: `- [x] Task with `code`` (verifies `RangeSetBuilder` does not throw and order is strictly non-decreasing).
      - Line with multiple inline codes: `` `foo` and `bar` `` (emits two widgets in ascending position order).
      - Line with heading and inline code: `# Heading with `code``.
      - Verifies no Tailwind utility classes are emitted in theme decoration classes.
  - Covers: [FR-03, FR-04, FR-08, FR-09, FR-05]

---

## 4. Test Plan

| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/clipboard.test.ts` | `copyToClipboard` and `triggerCopyFeedback` handle clipboard copying and visual feedback transitions | [FR-02, FR-06] |
| `tests/js/editor/markdownPreview.test.ts` | Fenced code blocks wrapped with copy button; inline code spans wrapped with compact copy button and escaped text | [FR-01, FR-07] |
| `tests/js/editor/codemirrorLivePreview.test.ts` | `extractFencedCodeContent` extracts inner code lines omitting fences | [FR-04] |
| `tests/js/editor/codemirrorLivePreview.test.ts` | `extractInlineCodeContent` extracts inner code omitting `CodeMark` backticks | [FR-09] |
| `tests/js/editor/codemirrorLivePreview.test.ts` | `markdownLineDecorations` emits copy widgets for fenced code and inline code | [FR-03, FR-08] |
| `tests/js/editor/codemirrorLivePreview.test.ts` | Complex lines (task checkbox + done strike-through + multiple inline codes) preserve monotonic `RangeSetBuilder` order without throwing | [FR-08, FR-09] |
| `tests/js/editor/codemirrorLivePreview.test.ts` | Theme classes contain no Tailwind utilities and maintain measurement safety | [FR-05] |

**Test scope for QA**:
- `npm run test:js tests/js/editor/`
- `npm run types:check`
- Manual verification in browser:
  - Preview & Split view: Hover over inline code and fenced code, click copy button, verify clipboard and checkmark feedback.
  - Code & Split view: Click inline copy widget and fence copy widget, verify caret does not move, verify clipboard content.
  - Note Header: Click Copy button next to Save button, verify entire note is copied, verify icon toggles to Check for 2s.

---

## 5. Risks & Mitigations
- **Risk**: CodeMirror's `RangeSetBuilder` throws if decoration `from` decreases or if line and point decorations at identical positions are inserted out of order.
  - *Mitigation*: On each line in `decorateLine`, all decoration entries (`Decoration.line`, `Decoration.replace`, `Decoration.mark`, `Decoration.widget`) are collected into a line array and sorted by `a.from - b.from || (a.value.startSide - b.value.startSide)` before adding to `builder`. Across lines, line numbers increase monotonically, guaranteeing global monotonicity.
- **Risk**: Caret jumping / document mutation when clicking widgets in CodeMirror.
  - *Mitigation*: `ignoreEvent() => true` and `e.preventDefault() / e.stopPropagation()` on both `mousedown` and `click` handlers ensure CodeMirror's caret handler never triggers.
- **Risk**: Unsaved edits in the note could be missed when clicking Copy in the header.
  - *Mitigation*: Header copy reads `editorRef.value?.getText()` (CodeMirror's live doc string) first, falling back to `props.note.content` only when the editor is unmounted.
- **Risk**: Visual clutter from inline buttons during normal reading.
  - *Mitigation*: Inline copy buttons have `opacity-0` by default and only fade in on hover, focus-visible, or copied state. Ultra-compact dimensions (`1.1em` in CM, `h-4 w-4` in HTML) ensure zero disruption.

---

## 6. Open Questions
- None. Requirements and architecture are fully specified.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-07 | Initial plan | Multi-line fenced code block copy button (Preview & CodeMirror Live Preview). |
| 2 | 2026-11-07 | User Request (Rev 2) | Expand to inline code copy button (`codespan` in Preview, `InlineCode` widget in CodeMirror with monotonic line decoration sorting) and full note content copy button in Note Editor header. |
