<!-- path: .ai/features/active/remove-tiptap-rich-mode/implementation.md -->
# Implementation: Retire TipTap and the Rich/Source Editor Bifurcation

## Metadata
- **Feature Name**: Retire TipTap and the Rich/Source Editor Bifurcation
- **Feature ID**: feat-retire-tiptap
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA — automated verification complete; T13's manual browser walkthrough (below) is a mandatory outstanding user-verification item per the plan's §4 "Verification honesty" note. Do not move this folder to `.ai/features/completed/` until the user confirms the walkthrough.

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Fix `insertTable`, add `table` toolbar command | DONE | Rewritten on `insertHorizontalRule`'s pattern: inserts after the line containing the selection end, never touches the current line, never deletes a selection. 4 new test cases added. |
| T2 — Collapse `toolbarCommands.ts` to a single target | DONE | All 17 `isTipTap(editor) ? … : …` ternaries removed; `table` added as the 18th entry. `executeUndo`/`executeRedo` widened from `EditorView` to `CommandTarget` — **`vue-tsc` accepted it with zero errors**, no fallback needed (see §4 Deviations for the one adjustment that was still necessary). |
| T3 — Rework `toolbarCommands.test.ts` | DONE | TipTap `describe` block (98 lines) deleted; the "no link command in the table" test moved into the surviving suite; `table` row added to `expectedCodeMirrorMarkdown`. All 17 pre-existing expected strings unchanged. |
| T4 — Single code path in `EditorToolbar.vue`/`LinkDialog.vue` | DONE | `editor` prop, `activeEditor` computed, `useEditorTick` call, `isTipTap` branches all removed. Table button added (icon button at `md+`, overflow menu below). `isAllowedHref` gate in `LinkDialog.apply()` preserved unchanged. |
| T5 — Collapse `NoteEditor.vue` | DONE | Every deletion/collapse/keep enumerated in the plan was followed line-by-line. `TiptapEditor`/`FrontmatterPanel` imports, `Mode` type, `mode` ref, `switchMode()`, `assessment`/`assessing`/`reformatAccepted`, `isLargeNote`, `writtenSinceMount` all removed. Save/conflict/copy/compare/freeze/external-change logic kept byte-identical for the source path. `sourceRef`→`editorRef`, `sourceText`→`noteText` renamed per plan. |
| T6 — Delete TipTap modules/components | DONE | 9 files + the empty `resources/js/lib/markdown/` directory deleted one at a time, `npm run types:check` run after each — every error after each deletion was confined to files already slated for T7 deletion. Final grep confirms clean. |
| T7 — Delete TipTap test corpus, re-home 2 behaviours | DONE | 6 test files + 28 fixtures + 2 empty directories deleted. `indent_size`→`indentUnit` facet (2-space and 4-space) and the `Tab` keymap-binding assertion added to `codemirror.test.ts`, replacing `tabIndent.test.ts`. The "no link command" test was already re-homed in T3. |
| T8 — Engine-neutral preview CSS | DONE | `.tiptap-content`→`.markdown-content` (31 selectors renamed). Deleted: the `min-h-48 outline-none` base rule, all 9 `ul[data-type='taskList']` rules (TipTap DOM only — `marked`'s checkbox output is already styled by `SourceEditor.vue`'s own `<style>` block), and `.tiptap-nowrap .tiptap-content`. Heading em-sizes and every other rule kept verbatim. `grep -rin tiptap resources/` returns nothing. |
| T9 — Remove 11 unused dependencies | DONE | 9 `@tiptap/*` + `@codemirror/theme-one-dark` + `codemirror` removed from `package.json`; `npm install` regenerated the lockfile (52 packages removed from `node_modules`, including transitive deps), rebasing onto the already-uncommitted `package.json`/`package-lock.json` changes rather than discarding them. Bundle size recorded below (§Bundle Size). |
| T10 — `noTiptapDependency.test.ts` regression guard | DONE | New file: checks `package.json` for `@tiptap/*`/`codemirror`/`@codemirror/theme-one-dark`, and walks `resources/js/**/*.{ts,vue}` + `resources/css/app.css` for `@tiptap`/`isTipTap`/`useEditorTick`/`tiptap-`. 3 tests, all passing. |
| T11 — Rename `SourceEditor.vue` → `MarkdownEditor.vue` | DONE | `git mv`, history preserved (`git status` shows `RM`). Single import + `InstanceType` ref type updated in `NoteEditor.vue`. Two template comments ("Unified EditorToolbar supporting CodeMirror" → "Formatting toolbar"; "CodeMirror 6 Editor Pane" → "Editor Pane") de-named per plan; the script-side CodeMirror-specific comments (which are technically accurate, e.g. about `EditorView`/`onUpdate`) were left as-is. |
| T12 — Make the documents true | DONE | `docs/Masterplan.md` §20/§21/§53 retitled and amended with a dated ADR-reference note each; toolbar lists and acceptance criteria kept verbatim. The explicitly-named summary mentions (`:11`, `:77/:91/:107/:137`, `:2001/:2041/:2321/:2455/:2539`) updated. Mentions **not** named by the plan (`:1157`, `:1433/:1434`, `:1638`, `:1661`, `:1688`) deliberately left untouched — out of the plan's stated T12 scope and outside FR-13's acceptance criterion, which is scoped to §20/§21/§53. `markdown-conversion-and-fidelity.md` → Status `Superseded`. `retire-tiptap-rich-mode.md` → Status `Accepted` (all approvals are now in). |
| T13 — Verification and manual walkthrough | DONE (automated) / **PENDING** (manual) | All automated commands pass — see §3. The manual browser walkthrough is written below for the user to run; `vite.config.ts` pins `test.environment: 'node'`, so nothing here can mount a DOM or an `EditorView`. |

---

## 2. Files Changed

### Deleted
| Path |
|---|
| `resources/js/components/editor/TiptapEditor.vue` |
| `resources/js/components/editor/FrontmatterPanel.vue` |
| `resources/js/lib/markdown/extensions.ts` |
| `resources/js/lib/markdown/converter.ts` |
| `resources/js/lib/markdown/assess.ts` |
| `resources/js/lib/editor/richContent.ts` |
| `resources/js/lib/editor/editorSession.ts` |
| `resources/js/lib/editor/useEditorTick.ts` |
| `resources/js/lib/editor/largeNote.ts` |
| `resources/js/lib/markdown/` (now-empty directory, removed) |
| `tests/js/markdown/spike.test.ts` |
| `tests/js/markdown/assess.test.ts` |
| `tests/js/markdown/roundTrip.test.ts` |
| `tests/js/editor/richContent.test.ts` |
| `tests/js/editor/tabIndent.test.ts` |
| `tests/js/editor/largeNoteThreshold.test.ts` |
| `tests/js/fixtures/markdown/**` (28 files) |
| `tests/js/markdown/`, `tests/js/fixtures/` (now-empty directories, removed) |

### Renamed (`git mv`, history preserved)
| From | To |
|---|---|
| `resources/js/components/editor/SourceEditor.vue` | `resources/js/components/editor/MarkdownEditor.vue` |

### Modified
| Path | Summary |
|---|---|
| `resources/js/lib/editor/codemirrorCommands.ts` | Fixed `insertTable` (insert-after-line, never deletes selection); widened `executeUndo`/`executeRedo` from `EditorView` to `CommandTarget`. |
| `resources/js/lib/editor/toolbarCommands.ts` | Collapsed to a single CodeMirror-only `CommandTarget` path; `isTipTap`/`SupportedEditor` deleted; `table` command added. |
| `resources/js/components/editor/EditorToolbar.vue` | `editor` prop, `activeEditor`, `useEditorTick`/`tick` removed; `view` + `revision` are the sole input surface; Table button added (icon + overflow menu). |
| `resources/js/components/editor/LinkDialog.vue` | `editor` prop, `Editor` type import, `isTipTap` branches removed; `view` is the sole input. |
| `resources/js/components/editor/NoteEditor.vue` | Collapsed to one editor: no mode state, single-branch save/copy/compare/conflict/external-change logic (see T5 details). |
| `resources/js/components/editor/MarkdownEditor.vue` (formerly `SourceEditor.vue`) | Import/rename only; two template comments de-named. |
| `resources/css/app.css` | `.tiptap-content`→`.markdown-content`; 3 TipTap/contenteditable-only rule families deleted. |
| `resources/js/types/notes.ts` | `body`/`frontmatter`/`frontmatter_yaml` commented as server-side-only (pending the deferred Q7 follow-up). |
| `package.json` / `package-lock.json` | 11 dependencies removed (9 `@tiptap/*`, `@codemirror/theme-one-dark`, `codemirror`); lockfile regenerated via `npm install`. |
| `tests/js/editor/toolbarCommands.test.ts` | TipTap suite deleted; CodeMirror suite kept, extended with `table`, and renamed to the sole `describe('toolbarCommands')`. |
| `tests/js/editor/codemirrorCommands.test.ts` | `insertTable` test rewritten into 4 cases (bullet line/no selection, multi-line selection preserved, end-of-document, resulting selection). |
| `tests/js/editor/codemirror.test.ts` | Added `indentUnit` facet (2-space/4-space) + `Tab` keymap-binding test, replacing `tabIndent.test.ts`'s one non-TipTap-specific assertion. |
| `docs/Masterplan.md` | §20/§21/§53 retitled and amended (dated ADR note each, toolbar/acceptance text verbatim); summary mentions at the plan-specified line numbers updated. |
| `.ai/decisions/markdown-conversion-and-fidelity.md` | Status → Superseded. |
| `.ai/decisions/retire-tiptap-rich-mode.md` | Status → Accepted (all approvals now recorded). |

### New
| Path | Summary |
|---|---|
| `tests/js/editor/noTiptapDependency.test.ts` | Regression guard: no `@tiptap/*`/`codemirror`/`@codemirror/theme-one-dark` dependency; no `@tiptap`/`isTipTap`/`useEditorTick`/`tiptap-` string anywhere under `resources/js` or `app.css`. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `npx vitest run tests/js/editor/codemirrorCommands.test.ts` (after T1) | 40 passed |
| `npm run types:check` (after T2) | 0 errors — the `CommandTarget` widening for `executeUndo`/`executeRedo` compiled cleanly on the first attempt; the plan's fallback (revert to `EditorView`, keep test `as any` casts) was **not needed**. |
| `npx vitest run tests/js/editor/toolbarCommands.test.ts` (after T3) | 20 passed, including all 17 pre-existing expected-string assertions unchanged |
| `npm run types:check` (after T4) | 0 errors |
| `npm run types:check` (after T5) | 0 errors |
| `npm run types:check` × 8 (once per file, during T6) | Each run's errors confined to files already slated for T7 deletion; 0 unexpected errors |
| `grep -rn "@tiptap\|isTipTap\|useEditorTick\|assessMarkdown\|richContent\|editorSession\|largeNote\|FrontmatterPanel\|TiptapEditor" resources/js` (T6) | No matches |
| `npm run types:check` (after T7 deletions) | 0 errors |
| `npx vitest run tests/js/editor/codemirror.test.ts` (T7) | 8 passed |
| `grep -rin tiptap resources/` (T8) | No matches |
| `npm run types:check` (after T9) | 0 errors |
| `npm run build` × 2 (before/after `npm install`) | Both succeed; **byte-identical output** — same filenames, same hashes, same sizes (see §Bundle Size) |
| `npx vitest run tests/js/editor/noTiptapDependency.test.ts` (T10) | 3 passed |
| `npm run types:check` (after T11 rename) | 0 errors |
| `npm run check:fix` | 0 errors, 1 pre-existing unrelated warning (see below) |
| `npm run test:js` (T13, full suite) | **183 passed across 14 files** |
| `npm run types:check` (T13) | 0 errors |
| `npm run check` (T13) | 0 errors; 1 pre-existing warning: `tests/js/editor/codemirror.test.ts:43` `no-unused-vars` on `changedText` in a test this feature did not write (it is part of the pre-existing "builds extensions and initializes an EditorState cleanly" test, untouched by T7's edit to the same file) |
| `npm run build` (T13, final) | Succeeds; output byte-identical to the T9 measurements |
| `git diff --name-only -- '*.php'` (T13) | **Empty — no PHP file changed**, confirming no scope violation |
| `php artisan test --compact tests/Feature/Notes/` (T13) | First run: 2 failures, both `SQLSTATE[HY000]: database is locked` (SQLite file-lock contention under parallel test execution — a test-infra flake, unrelated to this feature). Final clean run: **54 passed, 243 assertions**. |
| `php artisan test --compact tests/Feature/Services/ tests/Feature/Vaults/` (supplementary, beyond T13's specified `tests/Feature/Notes/`) | First full run surfaced 1 flaky `database is locked` error (same SQLite contention pattern) **and** 1 genuine-looking failure: `EncryptionServiceTest::a_key_file_round_trips_through_encode_and_parse` — "Failed asserting that 3 is identical to 1" on `$header->opslimit`. Investigated (see §4 Deviations) and traced to a **stale, gitignored `bootstrap/cache/config.php`** predating this session, which baked in the production KDF opslimit (3 = `SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE`) and silently overrode `phpunit.xml`'s test-env `MDVAULT_KDF_OPSLIMIT=1`. `php artisan config:clear` (clears a build artifact only, no source file touched) resolved it; the test then passed in isolation. Final clean run: **708 passed, 2948 assertions, 13 skipped (pre-existing)**. |

**Verification honesty** (per requirements.md §5 and plan.md §4): `vite.config.ts:45-48` pins `test.environment: 'node'`. No test in this repository can mount a Vue component or a real `EditorView` in a browser DOM. Everything above proves the code compiles, the pure-logic unit tests pass, the build succeeds, and the untouched backend is unharmed. **None of it proves the UI actually works for a human.** The walkthrough in §6 is not optional polish — it is the only check that can catch a broken toolbar, a CSS regression, or a save-flow defect in what is now MDVault's only editor.

---

## Bundle Size (FR-09 / NFR Performance)

| Measurement | Before `npm install` (deps still physically present; source already TipTap-free since T6) | After `npm install` (11 packages removed, lockfile regenerated) |
|---|---|---|
| `public/build` total | 1.6 MB | 1.6 MB (identical) |
| JS assets (sum) | 1.3 MB | 1.3 MB (identical — same file hashes) |
| CSS assets (sum) | 148 KB | 148 KB (identical) |
| Largest chunk (`Workspace-*.js`) | 629.63 kB / gzip 212.80 kB | 629.63 kB / gzip 212.79 kB |
| `node_modules` removed | — | 52 packages (`npm install` output: "removed 52 packages") |
| Disk freed (`@tiptap` + `codemirror` + `@codemirror/theme-one-dark`) | — | ~9.0 MB (`@tiptap` 8.9 MB, `codemirror` 45 KB, `@codemirror/theme-one-dark` 45 KB) |

**Why the shipped JS bundle did not shrink at T9**: by the time T9 ran, T6 had already deleted every file that imported `@tiptap/*`, `codemirror`, or `@codemirror/theme-one-dark`. Vite's tree-shaking had therefore already eliminated that code from the bundle the moment the imports disappeared — the two builds bracketing `npm install` are from the same (already TipTap-free) source graph and are correctly byte-identical. The real, measurable effect of removing the dependencies is on the **install footprint**: 52 fewer packages in `node_modules`, ~9 MB less on disk, and a smaller `package-lock.json` attack surface — not a further reduction in what ships to the browser. The actual runtime bundle reduction happened in T6, when the dead TipTap code paths were deleted from the source tree; it was not separately re-measured against a pre-T6 baseline because doing so would have required rebuilding from a stashed snapshot of ~140 other files with pre-existing uncommitted changes from unrelated prior sessions, which was judged too risky to the user's existing uncommitted work for a non-functional metric.

---

## 4. Deviations from Plan
- **T2's `CommandTarget` widening required one adjustment vs. the plan's literal text, but used no `any`.** The plan's draft code sketch for `executeUndo`/`executeRedo` was:
  ```ts
  export function executeUndo(view: EditorView): boolean { return undo(view); }
  ```
  widened naively to `CommandTarget`. This compiles and type-checks with **zero** errors from `vue-tsc` exactly as written — `@codemirror/commands`' `undo`/`redo` are `StateCommand`s (`(target: {state, dispatch}) => boolean`), and `CommandTarget`'s `dispatch(...specs: any[])` is structurally compatible. No cast, no `any`, no fallback to `EditorView` was needed. This is **not** a deviation in outcome — the plan's explicit "if it fights `vue-tsc`, revert" instruction simply never triggered — but it is recorded here because the plan predicted friction that did not materialize.
- **Bundle size "before this feature" was not independently re-measured.** See §Bundle Size above for the reasoning: the working tree had ~140 pre-existing uncommitted modifications from unrelated prior sessions at the start of this task (confirmed via `git status`), making a `git stash`-based reconstruction of a true pre-feature build too risky relative to the value of a non-functional metric. The two builds that *were* measured (bracketing the actual `npm install` step) are reported honestly as byte-identical, with the reason given rather than a fabricated delta.
- **`resources/js/types/notes.ts` was touched even though it isn't listed in plan.md's §3 Implementation Tasks (T1–T13).** It appears only in the plan's §2 Architecture "Frontend Components" table ("Comment `body`/`frontmatter`/`frontmatter_yaml` as server-side-only, pending Q7"). Treated as a low-risk, explicitly-planned-for addition and folded into T5 (the task that touches `NoteEditor.vue`'s consumption of those same fields). No logic changed — doc comment only.
- **A pre-existing stale config cache was cleared during verification (no source file touched).** `bootstrap/cache/config.php` (gitignored, `git check-ignore` confirms) predated this session and was baking in a production KDF `opslimit` value, causing `EncryptionServiceTest::a_key_file_round_trips_through_encode_and_parse` to fail against the broader `tests/Feature/Services/` regression run with no relation to this feature's changes (zero PHP files were touched — `git diff --name-only -- '*.php'` is empty throughout). `php artisan config:clear` fixed it; this is a cache-clearing operation, not a code or config-source change, and was necessary to get an honest read of the regression suite rather than a false red flag. Flagging it here rather than silently working around it.
- **No other deviations.** All other tasks were implemented exactly as specified, in the order given.

---

## 5. Notes for QA
- **The P1/Q2–Q6 gate is satisfied per plan.md's metadata** — this was confirmed before implementation started, not re-litigated here.
- **Regression scope check**: `git diff --name-only -- '*.php'` is empty; no `app/`, `database/`, or other backend path appears in the diff. `tests/Feature/Notes/` (54/54) and the broader `tests/Feature/Services/`/`tests/Feature/Vaults/` (708/708, 13 pre-existing skips) all pass cleanly, after two unrelated issues were isolated and ruled out: an SQLite file-lock flake under parallel execution (reproducible only under parallel runs, not when the affected file/directory is re-run alone), and a stale `bootstrap/cache/config.php` predating this session that was silently overriding `phpunit.xml`'s test KDF settings (fixed with `php artisan config:clear`, a cache-only operation). **If QA's own run hits the same encryption-test failure, run `php artisan config:clear` first** — it is not a code defect.
- **The `codemirrorCommands.ts`/`codemirrorCommands.test.ts`/`codemirror.test.ts` files are untracked in git** (they were created by the earlier, separately-completed `codemirror-unified-editor`/`codemirror-ui-fixes` passes and never committed). This feature *edited* them further (T1, T7) rather than creating them; `git status` shows them as `??` rather than `M` because they were never added. Worth knowing when reviewing the diff — `git diff` against HEAD will not show their prior content, only `git status`/the file contents as they stand now.
- **Pre-existing lint warning**: `tests/js/editor/codemirror.test.ts:43` (`changedText` unused in an unrelated pre-existing test). Not touched by this feature; flagged per the plan's "report, do not fix, unrelated pre-existing issues" instruction.
- **`npm audit` reports 5 critical vulnerabilities** after `npm install`. Not investigated or fixed — out of this feature's scope (dependency security triage), and not something T9 introduced (it is a function of the remaining dependency tree, which T9 only shrank). Flagging for the user/orchestrator to decide whether a separate task is warranted.
- **What most needs a close human look**: everything FR-02/FR-12/FR-11 claim and nothing here can verify — the Table button's actual click behaviour, the Link dialog round trip, keyboard shortcuts (especially `Ctrl+B` not fighting the sidebar), and the CSS rename's visual fidelity in both themes at both widths. All of that is itemised in the walkthrough below.

---

## 6. Manual UI Walkthrough (required before this feature can be marked complete)

`vite.config.ts` pins `test.environment: 'node'` — nothing in the automated suite can mount a component or a browser `EditorView`. Please run through all ten sections below in the actual application (`npm run dev` or `composer run dev`, then open the app) before this feature moves to `.ai/features/completed/`.

1. **Open a plain note.** Confirm: one editor, no Markdown/Rich-text toggle anywhere in the header, and the Code / Split / Preview switcher is present in the editor's own toolbar.
2. **Open a note with YAML frontmatter.** The leading `---`…`---` block should be visible as plain text at the top of the editor, not decorated as a heading or horizontal rule. Switch to Preview or Split — the frontmatter block should **not** appear as rendered body content. Edit a line inside the frontmatter block, wait for autosave (or press Ctrl+S), then reopen the note — the bytes you typed should be exactly what's there.
3. **Open five notes that each used to be blocked from Rich mode**: one with an **image**, one with a **raw HTML block**, one with a **reference-style link** (`[text][ref]` + a `[ref]: url` definition), one with a **footnote** (`[^1]`), and one with a `[[wiki link]]`. Each should open normally — no banner, no consent dialog, no "can't preserve yet" message.
4. **Open a note over 150 KB.** It should open instantly with no "open in Markdown mode for optimal performance" banner (that banner no longer exists), and the footer should show the file's actual size.
5. **Open a note over 1 MB, and a non-UTF-8 note.** The existing read-only alerts ("larger than 1 MB" / "isn't valid UTF-8") should be unchanged.
6. **Exercise every toolbar button**, including the new **Table** button:
   - On a bullet line (`- item`) with the caret at the end and **nothing selected**, click Table — a GFM table skeleton should appear on its own lines below, `- item` should be untouched, and the caret/selection should land inside "Header 1" so you can type over it.
   - **Select some text**, then click Table — your selected text must **not** be deleted; the table is inserted after it.
   - Check Undo/Redo, Bold/Italic/Strike/Code, H1–H3, Bullet/Ordered/Task lists, Blockquote, Code block, Horizontal rule, Clear formatting, and every advertised keyboard shortcut.
   - Open the Link dialog (Ctrl+K or the toolbar button): prefill on an existing link, edit it, remove it, and confirm a `javascript:` URL is rejected with an error message rather than applied.
   - Confirm the Table button appears in the overflow ("More formatting") menu at narrow/mobile widths, alongside the other secondary commands.
7. **`Ctrl+B` inside the editor** should apply bold and must **not** toggle the app sidebar. **`Ctrl+B` outside the editor** (e.g. with focus elsewhere on the page) should still toggle the sidebar as before.
8. **Preview and Split views, in both light and dark mode, at desktop width and at ~400px width**: headings (all 6 levels), links, bold, strikethrough, lists, **task-list checkboxes**, blockquotes, inline code, fenced code blocks, horizontal rules, **images**, and **tables** should all render with the same styling as before the `.tiptap-content` → `.markdown-content` rename. In Split view, the two panes should sit side-by-side without overlapping at any width.
9. **Save-flow regression check**: watch the autosave status badge change through its states; press Ctrl+S and click the Save button; trigger a conflict by editing the note's file in another editor while it's open in MDVault, then type — walk through Reload, Overwrite, Copy-to-clipboard, Save-as-new-note, and Compare from the conflict banner; trigger a guarded navigation with unsaved changes (the "unsaved changes" dialog should appear); and delete a note's file on disk while it's open (the "missing" handling should still work).
10. **Create a new note with the frontmatter-template setting enabled** (Settings → Editor). The rendered template should appear as plain text at the top of the one editor, exactly as the setting describes it.

If anything in this list looks or behaves differently from before — especially items 6–9 — please flag it before this feature is considered done; there is now no TipTap fallback for a human to drop back to if something in the CodeMirror surface is broken.

---

## Fix Round 1 (QA-01)

**Issue**: QA round 1 flagged `docs/Masterplan.md:1638` — the §48 "Development Phases" summary table still read "Phase 4 → Tiptap Editor", contradicting §53's retitled "Phase 4 — Markdown Editor" (amended in T12). QA also recommended fixing the same stale reference at `docs/Masterplan.md:1157` (§33 Encryption Flow "Saving" diagram), which named a "Tiptap" pipeline step that no longer exists.

**Fix**:
- `docs/Masterplan.md:1638` — `Phase 4 → Tiptap Editor` → `Phase 4 → Markdown Editor`, matching the retitling already applied at §53 and the summary mentions T12 updated.
- `docs/Masterplan.md:1157` — the §33 "Saving" flow's first step, `Tiptap`, renamed to `CodeMirror` (the diagram now reads `CodeMirror → Markdown → Encrypt → Write encrypted file`, consistent with the "Opening" diagram immediately above it and with the single-editor architecture everywhere else in the document).

**Left untouched (QA confirmed defensible)**:
- `:1433`/`:1434` — `MarkdownService` responsibilities (`Markdown → Tiptap representation` / `Tiptap representation → Markdown`): kept because `MarkdownService::composeRich()` is deliberately retained per Q7's backend deferral.
- `:1661`/`:1688` — Phase 0's historical setup list and acceptance criterion: kept as a historical record of what Phase 0 actually delivered at the time.
- `:702`/`:1805` — the existing dated ADR amendment notes from T12: these correctly name "Tiptap" to document its retirement and were not touched.

**Verification**: `grep -n -i tiptap docs/Masterplan.md` now returns only `:702`, `:1433`, `:1434`, `:1661`, `:1688`, `:1805` — the six defensible/expected mentions. No code or test files were touched; no PHP, Vue, or TypeScript changed. This was a documentation-only, two-line text fix.
