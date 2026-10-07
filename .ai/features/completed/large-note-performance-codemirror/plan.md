# Plan: CodeMirror 6 Markdown Editor & Large Note Performance Safeguards

## Metadata
- **Feature Name**: CodeMirror 6 Markdown Editor & Large Note Performance Safeguards
- **Feature ID**: feat-codemirror-perf
- **Author**: System Analyst
- **Created Date**: 2026-10-06
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Integrate CodeMirror 6 into MDVault to replace the plain `<textarea>` in `SourceEditor.vue` with a high-performance, virtualized Markdown editor featuring syntax highlighting, line numbers, and theme support. Add a large-file safeguard (`150 KB` threshold) in `NoteEditor.vue` to bypass expensive TipTap multi-pass fidelity checks and open large notes instantly in CodeMirror 6.

---

## 2. Architecture & Design
- **Approach**:
  - CodeMirror 6 provides native DOM virtualization (`@codemirror/view` mounts only visible lines).
  - Use dynamic compartments (`Compartment`) for reactive preferences: `lineNumbers`, `EditorView.lineWrapping`, `indentUnit`, and theme (light/dark).
  - Add `LARGE_NOTE_THRESHOLD = 150_000` (150 KB) in `NoteEditor.vue`: any note exceeding this threshold skips `assessMarkdown()` on mount, starts in `source` mode, and shows an informative banner.
  - Add an optional fast Markdown preview toggle in `SourceEditor.vue` using `marked` for reading large documents without ProseMirror DOM overhead.
- **Alternatives Considered**:
  - Monaco Editor: Rejected due to 4-6 MB bundle size and complex web worker requirements.
  - Offloading TipTap to Web Workers: Rejected because TipTap/ProseMirror lacks DOM virtualization, so the browser DOM remains unacceptably slow once rendered.
- **Decision Records**: `.ai/decisions/codemirror-markdown-editor.md`

### Data Model Changes
None. File storage, database schema, and backend APIs remain unchanged.

### Backend Components
None. Backend `NoteService.php` and `MarkdownService.php` continue to deliver note content and envelopes.

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Composable / Helper | `resources/js/lib/editor/codemirror.ts` | Builds CodeMirror extensions, theme compartments, keybindings, and preferences sync |
| Component | `resources/js/components/editor/SourceEditor.vue` | Mounts and manages CodeMirror 6 editor instance, preview toggle, and public API (`getText()`) |
| Component | `resources/js/components/editor/NoteEditor.vue` | Implements `LARGE_NOTE_THRESHOLD` bypass for `assessMarkdown` and displays status badge |

---

## 3. Implementation Tasks

- [ ] **T1 — Install CodeMirror 6 Dependencies**
  - Files: `package.json`
  - Command: `npm install codemirror @codemirror/view @codemirror/state @codemirror/language @codemirror/lang-markdown @codemirror/commands @codemirror/theme-one-dark`
  - Details: Install required CodeMirror 6 ESM modules.
  - Covers: FR-02

- [ ] **T2 — CodeMirror 6 Config & Extensions Helper**
  - Files: `resources/js/lib/editor/codemirror.ts`
  - Details: Helper module configuring compartments for line numbers (`preferences.show_line_numbers`), tab indentation (`preferences.indent_size`), word wrap (`preferences.word_wrap`), theme (light/dark), and GFM markdown highlighting.
  - Covers: FR-02, FR-03

- [ ] **T3 — Upgrade `SourceEditor.vue` to CodeMirror 6**
  - Files: `resources/js/components/editor/SourceEditor.vue`
  - Details: Mount CodeMirror 6 in the DOM container, watch `props.preferences`, `props.readonly`, and `props.editable`, dispatch view transactions to update `v-model:text`, emit `change`, and expose `getText()`. Add a fast HTML Preview toggle button.
  - Covers: FR-02, FR-03, FR-04, FR-05, FR-06

- [ ] **T4 — Large File Safeguard in `NoteEditor.vue`**
  - Files: `resources/js/components/editor/NoteEditor.vue`
  - Details: Add `LARGE_NOTE_THRESHOLD = 150_000`. If `note.file_size > LARGE_NOTE_THRESHOLD`, skip `assessMarkdown()`, set `mode.value = 'source'`, set `assessment.value = null`, and render an informative banner explaining that the note opened in high-performance source mode.
  - Covers: FR-01

- [ ] **T5 — Unit & Integration Tests**
  - Files: `tests/js/editor/sourceEditor.test.ts`, `tests/js/editor/largeNoteThreshold.test.ts`
  - Details: Test CodeMirror configuration helper, preference reactions, threshold bypass logic, and editor API compliance.
  - Covers: FR-01, FR-03, FR-04

- [ ] **T6 — Code Style & Type Verification**
  - Files: All changed files
  - Commands: `npm run types:check`, `npm run test:js`, `vendor/bin/pint --dirty --format agent`
  - Details: Ensure zero TypeScript errors and all tests pass.
  - Covers: All

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/codemirror.test.ts` | Verify extensions build with correct indent, wrap, line numbers | FR-02, FR-03 |
| `tests/js/editor/largeNoteThreshold.test.ts` | Verify notes > 150 KB bypass `assessMarkdown` and route to source mode | FR-01 |
| Existing `tests/js/editor/*` | Regression check existing autosave, saveTransport, copyTransport | FR-04 |

**Test scope for QA**:
- `npm run test:js`
- `npm run types:check`
- `php artisan test --compact tests/Feature/Notes/`

---

## 5. Risks & Mitigations
- **Risk**: CodeMirror style conflict with Tailwind dark mode.
  - **Mitigation**: Use CodeMirror `EditorView.theme` and inspect the `.dark` class on `document.documentElement` to toggle between light theme and `oneDark`.
- **Risk**: Event loops between CodeMirror updateListener and `defineModel<string>()`.
  - **Mitigation**: Guard external prop updates: only dispatch transactions to CodeMirror if the incoming string differs from `view.state.doc.toString()`.

---

## 6. Open Questions
None. All design requirements and interfaces are settled.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-06 | Initial plan | Complete architecture for CM6 & large-note bypass |
