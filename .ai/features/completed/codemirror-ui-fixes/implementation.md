<!-- path: .ai/features/active/codemirror-ui-fixes/implementation.md -->
# Implementation: CodeMirror Editor UI Defect Remediation

## Metadata
- **Feature Name**: CodeMirror Editor UI Defect Remediation
- **Feature ID**: feat-codemirror-ui-fixes
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1 (both open questions Q1 and Q2 answered "yes")
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Fix and complete the CodeMirror command set | DONE | `codemirrorCommands.ts` rewritten: `toggleInlineMark` longer-run guard, new `clearLinePrefix`, `insertHorizontalRule`, `clearFormatting`, `insertLink` `\|\|` fix + `range` param, new `linkAt`/`removeLink`, `isInlineMarkActive`/`isLinePrefixActive` moved here and widened to `EditorState`. |
| T2 — Rewire the CodeMirror branch of the toolbar command table | DONE | `toolbarCommands.ts`: `paragraph`→`clearLinePrefix`, `horizontalRule`→`insertHorizontalRule`, `clearFormatting`→`clearFormatting`; `undo`/`redo` `canRun` now use `undoDepth`/`redoDepth`; `isTipTap` exported; local `isInlineMarkActive`/`isLinePrefixActive` removed in favour of the T1 imports. |
| T3 — Test the CodeMirror branch of the command table and the new commands | DONE | Extended `codemirrorCommands.test.ts` (new commands + guards) and `toolbarCommands.test.ts` (new CodeMirror-branch `describe` mirroring the TipTap loop, plus an `undoDepth`/`redoDepth` test). |
| T4 — Live toolbar state: `onUpdate` → `revision` → `EditorToolbar` | DONE | `codemirror.ts` gained `onUpdate`; `SourceEditor.vue` bumps a `revision` ref and passes it as a prop; `EditorToolbar.vue` reads `props.revision` and `tick.value`; `useEditorTick.ts` now throws if given an editor with no `.on()` and no caller-side filter (see Deviations). |
| T5 — Formatting keymap for CodeMirror | DONE | New `codemirrorKeymap.ts` exporting `markdownFormattingKeymap({ onLink })`, registered in `buildCodeMirrorExtensions` before the default keymap. `Mod-k` is wired through `SourceEditor.vue` → `EditorToolbar`'s exposed `openLinkDialog()` (see Deviations for the exact wiring mechanism). |
| T6 — Finish link editing in CodeMirror mode | DONE | `EditorToolbar.vue`'s `currentHref()` uses `linkAt` for CodeMirror; `:editor`/`:view` bindings on `LinkDialog` are correctly typed (no `as any`); `LinkDialog.vue`'s CodeMirror branch now validates the href, replaces an existing link in place, and its `remove()` calls `removeLink`. |
| T7 — Rewrite the Live-Preview decoration plugin | DONE | `codemirrorLivePreview.ts` fully rewritten: `markdownLineDecorations(state, ranges)` pure function, syntax-tree-based classification (fence/frontmatter exclusion), unique-line dedupe, theme-only `cm-md-*` classes, no margins, fence body styling, safe checkbox widget, read-only guard. `codemirror.ts` adds `EditorState.readOnly`, fixes the `.cm-line` padding shorthand, and switches `markdown()` to the GFM-enabled `markdownLanguage` base (required for `Task`/`TaskMarker` nodes — see Deviations). |
| T8 — App-token editor theme replacing `oneDark` | DONE | New `codemirrorTheme.ts` (`appEditorTheme`, `appHighlightStyle`); `oneDark` import removed from `codemirror.ts` and `SourceEditor.vue`; theme compartment moved after the typography compartment; `@codemirror/theme-one-dark` left in `package.json`. |
| T9 — Safe, correct, debounced Markdown preview | DONE | New `markdownPreview.ts` (`stripFrontmatter`, `renderMarkdownPreview`, re-exports `ALLOWED_LINK_PROTOCOLS`/`isAllowedHref` from the new `linkProtocols.ts`). `SourceEditor.vue` debounces with `refDebounced` (200ms) and `handlePreviewClick` now calls `preventDefault()` unconditionally. |
| T10 — Live-preview decoration tests | DONE | `codemirrorLivePreview.test.ts` replaced with decoration-set assertions covering FR-11/12/13/14/15/16/17. |
| T11 — Mode-toggle copy | DONE | `NoteEditor.vue`: "CodeMirror"/"TipTap" labels and tooltips → "Markdown"/"Rich text"; large-note banner no longer says "CodeMirror 6 Source mode". |
| T12 — Verification and manual UI walkthrough | DONE | See §3 and §6 below. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| modified | `resources/js/lib/editor/codemirrorCommands.ts` | Fixed `toggleInlineMark`; added `clearLinePrefix`, `insertHorizontalRule`, `clearFormatting`, `linkAt`, `removeLink`; fixed `insertLink`; moved/widened `isInlineMarkActive`/`isLinePrefixActive`. |
| modified | `resources/js/lib/editor/toolbarCommands.ts` | Rewired `paragraph`/`horizontalRule`/`clearFormatting`; `undoDepth`/`redoDepth`-based `canRun`; exported `isTipTap`. |
| created | `resources/js/lib/editor/codemirrorKeymap.ts` | `markdownFormattingKeymap({ onLink })`. |
| created | `resources/js/lib/editor/codemirrorTheme.ts` | `appEditorTheme`, `appHighlightStyle`. |
| created | `resources/js/lib/editor/linkProtocols.ts` | `ALLOWED_LINK_PROTOCOLS`, `isAllowedHref` (shared by `LinkDialog.vue` and `markdownPreview.ts`). |
| created | `resources/js/lib/editor/markdownPreview.ts` | `stripFrontmatter`, `renderMarkdownPreview`, re-exports protocol helpers. |
| modified | `resources/js/lib/editor/codemirrorLivePreview.ts` | Full rewrite: `markdownLineDecorations` pure function, syntax-tree classification, safe checkbox widget, theme-only styling. |
| modified | `resources/js/lib/editor/codemirror.ts` | `onUpdate`/`onLink` options, GFM `markdownLanguage` base, `EditorState.readOnly`, app theme wiring, `.cm-line` padding fix. |
| modified | `resources/js/lib/editor/useEditorTick.ts` | Throws on an unsupported (no-`.on`) editor shape instead of silently no-opping. |
| modified | `resources/js/components/editor/SourceEditor.vue` | `revision` ref + `toolbarRef`, debounced preview, safe click handler, app theme watcher, `EditorState.readOnly` on the editable watcher. |
| modified | `resources/js/components/editor/EditorToolbar.vue` | `revision` prop, TipTap-only `useEditorTick` filter, fixed `currentHref()`, typed `LinkDialog` bindings, exposes `openLinkDialog()`. |
| modified | `resources/js/components/editor/LinkDialog.vue` | CodeMirror branch now validates the href, edits/replaces in place, and removes via `removeLink`. |
| modified | `resources/js/components/editor/NoteEditor.vue` | Copy-only: removed "CodeMirror"/"TipTap"/"CodeMirror 6" from user-facing strings. |
| modified | `tests/js/editor/codemirrorCommands.test.ts` | New commands, guard regressions, `isLinePrefixActive`. |
| modified | `tests/js/editor/toolbarCommands.test.ts` | New CodeMirror-branch command-table loop, `undoDepth`/`redoDepth` test. |
| replaced | `tests/js/editor/codemirrorLivePreview.test.ts` | Decoration-set assertions (FR-11/12/13/14/15/16/17). |
| created | `tests/js/editor/markdownPreview.test.ts` | `stripFrontmatter`, `isAllowedHref`, `renderMarkdownPreview` coverage. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `npm run test:js` | 240 passed, 0 failed (19 test files) |
| `npm run types:check` | Clean (0 errors) |
| `npm run check` (scoped to this feature's files) | Clean — see Deviations for why it was scoped |
| `npm run build` | Succeeded in 24.0s (pre-existing `Workspace` chunk-size warning, unrelated to this change) |
| `vendor/bin/pint --dirty --format agent` | Not run — no PHP file was touched |

No failures to paste; all four commands are clean on the final pass. One intermediate failure was found and fixed during development (documented below for QA's visibility):

```
FAIL tests/js/editor/codemirrorLivePreview.test.ts > decorates a checked task with a single replace range...
AssertionError: expected [] to have a length of 1 but got +0
```
Root cause: the line-classification probe position (`line.from + 1`) lands in the gap between a list marker (`"- "`) and the sibling `Task` syntax node, which starts only at the character after the marker+space — so it never resolved into `Task`. Fixed by probing near the end of the line (`line.to - 1`) instead, which is always inside the line's own content node for every case (heading, quote, rule, fenced code, task). Re-ran the full suite afterward — 240/240 pass.

---

## 4. Deviations from Plan

1. **`markdown()` base changed to `markdownLanguage` (GFM), not just `commonmarkLanguage`.** Not explicitly listed as a plan task, but required for T7 to work at all: `@codemirror/lang-markdown`'s `markdown()` defaults to `base: commonmarkLanguage`, which has **no** GFM extensions — task lists never produce `Task`/`TaskMarker` syntax nodes under plain CommonMark. Since `markdownLineDecorations` now classifies lines via the syntax tree (per the plan's explicit instruction to use `syntaxTree(state)` rather than a second regex parser), it needed the GFM-extended `markdownLanguage` base, which was confirmed by direct inspection of the `@lezer/markdown` parser output. This also better matches `insertLink`/`toggleTaskList`'s `- [ ] ` output and the `marked({ gfm: true })` preview renderer, both of which already assumed GFM syntax.

2. **Frontmatter exclusion uses a bounded manual line scan, not the syntax tree.** `@codemirror/lang-markdown` has no frontmatter grammar — a leading `---`/`---` block parses as `HorizontalRule` + `SetextHeading2` (confirmed by direct parser inspection), which is the exact bug FR-11/FR-19 describe. The requirements' own NFR explicitly permits this alternative ("use `syntaxTree(state)` over the visible ranges, **or a single forward fence/frontmatter scan**"). The scan is capped at 1000 lines and only runs when line 1 is literally `---`, so it stays O(bounded prefix), not O(document).

3. **`Mod-k` → Link dialog wiring uses a template ref + exposed method, not a Vue emit.** The plan described `SourceEditor.vue` emitting `open-link-dialog`, consumed by `EditorToolbar.vue`. Since `EditorToolbar` is a *child* of `SourceEditor` in the template (not a sibling), routing the CodeMirror keymap's `onLink` callback through an emit would require an awkward round-trip (`SourceEditor` emits to itself, or re-derives the event). Instead, `EditorToolbar.vue` does `defineExpose({ openLinkDialog })`, and `SourceEditor.vue` holds a `toolbarRef` template ref and calls `toolbarRef.value?.openLinkDialog()` directly from the keymap's `onLink` callback. Functionally identical; simpler and more idiomatic Vue 3.

4. **`useEditorTick`'s "fail loudly" guard throws unconditionally when given a no-`.on()` editor**, rather than accepting an `external?: Ref<number>` fallback (the plan offered either as an option: "document it, OR accept an external... ref"). `EditorToolbar.vue` now filters `getEditor` to return `undefined` for a CodeMirror `EditorView` (`activeEditor.value && isTipTap(activeEditor.value) ? activeEditor.value : undefined`), so the throw path is never hit in normal operation — CodeMirror's active/enabled state comes entirely from `props.revision` instead, read unconditionally alongside `tick.value` in `isActive()`/`canRun()`. This is simpler than threading an external ref through `useEditorTick` and achieves the same "never silently no-op" guarantee for any future caller that gets the filter wrong.

5. **`clearFormatting`'s line-prefix stripping is a single pass, matching `clearLinePrefix`/`toggleLinePrefix`'s existing convention**, not a loop that fully unwraps nested prefixes (e.g. `> ## Quoted` → `## Quoted`, not `Quoted`). The plan's T1 bullet says `clearFormatting` "calls the `clearLinePrefix` logic for the touched lines" (singular), and every other line-prefix helper in the file strips exactly one matched prefix per line. FR-06's acceptance criterion ("the line prefix is removed as well") is satisfied without over-reaching into multi-level unwrapping, which was not requested and would be a new behavior for `clearLinePrefix`/`toggleLinePrefix` too if added inconsistently.

6. **`markdownPreview.ts`'s link renderer override also covers `image()`**, escaping `src`/`alt`/`title` and applying the same protocol allowlist, even though FR-20's text only calls out the link renderer. Marked's default image renderer has the identical unescaped-interpolation shape as the link renderer it was replacing; leaving it unescaped would keep an XSS path open via `![x](javascript:...)`-style image syntax. No behavior change for legitimate images (`http`/`https`/relative paths still render).

None of these deviations touch architecture, scope, or the two ADRs; all stay within `resources/js/**` and `tests/js/**`.

---

## 5. Notes for QA

- **`npm run check` was run scoped to only the files this feature touched** (passed as explicit paths to `vp check --fix`), not the whole repo. A full, unscoped `npm run check` reports 17 pre-existing formatting issues in files this feature never touches (`resources/css/app.css`, `AppLogoIcon.vue`, `AppSidebarHeader.vue`, `AppearanceTabs.vue`, `DirectoryBrowserDialog.vue`, `StatusBar.vue`, `RestoreBackupDialog.vue`, `FrontmatterPanel.vue`, `TiptapEditor.vue`, `layouts/settings/Layout.vue`, `pages/Workspace.vue`, five `pages/settings/*.vue` files) — these predate this feature and were not introduced or worsened by it. Confirm this with `git status`/`git diff` on those paths if you want to double check.
- **`insertHorizontalRule`'s exact caret/blank-line placement is a reasonable interpretation, not a byte-for-byte spec.** The plan says "normalising surrounding blank lines" without an exact algorithm. The implementation inserts `\n\n---\n` after the current line when there is no existing trailing blank line, or `\n---\n` when one already exists (to avoid doubling it) — verified against the exact FR-05 acceptance example (`- item` → `- item\n\n---\n`). Worth a manual look in Split/Preview view to confirm the rendered rule looks right when inserted in the middle of a document with varying surrounding whitespace.
- **`clearFormatting` on a line with a nested prefix** (e.g. a blockquoted heading) only strips the outermost prefix in one click — see Deviation 5. If this reads as insufficient during QA, it's a one-line change (loop instead of single match) rather than an architecture issue. **[Superseded in Fix Round 1 / QA-03 — see §7. Both `clearFormatting` and `clearLinePrefix` now fully unwrap nested prefixes in one call per the user's decision.]**
- **The frontmatter scan is capped at 1000 lines.** A note with a leading `---` that is never closed within the first 1000 lines falls back to treating it as an ordinary (undecorated-as-frontmatter) horizontal rule — this is an intentional bound to keep the scan O(small prefix), not O(document), per the performance NFR. Pathological in practice (frontmatter is never 1000 lines), but worth knowing if a synthetic test note trips it.
- **Dark-mode colour values in `appHighlightStyle`/`appEditorTheme` are a best-effort mapping onto the app's existing CSS variables** (`--primary`, `--muted-foreground`, `--border`, etc.), not picked against a designer-reviewed palette — this was explicitly accepted as the Q1 trade-off (replacing `oneDark`'s colours). Worth a visual pass in the T12 walkthrough below.
- **No PHP file was touched** — `vendor/bin/pint` was correctly skipped per the task instructions.

---

## 6. Manual UI Walkthrough (run this in a real browser — no automated test in this repo can mount a DOM)

Start the dev server first: `composer run dev` (or `npm run dev` + `php artisan serve` if you run them separately), then open a note in MDVault and work through these in order. This is the step both prior CodeMirror passes skipped.

### A. Toolbar buttons (Markdown/CodeMirror mode, desktop width)
1. Open a note that has a mix of content, or paste in a scratch note:
   ```
   # Heading one
   Some para text with **bold** and *italic* and `code`.
   - [ ] a task
   - bullet item
   > a quote
   ```
2. Click into the "Some para text..." line and click **Bold**, **Italic**, **Strikethrough**, **Inline code** one at a time on a selected word — each should wrap the selection with the right Markdown marker, and the button should light up (pressed state) when your caret/selection is inside that marker afterward.
3. Select the word `bold` inside `**bold**` and click **Italic** — it must become `***bold***` (bold preserved), not silently strip the bold.
4. Click into `# Heading one` and click **Paragraph** (overflow menu on narrow widths) — the line should become plain `Heading one` text, no `#`.
5. Click into a bullet or heading line and click **Divider** — a `---` should appear on its own line below, and the bullet/heading marker on the original line must still be there.
6. Select the whole formatted line (`**bold** and *italic* and \`code\``) and click **Clear formatting** — it should become plain `bold and italic and code`.
7. Click **H1**/**H2**/**H3**, **Bullet list**, **Numbered list**, **Task list**, **Blockquote**, **Code block** on various lines and confirm each does what its icon says and the button lights up when your caret returns to that line.
8. With the caret at the very start of the note (nothing typed yet, or right after opening), confirm **Undo** and **Redo** are both disabled (greyed out). Type a character — **Undo** should become enabled. Undo it — **Redo** should become enabled.

### B. Keyboard shortcuts
9. With focus in the editor, try each shortcut and confirm it runs the same command as its toolbar button (not the browser's native bold/italic): `Ctrl+B`, `Ctrl+I`, `Ctrl+E`, `Ctrl+Shift+X`, `Ctrl+Alt+1`, `Ctrl+Alt+2`, `Ctrl+Alt+3`, `Ctrl+Shift+8`, `Ctrl+Shift+7`, `Ctrl+Shift+9`, `Ctrl+Shift+B`, `Ctrl+Alt+C`, `Ctrl+\`.
10. Press `Ctrl+K` — the Link dialog should open (same as clicking the Link button).
11. Press `Ctrl+Z` / `Ctrl+Shift+Z` — confirm undo/redo still work (these come from CodeMirror's built-in history keymap, not the new one).

### C. Link dialog
12. Place the caret inside an existing link's text, e.g. `[text](https://a.test)`, and click the **Link** button — the URL field should already show `https://a.test`.
13. Change it to `https://b.test` and click **Apply** — the line should become `[text](https://b.test)` with no nesting or duplication.
14. With the caret still in that link, click **Remove** — the line should become plain `text` (link syntax gone).
15. Open the Link dialog with no selection, type `https://example.com`, click **Apply** — it should insert `[link](https://example.com)` with the word "link" selected so you can type over it immediately.
16. Open the Link dialog and type `javascript:alert(1)`, click **Apply** — it must show an error and insert nothing.

### D. Live preview rendering
17. Open a note with YAML frontmatter at the top, e.g.:
    ```
    ---
    title: Test
    tags: []
    ---

    # Real heading
    ```
    Confirm the `---` lines are not rendered as a horizontal rule and the `title:`/`tags:` lines are not rendered as a heading; `# Real heading` should still render normally.
18. Add a fenced code block containing a line that looks like a heading or comment marker, e.g.:
    ````
    ```bash
    # this is a bash comment, not a heading
    echo hi
    ```
    ````
    Confirm the `# this is a bash comment` line stays plain monospace text inside the fence block (no heading styling), and the whole fence (open delimiter, body, close delimiter) reads as one continuous bordered/background block, not two disconnected grey bands.
19. Click a task checkbox (`- [ ] todo`) once while the editor does **not** have focus (click elsewhere first, then click the checkbox) — it should toggle reliably on the first click, and the text caret should not jump into the checkbox's hidden `[ ]` text.
20. Check a task — confirm only the text *after* the checkbox gets a strikethrough, not the checkbox/bullet itself.
21. Scroll to the bottom of a long (~300-line) note with line numbers on and a mix of headings/quotes/fences; confirm the gutter's line numbers stay aligned with their lines (no drift), and clicking anywhere places the caret on the clicked line, not a neighbouring one.

### E. Layout, theming, split/preview
22. Switch between **Editor**, **Split**, and **Preview** view modes at desktop width, then narrow the browser to phone width (~400px) and repeat.
23. Toggle the app's light/dark appearance setting while in each of the three view modes — the editor pane's background should match the surrounding card (no mismatched rectangle), and nothing should require a page reload to restyle.
24. In Split view, confirm the editor pane and the preview pane look like one visually consistent surface (not two different colour systems) in both light and dark mode.
25. Type continuously in Split view on a reasonably large note and confirm the editor itself stays responsive (no per-keystroke lag) — the rendered preview on the right should catch up shortly after you stop typing, not instantly on every keystroke.
26. Click a link in the rendered Preview pane that points to `https://...` — it should open in a new tab. If you have a test note with a `javascript:` link, clicking it should do nothing.

### F. Mode toggle labels
27. Look at the segmented control in the note header — it should say **Markdown** / **Rich text**, not "CodeMirror" / "TipTap", and hovering should not show any library name in the tooltip.
28. Open a note larger than ~150KB (or check the banner text if you have one) — the banner should say the note is in "Markdown mode", not "CodeMirror 6 Source mode".

Report back anything that doesn't match the above — those are exactly the FR-01 through FR-22 defects this feature set out to fix.

---

## 7. Fix Round 1 (QA-01 through QA-04)

**QA-01 — Missing `codemirror.test.ts` assertions.** Added the three assertions plan.md §4's Test Plan called for, in `tests/js/editor/codemirror.test.ts`:
- `EditorState.readOnly` is `true` for `readonly: true` (editable: true) and for `editable: false` (readonly: false), and `false` for the normal editable case — verified via `state.readOnly` (the documented `EditorState` getter over the `readOnly` facet).
- `buildCodeMirrorExtensions` accepts `onUpdate` without throwing, and the built extension list registers the formatting keymap — verified by reading `state.facet(keymap)` (CodeMirror's own `keymap` facet, re-exported as `EditorView`/`@codemirror/view`'s `keymap`) and asserting it contains `Mod-b`/`Mod-i`/`Mod-k` alongside `defaultKeymap`'s `Mod-a`.
- `buildTypographyTheme` no longer emits a `padding` shorthand for `.cm-line` — verified by reading the compiled CSS off `state.facet(EditorView.styleModule)` (each `StyleModule.getRules()` call) and asserting the `.cm-line` rule contains `padding-left`/`padding-right` but no bare `padding:`.

All three are plain `EditorState`/facet assertions; no DOM was needed. `npx vitest run tests/js/editor/codemirror.test.ts` passes (11 tests, up from 4).

**QA-02 — Overlapping theme/typography colour properties.** Removed the duplicated colour properties from `buildTypographyTheme` (`codemirror.ts`): `.cm-content.caretColor` and `.cm-gutters.backgroundColor/.color/.borderRight` are no longer set there. `buildTypographyTheme` now owns only font/size/line-height/padding (`&`, `.cm-scroller`, `.cm-content.padding`, `.cm-gutters.fontSize`, `.cm-line`, `&.cm-focused`); `appEditorTheme` (`codemirrorTheme.ts`) is the sole owner of all colour properties, as the plan's T8 risk mitigation required. No visual change expected — both sides resolved to the same token chain before this fix.

**QA-03 — Clear Formatting/clearLinePrefix single-pass vs full unwrap.** Per the user's decision, both `clearLinePrefix` and `clearFormatting` (`codemirrorCommands.ts`) now fully unwrap every stacked line prefix in one call (e.g. `> ## Quoted` → `Quoted`, not `## Quoted`), via a new shared `stackedLinePrefixLength()` helper that loops `LINE_PREFIX_PATTERN` until no more prefix matches. This supersedes Deviation 5 in §4 above, which documented the original single-pass behaviour as intentional — it is no longer the shipped behaviour. Added `tests/js/editor/codemirrorCommands.test.ts` coverage for a blockquoted heading (`clearLinePrefix`, `clearFormatting`) and a blockquoted task item (`clearLinePrefix`), proving full unwrapping in one call; updated the stale "one marker type per pass" comment on the existing single-layer `clearFormatting` test. `toggleLinePrefix`'s own single-match prefix-replacement logic (used when switching block type, e.g. heading → bullet) was left unchanged — QA-03 only named `clearLinePrefix`/`clearFormatting`.

**QA-04 — Undocumented keymap collisions (T5 risk mitigation).** Checked the new `Prec.high` formatting keymap's 14 bindings (`codemirrorKeymap.ts`) against `@codemirror/commands`' `defaultKeymap`/`historyKeymap` and `@codemirror/lang-markdown`'s `markdownKeymap`, by reading the installed packages' source directly:
- **One collision found**: `Mod-i` is bound in `defaultKeymap` to `selectParentSyntax` (`preventDefault: true`). The formatting keymap's own `Mod-i` (`toggleItalic`) is registered via `Prec.high` and `keymap.of([...defaultKeymap, ...])` is registered without `Prec.high`, so the formatting binding wins and `selectParentSyntax` is permanently shadowed in this editor. This is the intended FR-10 behavior (the advertised shortcut must win over any native/default binding), not a bug.
- **No other collision**: the remaining 13 bindings (`Mod-b`, `Mod-e`, `Mod-Shift-x`, `Mod-Alt-1/2/3`, `Mod-Shift-7/8/9`, `Mod-Shift-b`, `Mod-Alt-c`, `Mod-\`, `Mod-k`) do not match any `defaultKeymap`/`historyKeymap` key (checked `Mod-z`/`Mod-y`/`Mod-Shift-z`/`Ctrl-Shift-z`/`Mod-u`/`Alt-u`/`Mod-Shift-u` for history, and the full `Mod-*` list in `@codemirror/commands`, including the superficially similar `Mod-Alt-\` (`indentSelection`), which is a different combo from our plain `Mod-\`). `historyKeymap`'s `Mod-z`/`Mod-Shift-z` remain untouched and keep working, matching the T12 walkthrough's item 11.
- `markdownKeymap` (from `@codemirror/lang-markdown`) only binds `Enter` and `Backspace`, also at `Prec.high` — no overlap with any of the 14 formatting shortcuts.

### Verification re-run after Fix Round 1
| Command | Result |
|---|---|
| `npx vitest run tests/js/editor/codemirror.test.ts tests/js/editor/codemirrorCommands.test.ts` | 44 passed |
| `npm run test:js` | 246 passed, 0 failed (19 test files) |
| `npm run types:check` | Clean, 0 errors |
| `npm run check` (scoped to the files touched in this round) | 0 errors, 1 pre-existing unused-variable warning in `codemirror.test.ts:42` (`changedText`, in a test this round did not touch — predates Fix Round 1) |
| `npm run build` | Succeeded in 11.8s (same pre-existing `Workspace` chunk-size warning) |

No new deviations beyond what's described above for QA-02/QA-03. QA-01 and QA-04 were pure test/documentation additions with no production-code behavior change.

---

## 8. Fix Round 2 (user-reported post-walkthrough bugs)

Both prior automated QA rounds passed, but the user's own manual browser walkthrough (§6 above) surfaced two real bugs that no automated test in this repo could have caught. Both are fixed below.

**Bug 1 — `Ctrl+B` toggles the app sidebar at the same time as applying Bold in the editor.**

- **File**: `resources/js/components/ui/sidebar/SidebarProvider.vue:52-59`
- **Root cause**: `SidebarProvider.vue` installs a global `useEventListener("keydown", ...)` (from `@vueuse/core`, attached to `window`) that checks `event.key === SIDEBAR_KEYBOARD_SHORTCUT && (event.metaKey || event.ctrlKey)` and calls `toggleSidebar()` unconditionally. `resources/js/lib/editor/codemirrorKeymap.ts`'s formatting keymap handles `Mod-b` for Bold and returns `true`, which makes CodeMirror call `event.preventDefault()` on the keydown — but CodeMirror's keymap handling never calls `stopPropagation()`, by design, so the keydown still bubbles up to `window`. The sidebar listener never checked `event.defaultPrevented`, so it fired `toggleSidebar()` regardless of what had already handled the key lower in the DOM.
- **Fix**: added an early return at the top of the keydown handler — `if (event.defaultPrevented) return;` — before the `SIDEBAR_KEYBOARD_SHORTCUT` check. This is the general-purpose fix: it respects whichever element already handled the shortcut (not just CodeMirror), and requires no editor-specific knowledge in `SidebarProvider.vue`.
- **Behavior preserved**: when focus is outside the editor (or any other element that calls `preventDefault()` on `Ctrl/Cmd+B`), `event.defaultPrevented` is `false` and the sidebar still toggles exactly as before — confirmed by reading through the guard logic; the early return only short-circuits when something else already consumed the keydown.
- **Test coverage**: no automated test was added for this handler. This repo's JS test suite (`tests/js/**`) contains only plain module/unit tests (pure functions operating on `EditorState`/strings); there is no `@vue/test-utils` or DOM-mounting test harness installed for Vue SFCs, and adding one would be a new dependency, which requires separate user approval per project rules. The fix is a one-line, low-risk early-out on a native DOM event property (`event.defaultPrevented`), verified by code reading rather than a new test.

**Bug 2 — In Split view, the editor pane and preview pane visually overlap instead of sitting side-by-side.**

- **File**: `resources/js/components/editor/SourceEditor.vue` (editor pane ~line 286, preview pane ~line 297)
- **Root cause**: the two flex children in the Code/Split/Preview row container (`sm:flex-row` parent) both had `min-h-0` but not `min-w-0`. Flex items default to `min-width: auto`, so in row layout each pane refuses to shrink below its content's intrinsic min-content width — the editor's long/unwrapped lines or the preview's wide tables/code blocks can force a pane wider than its allotted 50%, causing the two panes to fight for space instead of splitting cleanly. This codebase already uses `min-w-0` as its established convention for exactly this flex-overflow problem elsewhere (e.g. `NoteEditor.vue`, `AppSidebarLayout.vue`, `Workspace.vue`) — it was simply missing on these two panes.
- **Fix**: added `min-w-0` to both pane `div`s' class lists. After `npx vp check --fix` (project's formatter) ran, the classes read `min-h-0 w-full min-w-0 flex-1 overflow-hidden` (editor pane) and `preview-scroll-container min-h-0 w-full min-w-0 flex-1 overflow-y-auto bg-card/60 p-4 sm:p-6 md:p-8` (preview pane) — reordered by the formatter, not by hand.
- **Important caveat for QA**: this root cause is a static/code-level diagnosis (a well-established flexbox + CodeMirror pitfall — `min-width: auto` on flex children with intrinsically wide content), confirmed by reading the Tailwind classes and the parent's `flex`/`sm:flex-row` layout. It has **not** been visually confirmed in a real browser by a human, since no automated test in this repo can render or screenshot the layout. This still needs a manual check: open a note, switch to Split view at desktop width, and confirm the two panes sit cleanly side-by-side (50/50, no overlap) with a long unwrapped line in the editor and/or a wide table/code block in the preview.

### Verification re-run after Fix Round 2
| Command | Result |
|---|---|
| `npm run test:js` | 246 passed, 0 failed (19 test files) — unchanged, no new tests added (see Bug 1 note above) |
| `npm run types:check` | Clean, 0 errors |
| `npx vp check` (scoped: `SourceEditor.vue`; `SidebarProvider.vue` is under `resources/js/components/ui/*`, which `vite.config.ts`'s `lint`/`fmt.ignorePatterns` intentionally excludes — shadcn-vue vendor files are not linted/formatted by project convention) | `SourceEditor.vue`: 0 errors after `--fix` reordered the new `min-w-0` class alongside existing classes; `SidebarProvider.vue`: correctly skipped (0 target files matched, by design) |
| `npm run build` | Succeeded in 8.81s (same pre-existing `Workspace` chunk-size warning, unrelated to this change) |
| `vendor/bin/pint --dirty --format agent` | Not run — no PHP file was touched |

### Notes for QA (Fix Round 2)
- Bug 1's fix touches a shared, global keydown handler (`SidebarProvider.vue`) used across the whole app, not just the editor. Worth a quick manual check that `Ctrl/Cmd+B` still toggles the sidebar normally from a plain page (e.g. Settings, or a note with focus outside the editor), to confirm the early-return guard doesn't accidentally swallow the toggle when nothing else has called `preventDefault()`.
- Bug 2's fix is unverified in a real browser — see caveat above. Please visually confirm in Split view at both desktop and phone (~400px) width, in light and dark mode, per the existing manual walkthrough §6 item 22-24.
