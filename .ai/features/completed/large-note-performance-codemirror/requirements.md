# Requirements: CodeMirror 6 Markdown Editor & Large Note Performance Safeguards

## Metadata
- **Feature Name**: CodeMirror 6 Markdown Editor & Large Note Performance Safeguards
- **Feature ID**: feat-codemirror-perf
- **Author**: System Analyst
- **Created Date**: 2026-10-06
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
Users experience severe UI latency, browser tab freezing, and high CPU usage when opening existing Markdown files with large data (e.g. notes > 100 KB - 1 MB).

Investigation revealed three bottlenecks:
1. **Synchronous Multi-Pass Headless TipTap Instantiation**: `assessMarkdown()` synchronously executes in `onMounted()` of `NoteEditor.vue`. It invokes `marked.lexer`, runs round-trip conversions that instantiate and destroy up to 6 headless `@tiptap/core` `Editor` instances, and performs full AST `JSON.stringify` comparisons on the main thread.
2. **TipTap DOM Overhead (No Virtualization)**: TipTap / ProseMirror renders entire documents as un-virtualized DOM elements. Documents with thousands of lines choke browser paint and layout calculations.
3. **Un-virtualized & Basic Source Mode**: The current `SourceEditor.vue` is a bare `<textarea>` lacking line numbers (despite `preferences.show_line_numbers` existing in `EditorPreferences`), syntax highlighting, and viewport virtualization.

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- **Large-File Safeguard**: Automatically bypass expensive TipTap fidelity assessment (`assessMarkdown`) for notes exceeding a defined threshold (`150 KB`), defaulting directly to high-performance Source mode.
- **CodeMirror 6 Source Editor**: Replace the plain `<textarea>` in `SourceEditor.vue` with a modern, virtualized CodeMirror 6 editor supporting:
  - Viewport virtualization (sub-millisecond line rendering regardless of file size).
  - Markdown / GFM syntax highlighting (`@codemirror/lang-markdown`).
  - Line numbers gutter controlled by `preferences.show_line_numbers`.
  - Tab indentation honoring `preferences.indent_size`.
  - Word wrapping honoring `preferences.word_wrap`.
  - Font styling honoring `preferences.font_family` and `font_size`.
  - Light & dark mode support matching MDVault's theme.
- **Markdown Viewer Mode**: Introduce a fast, rendered preview toggle in Source mode for large files and quick reading without triggering TipTap DOM overhead.
- **Backward Compatibility**: Preserve existing `NoteEditor.vue` dirty-checking, autosave (`noteSaver.ts`), and external conflict resolution semantics.

### Out of Scope (Non-Goals)
- Removing TipTap for standard notes: TipTap remains available for rich-text editing of small-to-medium notes that pass fidelity assessment.
- Changing backend note storage or envelope encoding (`MarkdownService.php` remains unchanged).
- Changing the backend `PREVIEW_LIMIT` (1 MB) in this phase.

---

## 3. User Personas & Stories
- **As a user opening a large markdown file (> 100 KB)**,
  **I want** the note to open instantly without freezing or locking the browser,
  **So that** I can view and edit large technical logs, tables, or notes seamlessly.

- **As a user editing in Source mode**,
  **I want** syntax highlighting, line numbers, and smooth 60 FPS scrolling,
  **So that** I get an IDE-grade markdown editing experience.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | **Large File Bypass** | Notes with file size exceeding `150 KB` must skip `assessMarkdown()` on mount and open directly in Source mode. | Given a note with `file_size > 150_000`, when opened in `NoteEditor.vue`, then `assessMarkdown()` is skipped, `mode` is set to `source`, and a non-blocking informative banner/badge indicates large-file source mode. |
| **FR-02** | **CodeMirror 6 Integration** | `SourceEditor.vue` renders a virtualized CodeMirror 6 instance instead of an HTML `<textarea>`. | Given any note in Source mode, CodeMirror 6 renders with DOM virtualization, maintaining 60 FPS scrolling even on 1 MB+ files. |
| **FR-03** | **Preferences Respect** | CodeMirror 6 binds to `EditorPreferences`: line numbers, font family, font size, indent size, word wrap. | Given `preferences.show_line_numbers = true`, line numbers are rendered in the gutter; given `indent_size = 2`, Tab inserts 2 spaces; given `word_wrap = false`, lines do not wrap. |
| **FR-04** | **Autosave & Content Sync** | CodeMirror 6 integrates cleanly with `defineModel<string>()`, `change` emit, and `getText()` expose. | Given edits in CodeMirror 6, `v-model` updates, `change` fires, `getText()` returns the current document, and `noteSaver` successfully detects changes and autosaves. |
| **FR-05** | **Readonly / Frozen Support** | CodeMirror 6 honors `editable` and `readonly` props during external conflict freezes. | Given `readonly = true` or `editable = false`, CodeMirror 6 is read-only and disallows typing/modifications. |
| **FR-06** | **Fast Markdown Viewer Mode** | Offer a toggle in Source mode to view rendered GFM HTML rendered via `marked`. | Given a note in Source mode, user can toggle between CodeMirror source and rendered HTML preview. |

---

## 5. Non-Functional Requirements
- **Performance**:
  - Time-to-interactive for a 500 KB note must be < 300 ms.
  - Zero UI freezing during file loading.
  - 60 FPS scrolling on large files via CodeMirror viewport windowing.
- **Accessibility & UX**:
  - CodeMirror instance must have appropriate `aria-label="Note source"`.
  - Clean focus outline matching MDVault's Tailwind design tokens.
- **Reliability**:
  - Zero data loss: document content string must remain byte-for-byte identical when untouched.

---

## 6. Technical Constraints & Context
- Framework: Vue 3.5 + Inertia v3 + Vite 8
- Dependencies to add: `codemirror`, `@codemirror/view`, `@codemirror/state`, `@codemirror/language`, `@codemirror/lang-markdown`, `@codemirror/commands`, `@codemirror/theme-one-dark`
- Code Style: Laravel Pint for PHP, ESLint/Prettier/Vite-Plus for TS/Vue
- Test Suite: `npm run test:js` (Vitest via `vp test run`)

---

## 7. Risks & Assumptions
- **Risk**: Adding CodeMirror packages might increase bundle size.
  - **Mitigation**: CodeMirror 6 is modular and tree-shakeable (~150 KB gzip); far lighter than Monaco (~4 MB) and avoids web worker overhead.
- **Risk**: Theme mismatch between light and dark mode.
  - **Mitigation**: Implement a dynamic CodeMirror theme compartment or reactive extension that observes MDVault's dark mode class (`.dark`).

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
