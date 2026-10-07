# Requirements: CodeMirror 6 Unified Rich Markdown Editor

## Metadata
- **Feature Name**: CodeMirror 6 Unified Rich Markdown Editor
- **Feature ID**: feat-codemirror-unified
- **Author**: System Analyst
- **Created Date**: 2026-10-07
- **Task Complexity**: Level 4 — Architectural
- **Status**: APPROVED

---

## 1. Problem Statement
TipTap (ProseMirror-based) is currently used as the default rich-text editor in MDVault. This introduces severe architectural trade-offs:
1. **Severe Performance Bottlenecks**: Headless TipTap conversion (`assessMarkdown`) creates up to 6 `@tiptap/core` instances per note open, stringifying megabytes of JSON trees and causing browser tab freezes on files over 100 KB.
2. **Lossy Conversion & User Friction**: Because TipTap converts Markdown to ProseMirror JSON and back, user formatting is frequently flagged as "reformat on save" or "unsupported", requiring user consent dialogs and mode switches.
3. **No DOM Virtualization**: ProseMirror renders every block element as a real DOM node, causing memory bloat and scroll latency on long notes.

CodeMirror 6 has proven to provide 60 FPS performance, DOM virtualization, and instant file loading. By adopting CodeMirror 6 as the **unified default editor with Live-Preview rich decorations and a rich formatting toolbar**, MDVault can eliminate TipTap entirely while delivering a superior editing experience (similar to Obsidian and Typora).

---

## 2. Goals & Non-Goals

### In Scope (Goals)
- **Make CodeMirror 6 the Default & Only Editor**: Replace TipTap across MDVault with a unified, high-performance Markdown editor.
- **Rich-Text Live Preview Formatting**:
  - Headings rendered with visual hierarchy (larger sizes, bold weights, dividers).
  - Task lists (`- [ ]` / `- [x]`) rendered with clickable interactive checkbox widgets.
  - Fenced code blocks rendered with container borders and backgrounds.
  - Blockquotes and horizontal rules visually styled.
- **Formatting Toolbar Integration**:
  - Retain the top formatting toolbar (`EditorToolbar.vue`) with Bold, Italic, Strikethrough, Headings (H1-H3), Lists (Bullet, Ordered, Task), Blockquote, Code, Code Block, Table, and Link buttons that dispatch Markdown editing transactions into CodeMirror with undo/redo support.
- **Unified View Modes**:
  - Provide effortless switching between `Editor` (CodeMirror with Live Preview), `Split` (Editor + Rendered Preview), and `Reading` (Full Rendered Preview).
- **Remove TipTap Overhead**:
  - Eliminate `assessMarkdown()`, "reformat on save" warnings, and mode-switching friction (`rich` vs `source`). The file on disk is the direct source of truth.

### Out of Scope (Non-Goals)
- Modifying backend storage or encryption algorithms.
- Changing note metadata or frontmatter handling (raw YAML frontmatter remains byte-preserved).

---

## 3. User Personas & Stories
- **As a user editing a note of any size**,
  **I want** the note to open instantly without conversion delays or "reformat" warnings,
  **So that** my writing flow is smooth and uninterrupted.

- **As a non-technical or rich-text user**,
  **I want** a rich formatting toolbar and visual live styling (headings, bold text, clickable checkboxes),
  **So that** I don't have to manually memorize or type Markdown syntax.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | **Unified CodeMirror Editor** | `NoteEditor.vue` uses CodeMirror 6 as its single, default editor engine. | Opening any note mounts CodeMirror 6 directly; TipTap is removed. |
| **FR-02** | **Live Rich Preview Decorations** | CodeMirror 6 dynamically decorates Markdown syntax: headings are larger, blockquotes styled, code fences bordered. | Heading lines visually render with heading typography; code blocks render with container styling. |
| **FR-03** | **Interactive Task Checkboxes** | Task list items (`- [ ]` and `- [x]`) display interactive checkbox widgets in the editor. | Clicking a task checkbox toggles the bracket character between `[ ]` and `[x]` in the document and triggers autosave. |
| **FR-04** | **Rich Formatting Toolbar** | `EditorToolbar.vue` buttons apply formatting to CodeMirror selections. | Selecting text and clicking **Bold** wraps text in `**`; clicking **Heading** inserts `# `; clicking **Task List** inserts `- [ ] `. |
| **FR-05** | **Elimination of Conversion Warnings** | `assessMarkdown()` and reformat consent dialogs are completely removed. | Notes open immediately without "Checking formatting..." or "Reformat on save" prompts. |
| **FR-06** | **Dual & Split View Modes** | Retain fast toggle between Editor, Split (side-by-side), and Full Reading Preview. | User can toggle between Editor, Split, and Preview seamlessly with persistent preference. |
| **FR-07** | **Zero Data Loss Autosave** | Autosave operates directly on the raw Markdown text, preserving byte-exact fidelity. | Editing text triggers existing `createNoteSaver` debounce and saves cleanly to backend. |

---

## 5. Non-Functional Requirements
- **Performance**: Instantaneous file loading (< 50ms) for notes of all sizes.
- **Reliability**: No syntax mangling or unintended escapes.
- **Code Health**: Removal of unused `@tiptap/*` dependencies and obsolete conversion code.
