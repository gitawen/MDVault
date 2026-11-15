# Implementation: Mermaid Diagram Editor and View

## Metadata
- **Feature Name**: Mermaid Diagram Editor and View
- **Feature ID**: feat-mermaid-editor-view
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Install `mermaid` NPM Dependency | DONE | Installed `mermaid` v12.3.0 (approved by user). |
| T2 — Create Mermaid Templates Library | DONE | Created `mermaidTemplates.ts` with 9 preset diagram templates (Flowchart, Sequence, State, Class, ER, Gantt, Pie, Mindmap, Git Graph) and lookup helpers. |
| T3 — Create Client Mermaid Renderer & SVG Cache Engine | DONE | Created `mermaidRenderer.ts` with lazy dynamic import of `mermaid`, `securityLevel: 'strict'`, in-memory cache `svgCache`, light/dark theme adaptation, and container rendering with error fallback. |
| T4 — Update Marked Preview Pipeline for Mermaid Code Blocks | DONE | Updated `markdownPreview.ts` custom code renderer to recognize `lang === 'mermaid'` and generate `.mermaid-block` with `data-mermaid-source` and action buttons; exported `escapeAttribute` and `escapeHtml`. Zero DOM dependency in Node. |
| T5 — Add CodeMirror Commands & Range Detection for Mermaid Blocks | DONE | Implemented `findMermaidBlockAt()`, `insertMermaidBlock()`, and `isMermaidBlockActive()` in `codemirrorCommands.ts`. |
| T6 — Update Toolbar Commands, Keymap, and CodeMirror Extensions | DONE | Added `mermaid` command with `Ctrl+Alt+D` shortcut in `toolbarCommands.ts`, bound `Mod-Alt-d` in `codemirrorKeymap.ts`, passed `onMermaid` through `codemirror.ts`. |
| T7 — Create the Mermaid Editor Dialog Component (`MermaidDialog.vue`) | DONE | Built `MermaidDialog.vue` with preset dropdown, two-column layout, monospace editor with tab indentation, live debounced preview, syntax error feedback, copy code/SVG, and insert/update actions. |
| T8 — Integrate Mermaid Dialog into `EditorToolbar.vue` | DONE | Added Diagram button (`Workflow` icon) to toolbar block group and mobile overflow menu; bound `openMermaidDialog()` to preload active block or insert new diagram; mounted `MermaidDialog`. |
| T9 — Connect Diagram Rendering, Theme Watching & Preview Actions in `MarkdownEditor.vue` | DONE | Wired `onMermaid` callback into CodeMirror extensions; added watcher on `[renderedHtml, isDark, displayMode]` to render diagrams; wired preview click actions (`data-mermaid-copy`, `data-mermaid-copy-svg`, `data-mermaid-edit`, `data-mermaid-toggle-source`); added CSS styles. |
| T10 — Unit & Integration Test Suite | DONE | Added 4 unit tests in `markdownPreview.test.ts`, 5 unit tests in `codemirrorCommands.test.ts`, and 5 unit tests in `mermaid.test.ts`. All 18 test files (250 tests) pass. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| modified | `package.json` | Added `mermaid` dependency. |
| modified | `package-lock.json` | Lockfile updated for `mermaid` and transitive dependencies. |
| created | `resources/js/lib/editor/mermaidTemplates.ts` | Preset templates for 9 diagram types, metadata, and helper functions. |
| created | `resources/js/lib/editor/mermaidRenderer.ts` | Lazy-loaded client rendering engine, SVG cache, theme mapping, strict security config, and container renderer. |
| modified | `resources/js/lib/editor/markdownPreview.ts` | Added custom fenced block rendering for `lang === 'mermaid'`, exported HTML escape utilities. |
| modified | `resources/js/lib/editor/codemirrorCommands.ts` | Added `findMermaidBlockAt()`, `insertMermaidBlock()`, and `isMermaidBlockActive()`. |
| modified | `resources/js/lib/editor/toolbarCommands.ts` | Registered `mermaid` command with `Ctrl+Alt+D` shortcut and active state. |
| modified | `resources/js/lib/editor/codemirrorKeymap.ts` | Registered `Mod-Alt-d` key binding for Mermaid dialog. |
| modified | `resources/js/lib/editor/codemirror.ts` | Propagated `onMermaid` option to `markdownFormattingKeymap`. |
| created | `resources/js/components/editor/MermaidDialog.vue` | Modal dialog for authoring/editing Mermaid diagrams with live preview, preset picker, syntax error alerts, and copy actions. |
| modified | `resources/js/components/editor/EditorToolbar.vue` | Added diagram toolbar button and overflow menu item; mounted and exposed `openMermaidDialog()`. |
| modified | `resources/js/components/editor/MarkdownEditor.vue` | Integrated `onMermaid` callback, preview diagram rendering watcher, preview button interactions (copy source, copy SVG, edit diagram, toggle source), and styles. |
| modified | `resources/css/app.css` | Added styles for `.mermaid-block`, `.mermaid-action-btn`, and `.mermaid-container svg`. |
| modified | `tests/js/editor/toolbarCommands.test.ts` | Updated command markdown assertion table to include `mermaid`. |
| modified | `tests/js/editor/markdownPreview.test.ts` | Added 4 tests for Mermaid code block wrapper, HTML escaping, action buttons, and empty block handling. |
| modified | `tests/js/editor/codemirrorCommands.test.ts` | Added 5 tests for detecting, inserting, replacing, and checking active state of Mermaid blocks. |
| created | `tests/js/editor/mermaid.test.ts` | Added 5 tests for template presets, theme mapping, strict security configuration, and SVG cache key/hit/miss behavior. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `npm run test:js` (`npm.cmd run test:js`) | 18 test files passed (18), 250 tests passed (250). |
| `npm run types:check` (`npm.cmd run types:check`) | 0 TypeScript errors. |

---

## 4. Deviations from Plan
- None. All tasks T1 through T10 were executed in accordance with Revision 1 of `plan.md`.

---

## 5. Notes for QA
- **Dynamic On-Demand Loading**: `mermaid` (~1.5 MB) is only loaded via dynamic import when a note containing `.mermaid-block` is displayed in Split/Preview modes or when `MermaidDialog` is opened. Notes without diagrams load zero bytes of Mermaid.
- **Theme Switching**: Toggling light/dark mode re-renders diagrams using Mermaid's `dark` or `default` themes respectively, invalidating or re-evaluating the cache.
- **Syntax Error Handling**: Notes with malformed Mermaid diagrams show a destructive alert box with the syntax error message and a "Toggle source" button, without blanking the rest of the note preview.
- **Interactive Buttons in Preview**:
  - "Copy diagram source": Copies raw Mermaid code to clipboard with checkmark feedback.
  - "Copy SVG": Copies the rendered SVG XML markup to clipboard.
  - "Edit diagram": Directly opens `MermaidDialog` with the diagram's code preloaded; saving updates the block in place in the Markdown document.
- **Keybindings**: `Ctrl+Alt+D` (`Cmd+Alt+D` on Mac) opens the Mermaid dialog from the editor. If the caret is inside an existing diagram block, it preloads that block and updates it upon save.

---

## 6. Fix Round 1

### Issues Addressed
- **QA-01 (High)**: Clicking "Edit diagram" (`button[data-mermaid-edit]`) in the preview pane did not pass the document `{ from, to }` range, causing the dialog to treat existing diagrams as new insertions.
  - **Resolution**:
    - Implemented and exported `findMermaidBlocks(state)` and `findMermaidBlockByIndex(state, index)` in `codemirrorCommands.ts`. Refactored `findMermaidBlockAt(state, pos)` to leverage `findMermaidBlocks`.
    - In `MarkdownEditor.vue:handlePreviewClick()`, determined the index of the clicked `.mermaid-block` within `previewContainer.value.querySelectorAll('.mermaid-block')` and resolved the corresponding `{ from, to }` block range via `findMermaidBlockByIndex(view.value.state, index)`.
    - Passed `range` into `toolbarRef.value?.openMermaidDialog(diagramSource, range)`, which activates `isEditingExisting = true` in `MermaidDialog.vue` (displaying "Update Diagram") and replaces the diagram block in-place in the CodeMirror buffer upon save.
    - Added unit tests in `tests/js/editor/codemirrorCommands.test.ts` for multiple diagram resolution, non-mermaid fence exclusion, out-of-bounds indexing, and frontmatter handling.
- **QA-02 (Low)**: "Copy SVG" (`data-mermaid-copy-svg`) and "Copy diagram source" (`data-mermaid-copy`) buttons had their innerHTML and labels overwritten with "Copy code" and `COPY_ICON_SVG` after the 2-second timeout expired.
  - **Resolution**:
    - Updated `triggerCopyFeedback` in `copyButton.ts` to automatically capture the button's initial `innerHTML`, `aria-label`, and `title` before transitioning to the copied checkmark state (stored in `originalButtonStates` WeakMap to protect against re-armed successive clicks).
    - Added optional `CopyFeedbackOptions` parameter (`durationMs`, `restoreHtml`, `restoreAriaLabel`, `restoreTitle`) to `triggerCopyFeedback()`.
    - In `MarkdownEditor.vue`, explicitly supplied restoration options for "Copy SVG" (`restoreHtml: MERMAID_SVG_ICON`, `restoreAriaLabel: 'Copy SVG'`, `restoreTitle: 'Copy SVG'`) and "Copy diagram source" (`restoreAriaLabel: 'Copy diagram source'`, `restoreTitle: 'Copy diagram source'`).
    - Added comprehensive unit tests in `tests/js/editor/clipboard.test.ts` verifying restoration of custom icons, custom aria-labels/tooltips, multiple successive clicks, and explicit restoration options.

### Files Modified in Fix Round 1
| Action | Path | Summary |
|---|---|---|
| modified | `resources/js/lib/editor/codemirrorCommands.ts` | Added `findMermaidBlocks()` and `findMermaidBlockByIndex()`. |
| modified | `resources/js/lib/editor/copyButton.ts` | Added original button state caching, `CopyFeedbackOptions`, and full icon/label restoration. |
| modified | `resources/js/components/editor/MarkdownEditor.vue` | Resolved diagram index and range on edit; provided explicit restoration options on SVG/source copy. |
| modified | `tests/js/editor/codemirrorCommands.test.ts` | Added 3 unit tests for `findMermaidBlocks` and `findMermaidBlockByIndex`. |
| modified | `tests/js/editor/clipboard.test.ts` | Added 4 unit tests for custom icon/label restoration, rapid clicks, and explicit options. |

### Verification Performed in Fix Round 1
| Command | Result |
|---|---|
| `npm.cmd run test:js` | 18 test files passed (18), 257 tests passed (257). |
| `npm.cmd run types:check` | 0 TypeScript errors. |
| `npm.cmd exec vp check resources/js/lib/editor/codemirrorCommands.ts resources/js/lib/editor/copyButton.ts resources/js/components/editor/MarkdownEditor.vue tests/js/editor/codemirrorCommands.test.ts tests/js/editor/clipboard.test.ts` | All 5 files correctly formatted, 0 warnings/lint errors. |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |

