# ADR: CodeMirror 6 Markdown Editor & Large Note Performance Safeguards

- **Status**: Proposed / Accepted
- **Date**: 2026-10-06
- **Context**: Performance degradation and UI freezes when opening large markdown files in MDVault

## Context
- MDVault users report severe browser freezes and slow load times when opening existing `.md` files with large content (e.g. > 100 KB).
- Root-cause profiling showed:
  1. `NoteEditor.vue` runs `assessMarkdown` synchronously in `onMounted()`.
  2. `assessMarkdown` executes multiple headless TipTap/ProseMirror conversions (`converter.roundTrip()` creating and destroying up to 6 `@tiptap/core` `Editor` instances per call) and deep `JSON.stringify` AST comparisons.
  3. TipTap renders full DOM trees for every block element without virtualization.
  4. `SourceEditor.vue` was a basic HTML `<textarea>` without viewport virtualization, line numbers (despite `preferences.show_line_numbers`), or syntax highlighting.

## Options Considered

1. **Keep TipTap for all files, optimize `assessMarkdown` in Web Workers**:
   - *Pros*: Keeps WYSIWYG for everything.
   - *Cons*: TipTap / ProseMirror has no viewport DOM virtualization. Even if parsing moves off-thread, rendering 10,000+ DOM nodes in TipTap causes severe layout, paint, and memory lag in the browser.
2. **Replace Source Mode with Monaco Editor (VS Code)**:
   - *Pros*: Full-featured IDE editor.
   - *Cons*: Extremely heavy bundle (~4-6 MB), requires web workers, complex Vite configuration, overkill for markdown note editing.
3. **Adopt CodeMirror 6 for Source Mode + Large File Bypass (Chosen)**:
   - *Pros*:
     - **Native Viewport Virtualization**: Only mounts DOM nodes for lines currently visible in the viewport. 1 MB+ files scroll smoothly at 60 FPS.
     - **Incremental Parsing**: Lezer markdown parser (`@codemirror/lang-markdown`) parses syntax incrementally as you scroll and type.
     - **Lightweight & Modular**: ~150-250 KB ESM bundle, zero web worker setup required.
     - **Direct Preference Mapping**: Natively supports line numbers gutter, tab indentation, word wrapping, font styling.
     - **Preserves WYSIWYG for Normal Files**: Small-to-medium files (< 150 KB) continue to enjoy TipTap WYSIWYG; large files bypass heavy assessment and open instantly in CodeMirror 6.

## Decision
1. **Large File Safeguard Threshold (`150 KB`)**:
   - In `NoteEditor.vue`, if `note.file_size > 150_000`, `assessMarkdown()` is skipped completely.
   - The editor sets `mode = 'source'` immediately and displays an alert indicating high-performance source mode.
2. **Upgrade `SourceEditor.vue` to CodeMirror 6**:
   - Replace `<textarea>` with a CodeMirror 6 instance wrapped in a Vue 3 component.
   - Integrate `@codemirror/view`, `@codemirror/state`, `@codemirror/language`, `@codemirror/lang-markdown`, `@codemirror/commands`, `@codemirror/theme-one-dark`.
   - Reactively update extensions when `preferences` change (line numbers, word wrap, indent size).
   - Maintain the identical component API: `v-model:text`, `change` emit, `editable`, `readonly`, and `getText()`.
3. **Fast Markdown Preview / Viewer**:
   - Provide an optional rendered HTML preview toggle in Source mode using `marked` for fast reading of large documents without ProseMirror DOM overhead.

## Consequences
- **Positive**:
  - Instantaneous opening (< 300 ms) of large markdown files.
  - Zero UI lockup or main-thread freezing.
  - Modern, IDE-grade source editing experience with line numbers, syntax highlighting, and smooth scrolling.
- **Negative / Trade-offs**:
  - Adds CodeMirror 6 packages to `package.json`.
  - Notes > 150 KB cannot be edited in TipTap rich-text mode (an acceptable trade-off given ProseMirror's lack of virtualization).
