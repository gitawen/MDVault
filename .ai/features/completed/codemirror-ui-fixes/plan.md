# Plan: CodeMirror Editor UI Defect Remediation

## Metadata
- **Feature Name**: CodeMirror Editor UI Defect Remediation
- **Feature ID**: feat-codemirror-ui-fixes
- **Author**: System Analyst
- **Created Date**: 2026-10-07
- **Task Complexity**: Level 3 — Complex Development
- **Master Plan Phase**: Phase 4 — Editor (`docs/Masterplan.md` §53)
- **Requirements**: `requirements.md`
- **Status**: APPROVED — Q1 and Q2 both answered **yes** by the user on 2026-10-07. All tasks T1–T12 are unblocked.

---

## 1. Summary

Repair the CodeMirror 6 editor integration left half-wired by `codemirror-unified-editor` and `large-note-performance-codemirror`. Three groups of work: (1) make the shared toolbar command table actually drive a CodeMirror `EditorView` — fix the three no-op/destructive commands, restore live active/enabled state, add the advertised keymap, and finish link editing; (2) rewrite the Live-Preview decoration builder to be syntax-tree-aware, crash-proof, and styled entirely through CodeMirror's own theme instead of Tailwind utilities that the cascade discards; (3) align the editor surface with the app's theme tokens and make the rendered preview correct, safe and debounced. No backend, schema, route or service change. New test coverage is written to run in the existing `node` test environment by extracting logic into pure, state-level functions.

---

## 2. Architecture & Design

- **Approach**:
  - **Keep the dual-editor command table.** `toolbarCommands.ts` stays a `Record<ToolbarCommandId, ToolbarCommand>` with a TipTap branch and a CodeMirror branch. All CodeMirror behaviour moves into `codemirrorCommands.ts` behind the existing `CommandTarget` interface (`{ state, dispatch, focus? }`), which is constructible from a bare `EditorState` and therefore unit-testable under `environment: 'node'`. **No new command logic may be written inline in a `.vue` file.**
  - **Toolbar reactivity via an explicit revision counter.** CodeMirror has no event emitter, so `buildCodeMirrorExtensions` gains an `onUpdate(view)` callback fired from the existing `EditorView.updateListener` (`codemirror.ts:113-117`) whenever `docChanged || selectionSet || focusChanged`. `SourceEditor.vue` increments a `revision` ref from it and passes it to `EditorToolbar` as a prop; `EditorToolbar.isActive()/canRun()` read `props.revision` instead of the dead TipTap tick. `useEditorTick` keeps its `.on('transaction')` path for TipTap and gains an explicit guard so it never silently no-ops again.
  - **Live preview driven by the syntax tree.** `buildLiveDecorations` is rewritten to walk `syntaxTree(state)` over `view.visibleRanges` to classify each visible line's block context (`FencedCode`/`CodeBlock`/frontmatter/`Blockquote`/`ATXHeading*`/`HorizontalRule`/`TaskMarker`) before deciding what to decorate, and to iterate **unique line numbers** so `RangeSetBuilder` ordering can never be violated. All visual properties move into the extension's `EditorView.theme` using `em` units and app CSS variables; decoration `class` values become stable `cm-*` names with **no Tailwind utilities**.
  - **One editor theme, two palettes.** A new `codemirrorTheme.ts` exports `appEditorTheme(isDark)` (an `EditorView.theme(..., { dark: isDark })` built on `var(--card)`, `var(--foreground)`, `var(--muted)`, `var(--muted-foreground)`, `var(--border)`, `var(--primary)`, with a **transparent** editor background so the card shows through) and `appHighlightStyle(isDark)` (a `HighlightStyle` for Markdown tags). `oneDark` is no longer imported. `@codemirror/theme-one-dark` stays in `package.json`.
  - **Preview pipeline extracted.** A new `markdownPreview.ts` owns `stripFrontmatter()`, `renderMarkdownPreview()` (configured `Marked` instance with an escaped, protocol-allowlisted link/image renderer) and the exported `ALLOWED_LINK_PROTOCOLS`. `SourceEditor.vue` consumes it and debounces it. This makes FR-19/FR-20 testable in `node` — today the logic is trapped inside the SFC.
- **Alternatives Considered**:
  - *Add `jsdom` + `@vue/test-utils` and test the toolbar/decorations against a real `EditorView`* — rejected for this pass: two new dev dependencies and a `vite.config.ts` test-environment change (needs approval, see Q1), and it would not have been necessary if the logic had been extracted. Extracting pure functions buys most of the coverage with zero new dependencies.
  - *Keep Tailwind utilities in decorations and raise their specificity with `!important`* — rejected: fights the cascade forever, defeats `sortTailwindcss`, and still loses to `.cm-activeLine` for the blockquote background. CodeMirror themes are the supported mechanism.
  - *Keep `oneDark` and restyle the surrounding card to match `#282c34`* — rejected: inverts the dependency (app design follows a third-party editor theme) and still mismatches the Split preview pane and all live-preview token colours.
  - *Regex-based fence tracking instead of the syntax tree* — rejected as the primary mechanism: `@codemirror/lang-markdown` is already installed and already parses the document incrementally; a second, divergent Markdown parser inside the decoration builder is exactly the kind of drift that produced FR-11.
- **Decision Records**: `.ai/decisions/codemirror-unified-editor.md` and `.ai/decisions/codemirror-markdown-editor.md` remain in force. **No new ADR**: this pass changes no architectural decision; it implements the accepted ones correctly. If Q1 is answered "yes, add a DOM test environment", that *does* need an ADR and must come back to the analyst first.

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| — | none | No migration. No SQLite, service, controller or route change in this feature. |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| — | — | None. Markdown files remain the source of truth; `NoteService`, `MarkdownService` and `createNoteSaver` are untouched. |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Library (modify) | `resources/js/lib/editor/codemirrorCommands.ts` | All CodeMirror formatting commands + the active-state predicates, against `CommandTarget` |
| Library (new) | `resources/js/lib/editor/codemirrorKeymap.ts` | `markdownFormattingKeymap({ onLink })` → `Prec.high(keymap.of([...]))` for the shortcuts `toolbarCommands` advertises |
| Library (new) | `resources/js/lib/editor/codemirrorTheme.ts` | `appEditorTheme(isDark)`, `appHighlightStyle(isDark)` built on app CSS variables |
| Library (new) | `resources/js/lib/editor/markdownPreview.ts` | `stripFrontmatter`, `renderMarkdownPreview`, `ALLOWED_LINK_PROTOCOLS`, `isAllowedHref` |
| Library (modify) | `resources/js/lib/editor/codemirrorLivePreview.ts` | Syntax-tree-aware, dedupe-safe decoration builder; theme-only styling; safe checkbox widget |
| Library (modify) | `resources/js/lib/editor/codemirror.ts` | `onUpdate` hook, formatting keymap, app theme, `EditorState.readOnly`, non-shorthand `.cm-line` padding |
| Library (modify) | `resources/js/lib/editor/toolbarCommands.ts` | Rewire the CodeMirror branch to the fixed commands and real `canRun` predicates |
| Composable (modify) | `resources/js/lib/editor/useEditorTick.ts` | TipTap `transaction` subscription + external revision source; fail loudly on an unsupported editor shape |
| Component (modify) | `resources/js/components/editor/SourceEditor.vue` | Revision plumbing, app theme, debounced + sanitised preview, link-click guard, Ctrl+K relay |
| Component (modify) | `resources/js/components/editor/EditorToolbar.vue` | `revision` prop, CodeMirror-aware `currentHref()`, typed `LinkDialog` binding |
| Component (modify) | `resources/js/components/editor/LinkDialog.vue` | CodeMirror edit/replace/remove + protocol validation with user-visible errors |
| Component (modify) | `resources/js/components/editor/NoteEditor.vue` | Mode-toggle copy only (T9, gated on Q2) |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| — | — | — | — | No route change. |

---

## 3. Implementation Tasks

*Ordered. Pure logic first, then plumbing, then presentation — each task leaves the app working and independently shippable. Run `npm run check:fix` and `npm run types:check` after each task; run `vendor/bin/pint --dirty --format agent` only if a PHP file is touched (none is expected).*

- [ ] **T1 — Fix and complete the CodeMirror command set**
  - Files: `resources/js/lib/editor/codemirrorCommands.ts`
  - Details:
    - `toggleInlineMark`: before taking the unwrap path at `:27-39`, reject a boundary that is part of a longer run of the same character — for `marker === '*'`, require `state.sliceDoc(beforeFrom - 1, beforeFrom) !== '*'` and `state.sliceDoc(afterTo, afterTo + 1) !== '*'`; apply the same rule for `` ` `` (guard against ``` ``` ```). Wrapping an already-bold selection with `*` must yield `***text***`. **(FR-03)**
    - Add `export function clearLinePrefix(view: CommandTarget): boolean` — strips the existing `/^(#{1,6}\s+|[-*+]\s+(\[[ xX]\]\s+)?|\d+\.\s+|>+\s*)/` prefix from every line in the selection (reuse the regex already at `:104`), returning `false` when no line has a prefix. **(FR-04)**
    - Add `export function insertHorizontalRule(view: CommandTarget): boolean` — inserts `\n---\n` after `state.doc.lineAt(selection.main.to).to`, normalising surrounding blank lines, exactly once regardless of selection size, leaving the caret on the line after the rule. Must not touch the current line's text. **(FR-05)**
    - Add `export function clearFormatting(view: CommandTarget): boolean` — removes `**`, `__`, `~~`, `*`, `_`, `` ` `` pairs from the selected text and calls the `clearLinePrefix` logic for the touched lines, in one transaction. **(FR-06)**
    - `insertLink` (`:198-213`): replace `??` with `||` so an empty `sliceDoc` falls back to `'link'`, and set the selection over the inserted label rather than after the whole insertion. Accept an optional `range` to replace instead of the current selection. **(FR-07)**
    - Add `export function linkAt(state: EditorState, pos: number): { from: number; to: number; text: string; href: string } | null` — matches a surrounding `[text](href)` on the caret's line. **(FR-08)**
    - Add `export function removeLink(view: CommandTarget): boolean` — replaces the `linkAt` range with its label text. **(FR-08)**
    - Move `isInlineMarkActive` and `isLinePrefixActive` here from `toolbarCommands.ts:52-72`, export them, and widen them to take `EditorState` (not `EditorView`); make `isLinePrefixActive` for ordered lists match `/^\d+\.\s/` rather than the literal `'1. '`. **(FR-01)**
  - Covers: FR-03, FR-04, FR-05, FR-06, FR-07, FR-08, FR-01

- [ ] **T2 — Rewire the CodeMirror branch of the toolbar command table**
  - Files: `resources/js/lib/editor/toolbarCommands.ts`
  - Details: Point `paragraph` → `clearLinePrefix`, `horizontalRule` → `insertHorizontalRule`, `clearFormatting` → `clearFormatting` (replacing the `: true` at `:250`). Import `undoDepth`/`redoDepth` from `@codemirror/commands` and use them for `undo.canRun`/`redo.canRun` in place of `Boolean(editor)` (`:83,90`). Use the relocated predicates from T1 for every `isActive`. Delete the now-unused local helpers. **The TipTap branch and `tests/js/editor/toolbarCommands.test.ts:11-29`'s expectations must not change.**
  - Covers: FR-02, FR-04, FR-05, FR-06, FR-01

- [ ] **T3 — Test the CodeMirror branch of the command table and the new commands**
  - Files: `tests/js/editor/codemirrorCommands.test.ts` (extend), `tests/js/editor/toolbarCommands.test.ts` (extend)
  - Command: `npx vitest run tests/js/editor/codemirrorCommands.test.ts tests/js/editor/toolbarCommands.test.ts` (or `npm run test:js`)
  - Details: In `toolbarCommands.test.ts`, add a second `describe` that mirrors the existing TipTap loop (`:45-60`) over **every** `ToolbarCommandId` using a `CommandTarget` built from `EditorState` with `para text` selected, asserting the resulting document text — this is the test that would have caught FR-04/FR-05/FR-06. In `codemirrorCommands.test.ts` add: italic on `**bold**` → `***bold***` and bold preserved (FR-03); inline code adjacent to a fence (FR-03); `clearLinePrefix` for heading/bullet/ordered/task/quote and the no-prefix no-op (FR-04); `insertHorizontalRule` on a bullet line, on a heading line and with a multi-line selection (FR-05); `clearFormatting` over mixed inline marks plus a prefix (FR-06); `insertLink` with an empty selection → `[link](url)` with `link` selected (FR-07); `linkAt` hit/miss and `removeLink` (FR-08); `isLinePrefixActive` for `2. ` (FR-01); `undoDepth`-based `canRun` (FR-02).
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-06, FR-07, FR-08

- [ ] **T4 — Live toolbar state: `onUpdate` → `revision` → `EditorToolbar`**
  - Files: `resources/js/lib/editor/codemirror.ts`, `resources/js/lib/editor/useEditorTick.ts`, `resources/js/components/editor/SourceEditor.vue`, `resources/js/components/editor/EditorToolbar.vue`
  - Details:
    - `codemirror.ts`: add `onUpdate?: (view: EditorView) => void` to the `buildCodeMirrorExtensions` options and call it from the existing `updateListener` (`:113-117`) when `update.docChanged || update.selectionSet || update.focusChanged`. Keep `onChange` firing only on `docChanged`.
    - `SourceEditor.vue`: add `const revision = ref(0)`, increment it from `onUpdate`, and bind `:revision="revision"` on `<EditorToolbar>` (`:252-255`).
    - `EditorToolbar.vue`: add an optional `revision?: number` prop; `isActive()`/`canRun()` (`:59-69`) read `props.revision` for the CodeMirror path and `tick.value` for the TipTap path.
    - `useEditorTick.ts`: keep the TipTap subscription but make the unsupported-shape case explicit — when the target has no `.on`, return a ref that the caller is responsible for (document it, or accept an `external?: Ref<number>` and return it). The silent `if (typeof editor.on === 'function')` fallthrough at `:23-29` is the root cause of FR-01 and must not survive as a silent branch.
  - Covers: FR-01, FR-02

- [ ] **T5 — Formatting keymap for CodeMirror**
  - Files: `resources/js/lib/editor/codemirrorKeymap.ts` (new), `resources/js/lib/editor/codemirror.ts`, `resources/js/components/editor/SourceEditor.vue`, `resources/js/components/editor/EditorToolbar.vue`
  - Details: Export `markdownFormattingKeymap(options: { onLink: () => void }): Extension` returning `Prec.high(keymap.of([...]))` with one binding per shortcut string declared in `toolbarCommands.ts` (`Mod-b`, `Mod-i`, `Mod-e`, `Mod-Shift-x`, `Mod-Alt-1/2/3`, `Mod-Shift-8/7/9`, `Mod-Shift-b`, `Mod-Alt-c`, `Mod-\`, `Mod-k` → `onLink`). Each handler calls the T1 command and **returns `true`** so the browser's native `contenteditable` bold/italic handling is suppressed. Register it in `buildCodeMirrorExtensions` **before** the existing `keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab])` (`codemirror.ts:97`); `markdown()`'s own `Prec.high` Enter/Backspace bindings must keep working. `SourceEditor.vue` passes `onLink` → an emitted `open-link-dialog`, which `EditorToolbar.vue` handles by setting `linkDialogOpen` (`:120-124`). Keep the printed shortcut strings and the bindings in one place so they cannot drift again.
  - Covers: FR-10

- [ ] **T6 — Finish link editing in CodeMirror mode**
  - Files: `resources/js/components/editor/EditorToolbar.vue`, `resources/js/components/editor/LinkDialog.vue`
  - Details:
    - `EditorToolbar.vue:126-134`: `currentHref()` returns `linkAt(view.state, view.state.selection.main.head)?.href ?? ''` for a CodeMirror view, keeping the `getAttributes` path for TipTap. Replace `:view="activeEditor as any"` (`:447`) with correctly typed `:editor`/`:view` bindings — the `as any` is what let FR-08 through `vue-tsc`. Remove the unused `import type { Editor } from '@tiptap/core'` (`:3`).
    - `LinkDialog.vue`: in the CodeMirror branch (`:60-65`), validate the href against `isAllowedHref` (from T8's `markdownPreview.ts`, or a shared `linkProtocols.ts` if T8 has not landed) and set `error` + keep the dialog open on rejection; when `linkAt` finds a link under the caret, replace that range; when the trimmed href is empty, call `removeLink`. Make `remove()` (`:71-80`) call `removeLink` for a CodeMirror view.
  - Covers: FR-08, FR-09

- [ ] **T7 — Rewrite the Live-Preview decoration plugin** *(gated on **Q1** only for how it is tested; the implementation is not gated)*
  - Files: `resources/js/lib/editor/codemirrorLivePreview.ts`, `resources/js/lib/editor/codemirror.ts`
  - Details:
    - Extract the builder to `export function markdownLineDecorations(state: EditorState, ranges: readonly { from: number; to: number }[]): DecorationSet` so it is testable from a bare `EditorState` (same pattern as `CommandTarget`). The `ViewPlugin` becomes a thin wrapper passing `view.state` and `view.visibleRanges`.
    - Iterate **unique line numbers** (track `lastLine` and skip a line already emitted) so `RangeSetBuilder.add` can never receive a decreasing `from` when line gaps split one line across two visible ranges. **(FR-12)**
    - Classify each line with `syntaxTree(state)` before decorating: skip heading/rule/quote/task decoration for lines inside `FencedCode`/`CodeBlock`/`CodeText` and inside a leading YAML frontmatter block; decorate headings from `ATXHeading1-6`, rules from `HorizontalRule`, quotes from `Blockquote`, tasks from `TaskMarker`. Rebuild on `update.docChanged || update.viewportChanged || syntaxTree(update.startState) !== syntaxTree(update.state)` so a late incremental parse is picked up. **(FR-11)**
    - Decoration classes become plain `cm-md-h1` … `cm-md-h6`, `cm-md-quote`, `cm-md-rule`, `cm-md-fence-open`, `cm-md-fence-body`, `cm-md-fence-close`, `cm-md-task-done` — **no Tailwind utilities in any decoration class**. All visual properties move into the extension's `EditorView.theme`. **(FR-14)**
    - Heading sizes in the theme become `fontSize: '2em' | '1.5em' | '1.25em' | '1.1em' | '1em' | '0.9em'` per ADR §Decision-2, so they scale with the px base size set from `preferences.font_size`. **(FR-15)**
    - **No `margin-top`/`margin-bottom`/`margin` on `.cm-line` or any decoration class** — express heading and rule spacing as `paddingTop`/`paddingBottom`, which CodeMirror's `getBoundingClientRect` measurement includes. Remove the margins at `:183-194`. **(FR-13)**
    - Decorate the fenced block body, not only the delimiters: a continuous monospace surface with a top border on the opening line and a bottom border on the closing line. **(FR-16)**
    - `TaskCheckboxWidget`: delete the `ignoreEvent()` override at `:50-52` (the default `true` is correct), move the toggle to an explicit `mousedown`/`click` handler that calls `event.preventDefault()` and `event.stopPropagation()` itself, dispatch the change, then `view.focus()`. Guard on read-only: set `input.disabled = true` and dispatch nothing when `view.state.readOnly`. Drop `line-through` from the whole checked line so it no longer strikes through the checkbox and bullet — apply it to the text after the marker. **(FR-17)**
    - `codemirror.ts`: add `EditorState.readOnly.of(!isEditable)` to the `editable` compartment (`:102-109`) and to the reconfigure in `SourceEditor.vue:128-143`, so read-only is enforced against programmatic dispatch too. Change `buildTypographyTheme`'s `.cm-line` rule (`:69-71`) from the `padding` **shorthand** to `paddingLeft`/`paddingRight` only, so decoration padding is no longer zeroed. **(FR-14, FR-17)**
  - Covers: FR-11, FR-12, FR-13, FR-14, FR-15, FR-16, FR-17

- [ ] **T8 — App-token editor theme replacing `oneDark`** *(gated on **Q1**)*
  - Files: `resources/js/lib/editor/codemirrorTheme.ts` (new), `resources/js/lib/editor/codemirror.ts`, `resources/js/components/editor/SourceEditor.vue`
  - Details: Export `appEditorTheme(isDark: boolean): Extension` — `EditorView.theme({ '&': { backgroundColor: 'transparent', color: 'var(--card-foreground)' }, '.cm-gutters': { backgroundColor: 'transparent', color: 'var(--muted-foreground)', borderRight: '1px solid var(--border)' }, '.cm-activeLine': { backgroundColor: 'var(--muted)' }, '.cm-activeLineGutter': { backgroundColor: 'var(--muted)' }, '.cm-content': { caretColor: 'var(--primary)' }, '.cm-cursor, .cm-dropCursor': { borderLeftColor: 'var(--primary)' }, '.cm-selectionBackground, ::selection': { … } }, { dark: isDark })` and `appHighlightStyle(isDark)` (`HighlightStyle.define([...], { themeType: isDark ? 'dark' : 'light' })`) for Markdown tags. In `codemirror.ts`, replace `compartments.theme.of(isDark ? oneDark : [])` (`:110`) with `compartments.theme.of(appEditorTheme(isDark))` and move the theme compartment **after** `compartments.typography` so typography keeps winning the precedence fight it currently loses. Keep `syntaxHighlighting(defaultHighlightStyle, { fallback: true })` (`:94`). In `SourceEditor.vue` remove the `oneDark` import (`:4`) and reconfigure with `appEditorTheme(dark)` + `appHighlightStyle(dark)` (`:146-153`). Leave `@codemirror/theme-one-dark` in `package.json` (removing a dependency needs approval).
  - Covers: FR-18

- [ ] **T9 — Safe, correct, debounced Markdown preview**
  - Files: `resources/js/lib/editor/markdownPreview.ts` (new), `resources/js/components/editor/SourceEditor.vue`, `tests/js/editor/markdownPreview.test.ts` (new)
  - Command: `npx vitest run tests/js/editor/markdownPreview.test.ts`
  - Details: New module exporting `ALLOWED_LINK_PROTOCOLS`, `isAllowedHref(href): boolean`, `stripFrontmatter(markdown): string` (removes a leading `---`…`---` block only, leaving a body-initial `---` alone) and `renderMarkdownPreview(markdown): string` — a module-level `Marked` instance (moved from `SourceEditor.vue:42-54`) whose link renderer **HTML-escapes `href`, `title` and emits nothing for a disallowed protocol**. `SourceEditor.vue`: `renderedHtml` consumes `renderMarkdownPreview(stripFrontmatter(debouncedText))`, where `debouncedText` is a 200 ms debounce of `text` (use `@vueuse/core`'s `refDebounced` — already a dependency) so the preview no longer re-parses per keystroke; keep the existing `try/catch` fallback (`:218-222`). In `handlePreviewClick` (`:227-242`), `event.preventDefault()` **unconditionally** for any anchor and only then `window.open` when `isAllowedHref` passes. Tests: frontmatter stripped / body-initial `---` preserved / CRLF frontmatter; `javascript:`, `data:` and `vbscript:` hrefs produce no anchor; a `"`-bearing title emits no extra attribute; `mailto:`/`http:`/`https:` survive; GFM table and task-list output unchanged.
  - Covers: FR-19, FR-20, FR-21

- [ ] **T10 — Live-preview decoration tests**
  - Files: `tests/js/editor/codemirrorLivePreview.test.ts` (replace the 16-line smoke test)
  - Command: `npx vitest run tests/js/editor/codemirrorLivePreview.test.ts`
  - Details: Build an `EditorState` with `markdown()` + the live-preview extension and assert over `markdownLineDecorations(state, ranges)` by iterating the returned `DecorationSet` (`set.between(...)` collecting `{ from, to, spec }`): a `# Heading` outside a fence is decorated and the same text **inside a fence is not** (FR-11); YAML frontmatter delimiters produce no rule decoration while a body `---` does (FR-11); a `- [x]` line yields exactly one replace range of length 3 at the bracket offset and a checked-line decoration (FR-17); passing two ranges that both fall inside one long line produces a valid set and does not throw (FR-12); no emitted decoration `spec.class` contains a Tailwind utility — assert `/\b(text-|bg-|border-|p[btlrxy]?-|m[btlrxy]?-|font-|leading-)/` does **not** match any class (FR-13, FR-14, FR-15); fence body lines carry a body class (FR-16).
  - Covers: FR-11, FR-12, FR-13, FR-14, FR-15, FR-16, FR-17

- [ ] **T11 — Mode-toggle copy** *(gated on **Q2**)*
  - Files: `resources/js/components/editor/NoteEditor.vue`
  - Details: Relabel the segmented control (`:644-667`) and the large-note banner (`:768`) in user language, removing the strings "CodeMirror", "CodeMirror 6" and "TipTap" from the UI. Copy-only change; no logic, no test.
  - Covers: FR-22

- [ ] **T12 — Verification and manual UI walkthrough**
  - Commands: `npm run test:js`, `npm run types:check`, `npm run check`, `npm run build`
  - Details: Run the full JS suite and type check. Then write the manual UI test steps into `implementation.md` for the user to run against a real browser (`composer run dev`), covering at minimum: every toolbar button in CodeMirror mode on a non-trivial note; every advertised keyboard shortcut; the Link dialog's prefill / edit / remove / `javascript:` rejection; a note with YAML frontmatter plus a fenced block containing `# comment`; task checkbox clicking from an unfocused editor; Code / Split / Preview switching at desktop and mobile widths; light↔dark toggling in each view mode; and a 300-line note with line numbers on, scrolled to the bottom, checking gutter alignment. **This is the step both previous passes skipped.**
  - Covers: All

---

## 4. Test Plan

| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/editor/toolbarCommands.test.ts` (extend) | New `describe` looping every `ToolbarCommandId` through a CodeMirror `CommandTarget` and asserting the resulting document text — the direct regression test for the three broken commands. Existing TipTap loop must still pass unchanged. | FR-02, FR-04, FR-05, FR-06 |
| `tests/js/editor/codemirrorCommands.test.ts` (extend) | Italic over bold; inline code beside a fence; `clearLinePrefix` across prefix types; `insertHorizontalRule` on bullet/heading/multi-line; `clearFormatting`; `insertLink` with an empty selection; `linkAt` / `removeLink`; `isLinePrefixActive` for `2. `; `undoDepth`/`redoDepth` gating | FR-01, FR-02, FR-03, FR-04, FR-05, FR-06, FR-07, FR-08 |
| `tests/js/editor/codemirrorLivePreview.test.ts` (replace) | Decoration set assertions: fence/frontmatter exclusion; task replace range and offsets; split ranges inside one line do not throw; no Tailwind utility appears in any decoration class; fence body decorated | FR-11, FR-12, FR-13, FR-14, FR-15, FR-16, FR-17 |
| `tests/js/editor/markdownPreview.test.ts` (new) | `stripFrontmatter` (leading block, body-initial `---`, CRLF); `renderMarkdownPreview` rejects `javascript:`/`data:`/`vbscript:`; escapes `"` in `title` and `href`; preserves `http`/`https`/`mailto`; GFM tables and task lists unchanged | FR-19, FR-20 |
| `tests/js/editor/codemirror.test.ts` (extend) | `buildCodeMirrorExtensions` accepts `onUpdate` and the built extension list contains the formatting keymap; `EditorState.readOnly` is true when `editable: false` / `readonly: true`; `buildTypographyTheme` no longer emits a `padding` shorthand for `.cm-line` | FR-01, FR-10, FR-17 |
| `tests/js/editor/noteSaver.test.ts`, `saveTransport.test.ts`, `copyTransport.test.ts`, `visitSafety.test.ts`, `tabIndent.test.ts`, `largeNoteThreshold.test.ts` | Regression only — autosave, conflict handling, copy, navigation guard, Tab indent and the 150 KB threshold must be unaffected | — |
| `tests/js/markdown/roundTrip.test.ts`, `assess.test.ts`, `richContent.test.ts` | Regression only — the commands write raw Markdown; round-trip fixtures must still pass | FR-03, FR-05, FR-06 |

**Why the existing suite passed despite every defect above** — state this in `qa-report.md`:
1. `vite.config.ts:45-48` pins `environment: 'node'`, so no `EditorView`, no DOM, no CSS cascade and no Vue component is ever exercised. FR-01, FR-13, FR-14, FR-15, FR-18 and FR-21 are structurally invisible to the suite.
2. `tests/js/editor/toolbarCommands.test.ts` drives the shared command table **only** through TipTap, so the CodeMirror branch — including the `: true` no-op at `toolbarCommands.ts:250` — had zero coverage.
3. `tests/js/editor/codemirrorLivePreview.test.ts` asserted only that `EditorState.create()` did not throw; not one decoration was ever built.
4. `tests/js/editor/codemirrorCommands.test.ts` tested only the happy path with a **non-empty selection on a clean line**, which is exactly the input for which the broken commands behave acceptably.
5. `EditorToolbar.vue:447`'s `as any` suppressed the type error that would have exposed FR-08.
6. The planned `tests/js/editor/sourceEditor.test.ts` (large-note plan T5) was never created.

**Test scope for QA**:
- `npm run test:js` (the whole JS suite is small; the editor files above are the required subset)
- `npm run types:check`
- `npm run check`
- `npm run build` (must succeed; the theme and keymap changes touch imports)
- `php artisan test --compact tests/Feature/Notes/` — regression only; no backend change is expected, so any failure here means the developer went outside scope
- **Do not** report PASS on static checks alone. QA must state explicitly in `qa-report.md` that this feature cannot be fully verified by the automated suite and must list the T12 manual walkthrough steps as the outstanding user-verification items.

---

## 5. Risks & Mitigations

- **Risk**: `syntaxTree(state)` returns an incomplete tree on the first render of a large note, so FR-11's fence detection flickers. — **Mitigation**: query the tree only over `visibleRanges`, and rebuild when `syntaxTree(update.startState) !== syntaxTree(update.state)` so late parse results land.
- **Risk**: Replacing `oneDark` visibly changes dark-mode syntax colours; the user may prefer the old look. — **Mitigation**: Q1 gets explicit confirmation first; `@codemirror/theme-one-dark` stays installed so a revert is a one-line change.
- **Risk**: The new `Prec.high` formatting keymap shadows a `defaultKeymap` or `markdownKeymap` binding (e.g. `Mod-k` is `deleteToLineEnd` on some platforms). — **Mitigation**: T5 must list the bindings it overrides in `implementation.md`; `markdown()`'s Enter/Backspace markup continuation is also `Prec.high` and must be verified still working in the T12 walkthrough.
- **Risk**: Moving the theme compartment after typography (T8) changes which rules win for properties both set. — **Mitigation**: the two must not overlap — typography owns font/size/padding, the theme owns colour. T8 reviews both objects for overlapping properties.
- **Risk**: Debouncing the preview (FR-21) makes Split view feel stale, or a pending debounce survives unmount. — **Mitigation**: 200 ms with `refDebounced`; verify the final text renders after typing stops; the editor pane remains the immediate feedback surface.
- **Risk**: Scope creep into removing TipTap. — **Mitigation**: Q2 is explicitly copy-only in T11; removing TipTap is a separate feature folder.
- **Risk**: 10 changed files in one pass. — **Mitigation**: T1→T12 ordering is pure logic → plumbing → presentation, each task shippable alone; if QA raises a MAJOR, the failing task can be reverted without unwinding the rest.

---

## 6. Open Questions

- [x] **Q1 — Dark-mode palette.** **ANSWERED: yes** (user, 2026-10-07). Replace `oneDark` with a theme built from the app's own tokens (`--card`, `--foreground`, `--muted`, `--border`, `--primary`). T8 proceeds. (The same question's alternative — adding `jsdom`/`happy-dom` + `@vue/test-utils` — remains not recommended/out of scope.)
- [x] **Q2 — Mode-toggle copy.** **ANSWERED: yes** (user, 2026-10-07). Relabel the "CodeMirror" / "TipTap" segmented control and the "CodeMirror 6 Source mode" banner in user language. T11 proceeds, copy-only. (Full removal of the rich/source bifurcation per ADR §Decision-1 remains a separate feature.)
- [ ] **Q3 — `insertTable`.** `codemirrorCommands.ts:182-196` is implemented and tested but reachable from no UI (there is no table button in `EditorToolbar.vue`), while Master Plan §53 does not list tables in the toolbar. Add a button, or leave it as a keyboard-only/future command? **Recommended: leave as-is**; out of scope for this pass. *Gates nothing.*

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-10-07 | Initial plan after user-reported UI breakage and a code-level audit of the CodeMirror integration | 22 FRs across toolbar commands, live-preview decorations, theming and the rendered preview; 12 ordered tasks; test plan plus a documented explanation of why the existing suite passed |
