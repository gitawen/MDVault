# QA Report: Mermaid Diagram Editor and View

## Metadata
- **Feature Name**: Mermaid Diagram Editor and View
- **Feature ID**: feat-mermaid-editor-view
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-11-15
- **Verdict**: FAIL

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01: Mermaid Code Block Parsing in Preview Pipeline | yes | `tests/js/editor/markdownPreview.test.ts` | ✅ |
| FR-02: Dynamic On-Demand Mermaid Loading | yes | `tests/js/editor/mermaid.test.ts`, `resources/js/lib/editor/mermaidRenderer.ts` | ✅ |
| FR-03: Interactive Diagram View with Actions | partial | `tests/js/editor/markdownPreview.test.ts`, `resources/js/components/editor/MarkdownEditor.vue` | ❌ (QA-01, QA-02) |
| FR-04: Dedicated Mermaid Editor Dialog (`MermaidDialog.vue`) | yes | `resources/js/components/editor/MermaidDialog.vue` | ✅ |
| FR-05: Preset Diagram Templates | yes | `tests/js/editor/mermaid.test.ts` | ✅ |
| FR-06: Toolbar Command & Keymap Integration | yes | `tests/js/editor/toolbarCommands.test.ts`, `tests/js/editor/codemirrorCommands.test.ts` | ✅ |
| FR-07: Theme Harmonization & Dynamic Re-rendering | yes | `tests/js/editor/mermaid.test.ts`, `resources/js/lib/editor/mermaidRenderer.ts` | ✅ |
| FR-08: Graceful Syntax Error Handling in Preview | yes | `resources/js/lib/editor/mermaidRenderer.ts`, `resources/js/components/editor/MermaidDialog.vue` | ✅ |
| FR-09: Strict Security & XSS Prevention | yes | `tests/js/editor/markdownPreview.test.ts`, `tests/js/editor/mermaid.test.ts` | ✅ |
| FR-10: SVG Caching & Split-View Debounce | yes | `tests/js/editor/mermaid.test.ts`, `resources/js/lib/editor/mermaidRenderer.ts` | ✅ |
| FR-11: CodeMirror Commands & Block Detection | yes | `tests/js/editor/codemirrorCommands.test.ts` | ✅ |
| FR-12: Headless Vitest Test Suite Compatibility | yes | `tests/js/editor/*.test.ts` (18 test files, 250 tests in node env) | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `npm.cmd exec vp test run tests/js/editor/markdownPreview.test.ts tests/js/editor/codemirrorCommands.test.ts tests/js/editor/mermaid.test.ts tests/js/editor/toolbarCommands.test.ts` | 4 test files passed (4), 118 tests passed (118) |
| `npm.cmd run test:js` | 18 test files passed (18), 250 tests passed (250) |
| `npm.cmd run types:check` | 0 TypeScript errors |
| `npm.cmd exec vp check resources/js/lib/editor/mermaid* resources/js/components/editor/MermaidDialog.vue tests/js/editor/mermaid.test.ts` | pass: all files correctly formatted, 0 warnings/lint errors |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | High | MINOR | `resources/js/components/editor/MarkdownEditor.vue:386` and `resources/js/components/editor/EditorToolbar.vue:143` | Clicking "Edit diagram" (`data-mermaid-edit`) on a rendered diagram in the preview pane invokes `openMermaidDialog(source)` without a target document `range`. Consequently, `isEditingExisting` is `false`, the dialog displays "Insert Diagram" instead of "Update Diagram", and saving inserts a duplicate diagram at the editor's last cursor position (e.g. pos 0 / above YAML frontmatter in preview mode) instead of replacing the existing diagram in place. | Clicking "Edit diagram" in preview should identify the target diagram block range (e.g. by diagram index or source match) and pass `range: { from, to }` to `openMermaidDialog`, enabling in-place replacement and displaying "Update Diagram". | 1 |
| QA-02 | Low | MINOR | `resources/js/lib/editor/copyButton.ts:39` & `resources/js/components/editor/MarkdownEditor.vue:371` | When clicking "Copy SVG" (`data-mermaid-copy-svg`) on a rendered diagram block in preview, `triggerCopyFeedback` is used with generic defaults. After the 2-second copied state expires, it resets the button's HTML to `COPY_ICON_SVG` and its aria-label/title to "Copy code", overwriting the original `MERMAID_SVG_ICON` and "Copy SVG" tooltip. | Restoring visual feedback for the "Copy SVG" button should preserve `MERMAID_SVG_ICON` and the "Copy SVG" label/title. | 1 |

**Severity Breakdown**:
- Critical: 0
- High: 1 (QA-01)
- Medium: 0
- Low: 1 (QA-02)

---

## 4. Code Review Notes
- **Security & Authorization**:
  - `securityLevel: 'strict'` is verified in `getMermaidConfig()`.
  - Escaping utilities `escapeAttribute` and `escapeHtml` are properly applied to diagram source and syntax error messages; hostile script tags inside node labels or attributes do not execute and are escaped in the host DOM.
- **Validation & Data Integrity**:
  - Syntax errors in preview mode render a clean error card with source toggle rather than throwing unhandled exceptions or blanking the preview container.
  - In `MermaidDialog.vue`, the apply button is disabled while syntax errors exist or source is empty.
  - QA-01 must be fixed to prevent duplicate diagram insertion or frontmatter disruption when editing from the preview pane.
- **Performance & Lazy Loading**:
  - Dynamic `import('mermaid')` ensures the ~1.5 MB library is only loaded when a note containing `.mermaid-block` is active or the Mermaid dialog is opened.
  - In-memory `svgCache` keyed by `${theme}:${source.trim()}` prevents redundant compilations while typing in Split view.
  - Headless Node test environment (`vite-plus/test`) remains 100% compatible.
- **Conventions & Frontend Patterns**:
  - `MermaidDialog.vue` adheres to Vue 3 `<script setup lang="ts">` with single root element and clean shadcn/reka-ui primitives.
  - Dark/light mode theme toggling correctly triggers dynamic re-rendering.

---

## 5. Routing Recommendation
- [x] **FAIL — MINOR/MODERATE** → Senior Developer: `QA-01`, `QA-02`
- [ ] **FAIL — MAJOR** → System Analyst
- [ ] **PASS** → move feature to `.ai/features/completed/`
