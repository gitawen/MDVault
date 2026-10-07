# ADR: Unifying MDVault on CodeMirror 6 with Live Rich Markdown

- **Status**: Accepted
- **Date**: 2026-10-07
- **Replaces**: `.ai/decisions/markdown-conversion-and-fidelity.md`

## Context
- MDVault originally adopted TipTap / ProseMirror for rich-text editing and maintained a plain `<textarea>` for "source mode".
- Under this architecture, opening any note required a multi-pass fidelity check (`assessMarkdown`), which:
  1. Ran up to 6 headless ProseMirror editor creations/destructions per open.
  2. Froze browser threads on large notes (> 100 KB).
  3. Forced notes with foreign or complex formatting into a modal consent flow ("reformat on save") or dropped them into raw source mode.
- In addition, ProseMirror lacks DOM virtualization, causing frame drops and typing latency when notes grow beyond a few hundred lines.
- CodeMirror 6 provides native DOM virtualization, sub-millisecond line recycling, an incremental parser (`@lezer/markdown`), and a powerful decoration extension engine (`ViewPlugin` / `DecorationSet`).

## Decision
1. **Retire TipTap as the editor engine**:
   - Make CodeMirror 6 the **single, unified editor engine** for all notes.
   - Delete the rich vs. source mode bifurcation.
2. **Implement Live-Preview Rich Markdown**:
   - Provide rich visual feedback directly within the CodeMirror buffer using CodeMirror decorations:
     - Heading lines scale dynamically (H1 = 2em, H2 = 1.5em, H3 = 1.25em) with divider borders.
     - Interactive task list checkbox widgets for `- [ ]` and `- [x]`.
     - Code block background containers and borders.
     - Blockquote accent borders.
3. **Connect the Formatting Toolbar to CodeMirror**:
   - Adapt `EditorToolbar.vue` to dispatch Markdown transformations into CodeMirror:
     - Inline marks (bold, italic, strikethrough, inline code).
     - Block structures (headings, bullet lists, ordered lists, task lists, quotes, tables, code blocks).
     - Undo and redo history commands.
4. **Preserve Split and Full Reading Views**:
   - Maintain the fast, rendered HTML preview (`marked`) with Code / Split / Preview views for users wanting side-by-side or read-only reading experiences.
5. **Autosave and Fidelity**:
   - Autosave (`createNoteSaver`) directly saves the verbatim document string without any AST normalization or silent rewrites. Zero risk of fidelity loss.

## Consequences
- **Positive**:
  - Sub-50ms opening times for notes of any size.
  - Zero "reformat on save" warnings or consent dialogues.
  - 60 FPS typing and scrolling regardless of file length.
  - Eliminates heavy `@tiptap/*` dependencies and hundreds of lines of brittle round-trip converter logic.
- **Negative / Trade-offs**:
  - Requires implementing CodeMirror formatting helpers and decoration plugins.
  - ProseMirror WYSIWYG tables (row/column insertion popovers) are replaced by standard Markdown table editing.
