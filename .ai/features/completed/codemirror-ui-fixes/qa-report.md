# QA Report: CodeMirror Editor UI Defect Remediation

## Metadata (Round 2 — current)
- **Feature Name**: CodeMirror Editor UI Defect Remediation
- **Feature ID**: feat-codemirror-ui-fixes
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-10-07
- **Verdict (Round 2)**: **PASS (automated scope)** — all four Round 1 MINOR issues (QA-01–QA-04) confirmed fixed by direct code/test inspection and independent re-execution. Zero open Critical/High/Medium issues. **Still conditional**: the manual browser walkthrough in `implementation.md` §6 remains outstanding, unchanged from Round 1.

---

## QA Round 2

### R2.1 Issue-by-Issue Verification

| ID | Round 1 Severity | Status | Evidence |
|---|---|---|---|
| **QA-01** | Medium (MINOR) | **FIXED** | `tests/js/editor/codemirror.test.ts` grew from 4 to 11 tests. (a) `state.readOnly` asserted `true` for `readonly:true` and for `editable:false`, and explicitly `false` for the normal editable case (lines 77–121) — includes a real negative case. (b) `state.facet(keymap)` is read and flattened to assert `Mod-b`/`Mod-i`/`Mod-k` are present alongside `defaultKeymap`'s `Mod-a` (lines 123–159) — proves the formatting keymap is actually registered. (c) `buildTypographyTheme`'s compiled CSS is read via `state.facet(EditorView.styleModule)` → `getRules()`; `.cm-line` is asserted to contain `padding-left`/`padding-right` but no bare `padding:` shorthand (lines 161–177). All three inspect real `EditorState`/facet output, not mocks. |
| **QA-02** | Low (MINOR) | **FIXED** | `codemirror.ts:60-87` (`buildTypographyTheme`) no longer sets `.cm-content.caretColor` or any `.cm-gutters` colour property. `codemirrorTheme.ts:13-47` (`appEditorTheme`) is the sole remaining owner of those properties, and it's registered unconditionally in `buildCodeMirrorExtensions` (`codemirror.ts:144-147` — only the resolved *values* change with `isDark`, not whether it's registered), so caret colour and gutter styling are each set by exactly one compartment. No visual gap. |
| **QA-03** | Low (MINOR) | **FIXED** | `codemirrorCommands.ts:164-174` adds `stackedLinePrefixLength()`, a real `while` loop over `LINE_PREFIX_PATTERN` until no further match. Both `clearLinePrefix` (183-213) and `clearFormatting` (335-375) use it. New tests prove multi-layer unwrapping (`> ## Quoted` → `Quoted`, `> - [ ] task` → `task`) in `codemirrorCommands.test.ts:189-199,250-254`; 44/44 pass. **FR-04 (Paragraph) unaffected**: `toolbarCommands.ts:151-164` maps `paragraph` → the same `clearLinePrefix`, which only ever touches block-level `LINE_PREFIX_PATTERN` matches (never inline marks) and only within the current selection's lines — the existing table-driven CodeMirror-branch test (`toolbarCommands.test.ts:145-157`) still passes unchanged. The fix goes deeper per line, not wider across lines, so no cascade risk materializes. |
| **QA-04** | Low (MINOR) | **FIXED** | `implementation.md` §7 documents the keymap collision check: one real collision found (`Mod-i` shadows `defaultKeymap`'s `selectParentSyntax`, intentionally won by the `Prec.high` formatting keymap per FR-10), and the other 13 bindings checked and found not to collide with `defaultKeymap`/`historyKeymap`/`markdownKeymap`. Satisfies the plan's T5 risk-mitigation requirement. |

### R2.2 Verification Re-run (independent of the Developer's own claims)

| Command | Result |
|---|---|
| `npx vitest run tests/js/editor/codemirror.test.ts tests/js/editor/codemirrorCommands.test.ts` | **44 passed** |
| `npx vitest run tests/js/editor/` (all 12 editor files) | **163 passed** (up from 157 in Round 1 — delta of 6 matches the new assertions/tests) |
| `npm run test:js` | **246 passed, 0 failed** (19 test files) |
| `npm run types:check` | **Clean, 0 errors** |
| `npm run check` (scoped to the 5 files touched in Fix Round 1) | **0 errors**, 1 pre-existing lint warning (`no-unused-vars` on `changedText` in `codemirror.test.ts:42`, untouched by this round) |
| `npm run build` | **Succeeded** (same pre-existing `Workspace` chunk-size warning) |
| `php artisan test --compact tests/Feature/Notes/` | **54 passed, 243 assertions, 0 failed** — no backend regression; no `.php` file modified at any round |

No discrepancies between claimed and re-run results. No new issues introduced by Fix Round 1.

### R2.3 Carried-Forward Note (unchanged from Round 1 — do not drop)

Fixing QA-01–QA-04 closes four coverage/documentation/behavior gaps, but does **not** change the structural fact that this repo's JS test environment is Node-only (`vite.config.ts` pins `test.environment: 'node'`). The following remain **unverified by any automated suite** and require the human walkthrough in `implementation.md` §6 (items 1–28):
- FR-01 (toolbar live-updates via `revision`)
- FR-08 (Link dialog DOM round-trip)
- FR-10 (keyboard shortcuts actually suppress native `contenteditable` behavior)
- FR-13/14/15/18 (rendered CSS cascade, including QA-02's theme/typography split producing no visible caret/gutter regression)
- FR-17a (checkbox click/focus DOM behavior)
- FR-21 (perceived typing latency)
- FR-22 (visual label check)

**Do not move this feature to `.ai/features/completed/` until a human has run `implementation.md` §6.**

### R2.4 Routing Recommendation

- [x] **PASS (automated scope)** — QA-01–QA-04 all confirmed fixed; zero open Critical/High/Medium issues; all commands reproduce the Developer's claimed results exactly; backend regression suite green; no scope violation.
- [ ] **Do not move to `.ai/features/completed/` yet** — the manual browser walkthrough (`implementation.md` §6, items 1–28) remains the only way to verify FR-01, FR-08, FR-10, FR-13/14/15/18, FR-17a and FR-21.
- [ ] **FAIL — MINOR/MODERATE** → None open.
- [ ] **FAIL — MAJOR** → None.
- [ ] **ESCALATE → System Analyst** → None. No issue reached 3 fix attempts (all four closed on the first attempt).

---

## QA Round 1 (original report, preserved for history)

## Metadata
- **Feature Name**: CodeMirror Editor UI Defect Remediation
- **Feature ID**: feat-codemirror-ui-fixes
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-10-07
- **Verdict**: **PASS (automated scope)** — zero open Critical/High issues found by code audit or by the automated test commands in plan.md §4. **This verdict covers only what is statically/node-verifiable. See §0 — the manual browser walkthrough in `implementation.md` §6 is a mandatory outstanding item and has not been run by QA or the user.**

> Per `AGENTS.md`/`CLAUDE.md`: PASS requires zero open Critical or High issues. Medium/Low issues remain as documented follow-ups below.

---

## 0. Why This PASS Is Conditional on a Manual Walkthrough

This repository's JS test environment is Node-only (`vite.config.ts:45-48` pins `test.environment: 'node'`). No test in `tests/js/` — before or after this feature — can mount a real `EditorView` or a Vue component. That is the exact structural gap `requirements.md` §1 and `plan.md` §4 describe, and it is **unchanged by this feature**: the fix extracts logic into pure functions so it is *unit-testable*, but nothing in this repo can prove the DOM, CSS cascade, Vue reactivity and CodeMirror view-plugin wiring behave correctly in a real browser.

Concretely untestable by `npm run test:js` and therefore **unverified by QA**, regardless of this report's verdict:
- FR-01 (toolbar re-renders live on caret/selection/doc change via `revision` prop)
- FR-13/FR-14/FR-15/FR-18 (actual rendered CSS cascade — Tailwind-vs-unlayered-theme precedence, dark-mode token mapping, line-height margin exclusion)
- FR-17a (checkbox click not stealing focus/caret via `ignoreEvent`)
- FR-10 (keyboard shortcuts actually suppress native `contenteditable` behavior)
- FR-08 (Link dialog prefill/replace/remove round-trip through real DOM events)
- FR-21 (perceived typing latency in Split view)
- FR-22 (visual label check)

**QA explicitly does not certify these as working.** `implementation.md` §6 (Manual UI Walkthrough, items 1–28) is the only way to verify them and remains an **outstanding user-verification item** independent of this report's verdict. Do not move this feature to `.ai/features/completed/` until a human has run that walkthrough.

---

## 1. Requirements Coverage

| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 (toolbar tracks CM view) | Yes — `revision` prop + `onUpdate` listener (`codemirror.ts:154-166`, `SourceEditor.vue:106-111`, `EditorToolbar.vue:75-91`) | Code review only; DOM-dependent | ⚠️ Unverified by automated suite — manual walkthrough item 2/7 required |
| FR-02 (undo/redo enablement) | Yes — `undoDepth`/`redoDepth` (`toolbarCommands.ts:69-85`) | `tests/js/editor/toolbarCommands.test.ts:160-182` | ✅ |
| FR-03 (italic doesn't strip bold) | Yes — longer-run guard (`codemirrorCommands.ts:34-57`) | `tests/js/editor/codemirrorCommands.test.ts:134-150` | ✅ |
| FR-04 (Paragraph clears prefix) | Yes — `clearLinePrefix` (`codemirrorCommands.ts:164-194`) | `codemirrorCommands.test.ts:152-187`, `toolbarCommands.test.ts:145-157` | ✅ |
| FR-05 (Divider inserts rule correctly) | Yes — `insertHorizontalRule` (`codemirrorCommands.ts:278-298`) | `codemirrorCommands.test.ts:190-214`, `toolbarCommands.test.ts:145-157` | ✅ |
| FR-06 (Clear formatting works in CM) | Yes, with a documented narrowing (single-pass prefix strip) — `clearFormatting` (`codemirrorCommands.ts:315-355`) | `codemirrorCommands.test.ts:216-239` | ✅ (see QA-03) |
| FR-07 (Link insertion with no selection) | Yes — `||` fallback + label selection (`codemirrorCommands.ts:363-385`) | `codemirrorCommands.test.ts:242-271` | ✅ |
| FR-08 (Link dialog prefill/edit/remove in CM) | Yes — `linkAt`/`removeLink`, typed `EditorToolbar.vue`/`LinkDialog.vue` bindings, no `as any` | `codemirrorCommands.test.ts:274-304` (logic only); dialog itself is DOM-dependent | ⚠️ Logic verified; dialog UI unverified — manual walkthrough items 12–16 required |
| FR-09 (protocol validation in CM Link dialog) | Yes — `isAllowedHref` gate in `LinkDialog.vue:63,78` | `markdownPreview.test.ts:30-52` (shared helper); dialog UI unverified | ⚠️ Helper verified; dialog UI unverified — manual walkthrough item 16 |
| FR-10 (keyboard shortcuts exist) | Yes — `codemirrorKeymap.ts` registered `Prec.high` before default keymap | Code review only; needs a real `contenteditable` to prove native handling is suppressed | ⚠️ Unverified — manual walkthrough items 9–11 required |
| FR-11 (no decoration inside fences/frontmatter) | Yes — syntax-tree classification + bounded frontmatter scan (`codemirrorLivePreview.ts`) | `codemirrorLivePreview.test.ts:58-96` | ✅ (scan has a documented bound, see QA-05) |
| FR-12 (decoration builder never throws) | Yes — unique-line dedupe (`markdownLineDecorations`) | `codemirrorLivePreview.test.ts:123-143` | ✅ |
| FR-13 (no vertical margin on `.cm-line`) | Yes by code review (no `margin-top`/`margin-bottom` found in `livePreview()` or `buildTypographyTheme`) | Partially — test only checks decoration *class names*, not the theme object's CSS properties | ⚠️ See QA-02 |
| FR-14 (styling via theme, not Tailwind utilities) | Yes — `cm-md-*` classes with no Tailwind utility strings | `codemirrorLivePreview.test.ts:164-189` | ✅ |
| FR-15 (heading sizes in `em`, scale with font_size) | Yes — `fontSize: '2em'`/`'1.5em'`/`'1.25em'`/... | Covered transitively by FR-14's test; no explicit `em`-unit assertion | ✅ (acceptable) |
| FR-16 (fence body styled, not just delimiters) | Yes — `cm-md-fence-open/body/close` | `codemirrorLivePreview.test.ts:145-162` | ✅ |
| FR-17 (checkbox reliability + read-only guard) | Yes — `ignoreEvent` default restored, explicit `click` handler, `view.state.readOnly` guard, `EditorState.readOnly.of(!isEditable)` wired in `codemirror.ts:138` and `SourceEditor.vue:149` | `codemirrorLivePreview.test.ts:98-121` (toggle/offset logic only) | ⚠️ Logic verified; the actual click-doesn't-steal-focus and read-only-blocks-write behavior is DOM-dependent and **has no regression test at all** — see QA-01. Manual walkthrough items 19–20 required. |
| FR-18 (dark mode via app tokens) | Yes — `appEditorTheme`/`appHighlightStyle`, `oneDark` import removed | None (CSS cascade, DOM-only) | ⚠️ Unverified — manual walkthrough items 22–24 required. See also QA-02 (theme/typography property overlap). |
| FR-19 (preview strips frontmatter) | Yes — `stripFrontmatter` with bounded-pattern match | `markdownPreview.test.ts:8-27` | ✅ |
| FR-20 (preview escapes attrs, blocks protocols) | Yes — `escapeAttribute`, protocol allowlist in link **and** image renderer (Deviation 6) | `markdownPreview.test.ts:55-107` | ✅ |
| FR-21 (preview debounced) | Yes — `refDebounced(text, 200)` (`SourceEditor.vue:230`) | No automated test (timer/DOM); acceptable given `@vueuse/core` is a trusted primitive | ⚠️ Unverified — manual walkthrough item 25 |
| FR-22 (mode toggle copy) | Yes — "Markdown"/"Rich text" labels, no library names (`NoteEditor.vue:653-692,804-808`) | None needed (copy-only) | ✅ |

---

## 2. Test Execution

| Command | Result |
|---|---|
| `npm run test:js` | **240 passed, 0 failed** (19 test files) — matches `implementation.md` §3 |
| `npm run types:check` | **Clean, 0 errors** |
| `npm run check` (unscoped, full repo) | 17 pre-existing formatting issues, confirmed by file list to be entirely outside this feature's changed files (`resources/css/app.css`, `AppLogoIcon.vue`, `AppSidebarHeader.vue`, `AppearanceTabs.vue`, `DirectoryBrowserDialog.vue`, `StatusBar.vue`, `RestoreBackupDialog.vue`, `FrontmatterPanel.vue`, `TiptapEditor.vue`, `layouts/settings/Layout.vue`, `pages/Workspace.vue`, 5 `pages/settings/*.vue`) — confirmed out of scope |
| `npm run build` | **Succeeded in 6.7s** (pre-existing `Workspace` chunk-size warning, unrelated) |
| `php artisan test --compact tests/Feature/Notes/` | **54 passed, 243 assertions, 0 failed** — confirms no backend scope violation |
| `npx vitest run tests/js/editor/` (all 12 editor test files) | **157 passed** |
| `npx vitest run tests/js/markdown/roundTrip.test.ts tests/js/markdown/assess.test.ts` | **30 passed** — round-trip fixtures unaffected |
| `git diff --stat tests/js/editor/toolbarCommands.test.ts` | **88 insertions, 0 deletions** — confirms the original TipTap-path tests (`:1-98`) were not modified, only a new `describe` block appended (`:100-183`) |

No failures to paste. All commands in plan.md §4's test scope are green.

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Medium | MINOR | `tests/js/editor/codemirror.test.ts` (entire file, vs. `plan.md` §4 Test Plan table row for this file) | Plan's Test Plan explicitly calls for extending `codemirror.test.ts` to assert: (a) `EditorState.readOnly` is `true` when `editable: false`/`readonly: true` — this is the FR-17b data-integrity fix (a frozen/read-only document must never accept a programmatic write); (b) `buildCodeMirrorExtensions` accepts `onUpdate` and the built list contains the formatting keymap (FR-01/FR-10); (c) `buildTypographyTheme` no longer emits the `padding` shorthand for `.cm-line` (FR-14/FR-17). None of these three assertions exist anywhere in `tests/js/` (confirmed by `grep -rn "readOnly" tests/js` → no matches). The file's content is unchanged generic smoke tests. `implementation.md`'s Task Progress table marks T4/T7/T8 "DONE" and its Deviations section (§4) does not disclose this gap. | Add the three missing assertions to `tests/js/editor/codemirror.test.ts` (all are plain `EditorState`/array checks, no DOM needed — e.g. `expect(EditorState.create({ extensions: buildCodeMirrorExtensions({ editable: false, readonly: true, ... }) }).readOnly).toBe(true)`), or explicitly document the gap as an accepted deviation. Underlying runtime code was manually verified correct by QA; this is a coverage gap, not a behavior bug. | 0 |
| QA-02 | Low | MINOR | `resources/js/lib/editor/codemirror.ts:73-91` (`buildTypographyTheme`) vs. `resources/js/lib/editor/codemirrorTheme.ts:20-33` (`appEditorTheme`) | Both themes set `.cm-content.caretColor` and all three of `.cm-gutters.backgroundColor/.color/.borderRight`, contradicting `plan.md` §5's explicit T8 risk mitigation ("the two must not overlap — typography owns font/size/padding, the theme owns colour"). Harmless today because both sides resolve to the same design-token chain (`var(--color-primary, ...)` ≡ `var(--primary)` via the Tailwind v4 `@theme` alias block in `app.css:24-44`), so whichever theme compartment wins the CSS cascade produces the same visible color. But it is an unreviewed assumption about CodeMirror's style-module ordering that the plan itself flagged as a risk, and it is exactly the kind of thing that silently drifts the next time either theme is touched in isolation. | Remove the duplicated color properties from one side (recommend keeping them only in `appEditorTheme`, since `buildTypographyTheme`'s doc comment is "typography" — font/size/padding — not color) so the two themes truly don't overlap, as the plan intended. | 0 |
| QA-03 | Low | MINOR | `resources/js/lib/editor/codemirrorCommands.ts:315-355` (`clearFormatting`), Deviation 5 in `implementation.md` | `clearFormatting` strips only the outermost line prefix per click (e.g. `> ## Quoted` → `## Quoted`, not `Quoted`), documented as intentional (matches `clearLinePrefix`'s single-pass convention). This is a reasonable reading of FR-06's acceptance text, but a user clicking "Clear formatting" on a blockquoted heading and getting `## Quoted` back (still a heading) may reasonably expect full unwrapping in one click, since the button's whole purpose is "remove all formatting." | Confirm with the user during the manual walkthrough (item 6) whether single-pass is acceptable; if not, change `clearLinePrefix`'s single match to a `while` loop over `LINE_PREFIX_PATTERN` in both `clearLinePrefix` and `clearFormatting`, consistently. | 0 |
| QA-04 | Low | MINOR | `implementation.md` — Deviations §4 vs. `plan.md` §5 Risk 3 | The plan's risk mitigation for T5 explicitly requires implementation.md to "list the bindings it overrides" (the new `Prec.high` formatting keymap could shadow a `defaultKeymap`/`markdown()` binding, e.g. `Mod-k` on some platforms). `implementation.md` §4 Deviation 3 discusses *how* `Mod-k` is wired but never lists which existing bindings, if any, the 13 new shortcuts collide with. Partially mitigated by `implementation.md` §6 walkthrough items 9 and 11 (which do ask the user to manually check `Ctrl+Z`/`Ctrl+Shift+Z` and every advertised shortcut), but the explicit documentation step the plan asked for is missing. | Add a short list to `implementation.md` of any `defaultKeymap`/`historyKeymap`/`markdown()` bindings the new keymap's 13 entries shadow (even if the answer is "none found by inspection"). | 0 |
| QA-05 | Low (informational) | N/A — accepted trade-off, no fix requested | `resources/js/lib/editor/codemirrorLivePreview.ts:79-102` (`computeFrontmatterEndLine`) and `resources/js/lib/editor/markdownPreview.ts:11` (`FRONTMATTER_PATTERN`) | Both the live-preview exclusion and the preview-render stripping use a bounded forward scan/regex for "frontmatter," not real YAML-block detection. A note that opens with `---` and contains any other bare `---`/`...` line within the scan bound — even an unrelated body horizontal rule — will have everything between the two treated as frontmatter and suppressed/stripped. This is the exact alternative requirements.md's NFR explicitly permits ("a single forward fence/frontmatter scan") and is applied consistently by both consumers, so preview and live-decoration never disagree with each other. Not a regression; flagging only so it's a known, accepted limitation rather than a silent one. | No action required for this pass. Worth a note in the Master Plan or a future ADR if real frontmatter parsing is ever added. | — |

**No Critical or High issues found.** All Medium/Low issues above are routed to the Developer (MINOR classification — localized, obvious fixes within the existing design); none require a replan.

---

## 4. Code Review Notes

- **Security & Authorization**: No authorization surface touched (confirmed — no controller/policy/route file in the diff). FR-09/FR-20's XSS-adjacent fixes were traced end-to-end: `isAllowedHref` (`linkProtocols.ts:15-27`) is a single shared allowlist used by both `LinkDialog.vue`'s CodeMirror branch and `markdownPreview.ts`'s link **and** image renderers (Deviation 6 — reasonable, closes an XSS path the requirement didn't explicitly name but which has the identical unescaped-interpolation shape). `escapeAttribute` (`markdownPreview.ts:31-37`) correctly escapes `&"<>` before interpolation into the `v-html`-bound string. Verified against the exact FR-20 attack string in `markdownPreview.test.ts:62-75` and it passes. No document content is logged anywhere in the diff (Master Plan non-negotiable #11) — confirmed by `grep`-level review of the changed files.
- **Validation & Data Integrity**: FR-17b (`EditorState.readOnly.of(!isEditable)`) closes the "checkbox click writes to a frozen file" gap described in requirements.md, verified by code read in `codemirror.ts:138` and the `SourceEditor.vue:141-157` reconfigure watcher, and the `TaskCheckboxWidget`'s own `view.state.readOnly` live-check (`codemirrorLivePreview.ts:58-60`) as a second line of defense even if the widget's captured `readOnly` flag is stale. **Gap**: no automated test asserts this (QA-01). Markdown files remain the source of truth throughout; no change to `NoteService`/`createNoteSaver`/hashing.
- **Performance**: FR-12's dedupe is O(visible lines), not O(document) — confirmed by code read of `markdownLineDecorations`'s single pass over `ranges`. FR-11's frontmatter scan is explicitly bounded to 1000 lines (`FRONTMATTER_SCAN_LIMIT`). FR-21's debounce correctly decouples the per-keystroke `text` ref from the 200ms-debounced `renderedHtml` computed.
- **Conventions (AGENTS.md, `.ai/rules/`)**: Vue components all retain a single root element (checked `SourceEditor.vue`, `EditorToolbar.vue`, `LinkDialog.vue`). No Wayfinder-relevant change (no backend route touched). `vendor/bin/pint` correctly skipped — no PHP file in the diff, confirmed by `git status`.
- **Frontend (Inertia/Vue/Wayfinder)**: `EditorToolbar.vue`'s `defineExpose({ openLinkDialog })` + `SourceEditor.vue`'s template ref (Deviation 3) is a standard, idiomatic Vue 3 pattern for a child-to-parent-to-child callback relay; functionally equivalent to the plan's emit-based sketch and not a scope or architecture deviation.
- **TipTap branch integrity**: Explicitly re-verified per this QA round's instructions. `git diff tests/js/editor/toolbarCommands.test.ts` shows **0 deletions, 88 insertions** — the original TipTap-path `describe` block (lines 1-98) is byte-for-byte unchanged. `EditorToolbar.vue` and `LinkDialog.vue`'s TipTap branches (`isTipTap(editor) ? ... : ...`) are untouched logic paths; only the CodeMirror branch and the shared `currentHref()`/typed-prop plumbing changed. `npm run test:js` confirms all original TipTap assertions still pass.

---

## 5. Routing Recommendation
- [x] **PASS (automated scope)** — zero open Critical/High issues; all four non-DOM test commands in `plan.md` §4 are green; backend regression (`tests/Feature/Notes/`) is unaffected, confirming no scope violation.
- [ ] **Do not move to `.ai/features/completed/` yet.** Per this feature's own stated goal and `plan.md` §4's explicit QA instruction ("Do not report PASS on static checks alone"), the mandatory next step is the human manual browser walkthrough in `implementation.md` §6 (items 1–28, via `composer run dev`). This is the step both prior CodeMirror passes skipped, and it is the only way to verify FR-01, FR-08 (dialog UI), FR-10, FR-13/14/15/18 (rendered CSS), FR-17a (checkbox DOM behavior) and FR-21 (perceived latency).
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: **QA-01** (add the three missing `codemirror.test.ts` assertions — recommend doing this before/alongside the manual walkthrough, since it's cheap and closes a real regression-coverage gap on a data-integrity-relevant fix), **QA-02** (dedupe overlapping theme/typography CSS properties), **QA-04** (document any keymap overrides in `implementation.md`). **QA-03** needs a one-line product decision from the user (full vs. single-pass prefix unwrap in Clear Formatting) before the Developer touches it — surface this during/after the manual walkthrough rather than guessing.
- [ ] **FAIL — MAJOR** → None. No issue requires a replan; the plan's design holds.
