# QA Report: Code Block & Inline Code Copy Buttons

## Metadata
- **Feature Name**: Code Block & Inline Code Copy Buttons
- **Feature ID**: feat-code-block-copy
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-11-07
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage

| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| **FR-01**: Preview Code Block Copy Button | Yes | `tests/js/editor/markdownPreview.test.ts:109` | ✅ |
| **FR-02**: Preview Code Clipboard Copy | Yes | `tests/js/editor/clipboard.test.ts:42,134`, `tests/js/editor/markdownPreview.test.ts:109` | ✅ |
| **FR-03**: CodeMirror Fenced Code Copy Widget | Yes | `tests/js/editor/codemirrorLivePreview.test.ts:227` | ✅ |
| **FR-04**: Inner Code Extraction (CodeMirror) | Yes | `tests/js/editor/codemirrorLivePreview.test.ts:325-371` | ✅ |
| **FR-05**: Caret Isolation & Read-Only Support | Yes | `tests/js/editor/codemirrorLivePreview.test.ts:227`, `tests/js/editor/clipboard.test.ts:134` | ✅ |
| **FR-06**: Copy Feedback & A11y | Yes | `tests/js/editor/clipboard.test.ts:134-216` | ✅ |
| **FR-07**: Preview Inline Code Copy Button | Yes | `tests/js/editor/markdownPreview.test.ts:146-168` | ✅ |
| **FR-08**: CodeMirror Inline Code Copy Widget | Yes | `tests/js/editor/codemirrorLivePreview.test.ts:247-297` | ✅ |
| **FR-09**: Inner Inline Code Extraction & Monotonic Ordering | Yes | `tests/js/editor/codemirrorLivePreview.test.ts:299-322,373-406` | ✅ |
| **FR-10**: Copy Full Note Content Button | Yes | `tests/js/editor/clipboard.test.ts:42`, `resources/js/components/editor/NoteEditor.vue:80-97,524-536` | ✅ |

---

## 2. Test Execution

| Command | Result |
|---|---|
| `npm.cmd run test:js tests/js/editor/` | 12 test files passed, 160 tests passed (0 failed) |
| `npm.cmd run test:js tests/js/` | 17 test files passed, 212 tests passed (0 failed) |
| `npm.cmd run types:check` | Pass (0 errors across `vue-tsc --noEmit`) |
| `vendor/bin/pint --dirty --format agent` | Pass (`{"tool":"pint","result":"passed"}`) |
| `php artisan test --compact tests/Feature/Notes/NoteContentTest.php` | 18 tests passed, 87 assertions |

*All scoped and editor test suites executed cleanly.*

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| — | None | — | — | No defects identified. | — | 0 |

**Severity Counts**:
- Critical: 0
- High: 0
- Medium: 0
- Low: 0

---

## 4. Code Review Notes

- **Security & A11y**:
  - In `markdownPreview.ts`, both code block content and inline codespan text are strictly HTML-escaped (`escapeHtml`), preventing tag injection. All code and inline buttons have explicit `type="button"`, descriptive `aria-label` / `title` attributes, and dynamic updates to `"Copied!"` on interaction.
  - Keyboard accessibility is maintained with `focus-visible:opacity-100` and focus outline rings conforming to theme design tokens.
- **CodeMirror Integration & Caret Isolation**:
  - Both `CodeBlockCopyWidget` and `InlineCodeCopyWidget` implement `ignoreEvent(): boolean { return true; }` and intercept `mousedown` and `click` with `preventDefault()` and `stopPropagation()`. Clicking copy widgets in CodeMirror never displaces the caret, modifies the selection, or dispatches editing transactions, functioning identically in editable and read-only modes.
  - Strict decoration ordering in `decorateLine`: collects line decorations (`Decoration.line`), task checkbox replacements (`Decoration.replace`), strike-through markings (`Decoration.mark`), and inline code buttons (`Decoration.widget`) on each line, sorting them monotonically by `a.from - b.from || a.value.startSide - b.value.startSide || a.to - b.to`. This ensures CodeMirror's `RangeSetBuilder` invariant is preserved across all mixed-syntax lines without throwing.
  - `extractInlineCodeContent` correctly strips outer `CodeMark` backtick delimiters while preserving inner whitespace and handles arbitrary backtick counts (e.g. ```` ``hello ` world`` ````).
- **Preview & CSS Layout**:
  - Preview `<pre>` is wrapped in `.code-block-wrapper relative group`, positioning the copy button outside `<pre>`'s horizontal scroll container.
  - Inline code spans wrap text in `.inline-code-text` inside `.inline-code-wrapper group`, keeping the compact `.inline-code-copy-btn` unobtrusive (`opacity-0` default, revealed on hover/focus). Text extraction via `textContent` in `handlePreviewClick` handles decoded entity strings cleanly.
  - Live Preview styling is fully declared in CodeMirror's theme extension (`livePreview()`) with zero Tailwind utility class leaks, zero vertical margins on `.cm-line`, and `em`-relative dimensions (`1.1em` button, `0.75em` icon).
- **Header Note Copying**:
  - In `NoteEditor.vue`, the Copy button next to Save captures the live in-memory document via `readContent()` (`editorRef.value?.getText()`), falling back to `props.note.content` only if unmounted.
  - Visual feedback transitions icon from `Copy` to `Check`, label from "Copy" to "Copied", and `aria-label` / `title` to "Copied note" for 2000ms, with timeout cleanup on `onUnmounted`.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/`
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer
- [ ] **FAIL — MAJOR** → System Analyst

**Summary**:
- **Verdict**: PASS
- **Severity Counts**: 0 Critical, 0 High, 0 Medium, 0 Low
- **Routing**: Ready to move `.ai/features/active/code-block-copy-button/` to `.ai/features/completed/code-block-copy-button/`.
