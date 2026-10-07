# Plan: CodeMirror 6 Unified Rich Markdown Editor

## Metadata
- **Feature Name**: CodeMirror 6 Unified Rich Markdown Editor
- **Feature ID**: feat-codemirror-unified
- **Author**: System Analyst
- **Created Date**: 2026-10-07
- **Task Complexity**: Level 4 — Architectural
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Replace TipTap / ProseMirror with CodeMirror 6 as MDVault's single default editor. Implement rich Live-Preview formatting (headings, interactive task checkboxes, code fences, blockquotes) via CodeMirror 6 decorations, adapt the formatting toolbar to dispatch CodeMirror commands, and remove the obsolete fidelity check / converter pipeline.

---

## 2. Architecture & Design
- **Unified Editor Architecture**:
  - `SourceEditor.vue` becomes the primary `CodeMirrorEditor.vue` (or enhanced `SourceEditor.vue`) mounted in `NoteEditor.vue`.
  - Removes the `rich` vs. `source` mode bifurcation and fidelity checks (`assessMarkdown`).
  - View modes become `Editor` (CodeMirror with Live Preview), `Split` (Editor + Rendered Preview), and `Preview` (Rendered Preview).
- **Rich Markdown Formatting & Commands**:
  - `resources/js/lib/editor/codemirrorCommands.ts`: Helper functions to wrap or insert Markdown syntax for bold (`**`), italic (`*`), strikethrough (`~~`), code (`` ` ``), headings (`# `), lists (`- `, `1. `, `- [ ] `), quotes (`> `), links (`[text](url)`), and tables.
  - `resources/js/lib/editor/codemirrorLivePreview.ts`: CodeMirror 6 `ViewPlugin` applying visual line decorations for headings, task list checkboxes, blockquotes, and code fences.
- **Toolbar Binding**:
  - `EditorToolbar.vue` emits formatting commands to the active CodeMirror editor view.

---

## 3. Implementation Tasks

- [ ] **T1 — CodeMirror Rich Text Commands**
  - Files: `resources/js/lib/editor/codemirrorCommands.ts`
  - Details: Implement `toggleBold`, `toggleItalic`, `toggleStrike`, `toggleCode`, `toggleHeading`, `toggleBulletList`, `toggleOrderedList`, `toggleTaskList`, `toggleBlockquote`, `insertCodeBlock`, `insertTable`, `insertLink`.
  - Covers: FR-04

- [ ] **T2 — CodeMirror Live Preview Decorations Extension**
  - Files: `resources/js/lib/editor/codemirrorLivePreview.ts`
  - Details: Build a CodeMirror 6 `ViewPlugin` with `DecorationSet` providing heading line typography, interactive task checkboxes (click toggles document text between `- [ ]` and `- [x]`), blockquote borders, and code fence boxes.
  - Covers: FR-02, FR-03

- [ ] **T3 — Integrate Live Preview into `codemirror.ts`**
  - Files: `resources/js/lib/editor/codemirror.ts`
  - Details: Add the live preview extension to `buildCodeMirrorExtensions`.
  - Covers: FR-02

- [ ] **T4 — Update `EditorToolbar.vue` to control CodeMirror**
  - Files: `resources/js/components/editor/EditorToolbar.vue`
  - Details: Connect toolbar buttons to CodeMirror editor view commands.
  - Covers: FR-04

- [ ] **T5 — Simplify `NoteEditor.vue` & Retire TipTap**
  - Files: `resources/js/components/editor/NoteEditor.vue`
  - Details: Make CodeMirror the default and only editor. Remove `assessMarkdown`, `reformatAccepted`, and TipTap mount. Add top toolbar to CodeMirror editor. Connect autosave baseline directly to raw note content.
  - Covers: FR-01, FR-05, FR-06, FR-07

- [ ] **T6 — Unit & Regression Tests**
  - Files: `tests/js/editor/codemirrorCommands.test.ts`, `tests/js/editor/codemirrorLivePreview.test.ts`
  - Details: Test all formatting commands and decoration logic.
  - Covers: All

- [ ] **T7 — Code Quality & Test Verification**
  - Commands: `npm run types:check`, `npm run test:js`, `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`
  - Covers: All

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/codemirrorCommands.test.ts` | Bold, Italic, Headings, Lists, Tasks, Blockquotes formatting commands | FR-04 |
| `tests/js/editor/codemirrorLivePreview.test.ts` | Line decorations for headings, task checkboxes, code blocks | FR-02, FR-03 |
| Existing noteSaver and transport tests | Ensure autosave, copy, and conflict handling pass cleanly | FR-07 |

**Test scope for QA**:
- `npm run test:js`
- `npm run types:check`
- `php artisan test --compact tests/Feature/Notes/`
