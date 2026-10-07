# QA Report: Retire TipTap and the Rich/Source Editor Bifurcation

## Metadata
- **Feature ID**: feat-retire-tiptap
- **QA Round**: 1
- **Reviewer**: Senior QA Engineer
- **Plan Revision Audited**: Revision 1
- **Date**: 2026-10-07

## Verdict: **PASS (automated scope) — with mandatory outstanding manual verification**

Zero open Critical or High issues. One MODERATE documentation issue found (not blocking automated PASS criteria, but should be fixed). **This verdict does not authorize moving the feature folder to `.ai/features/completed/`.** Per `requirements.md` §5 "Verification honesty" and `plan.md` §4, `vite.config.ts` pins `test.environment: 'node'`, so nothing in this codebase's automated suite can mount a Vue component or a real `EditorView` in a browser DOM. This feature deletes MDVault's only fallback editor (TipTap) — after this change, if any part of the CodeMirror surface is broken, there is no other editor for a user to fall back to. The `implementation.md` §6 manual walkthrough (10 numbered sections, re-running the previous pass's §A–§C plus five new checks) is a **mandatory outstanding user-verification item**, not optional polish. Do not move this folder to `.ai/features/completed/` until a human confirms it in a real browser. This is materially higher-risk than the `codemirror-ui-fixes` pass, which at least left TipTap as a working fallback.

---

## 1. Requirements Coverage

| FR | Verified | Method | Result |
|---|---|---|---|
| FR-01 Single editor, mounted unconditionally | Yes | Read `NoteEditor.vue` in full; grepped for `Mode`/`mode`/`switchMode` | No mode type, no mode ref, no segmented control. `MarkdownEditor` is the only child, rendered unconditionally in the `note.editable` branch. |
| FR-02 No fidelity assessment / consent flow | Yes | Grep + read `NoteEditor.vue` template | `assessMarkdown`, `assessing`, `assessment`, `reformatAccepted`, the "Checking formatting…" line and the reformat-consent alert are all absent. |
| FR-03 Frontmatter as document text | Yes | `FrontmatterPanel.vue` confirmed deleted; `codemirrorLivePreview.test.ts`/`markdownPreview.test.ts` pass unchanged | No separate frontmatter UI; live-preview/strip behaviour unchanged (8 tests pass). |
| FR-04 Verbatim source save | Yes | Read `send()`/`saveAsNewNote()` in `NoteEditor.vue` | Payload is always `{content, base_hash, mode: 'source'}` / `{content, mode: 'source', source_path}`; no `has_frontmatter`/`frontmatter` keys. |
| FR-05 No per-note mode memory, no migration | Yes | `resources/js/lib/editor/editorSession.ts` confirmed deleted; grep clean; `git diff --stat`/`git status` on `database/migrations/` empty | No migration anywhere in the diff. |
| FR-06 Large-note banner/threshold removed | Yes | `largeNote.ts` + `largeNoteThreshold.test.ts` confirmed deleted; `too_large` backend alert unchanged in `NoteEditor.vue:524-531` | Footer still shows file size (`NoteEditor.vue:586-602`). |
| FR-07 All 9 TipTap-coupled modules deleted | Yes | Direct filesystem check (`ls`) on all 9 paths + `resources/js/lib/markdown/` | All absent. `tests/js/markdown/`, `tests/js/fixtures/` also absent. |
| FR-08 One code path per shared module | Yes | Read `toolbarCommands.ts`, `EditorToolbar.vue`, `LinkDialog.vue` in full; grep for `@tiptap\|isTipTap\|useEditorTick` | Zero matches anywhere in `resources/`. No `editor` prop on `EditorToolbar`/`LinkDialog`; `view`+`revision` only. |
| FR-09 11 dependencies removed | Yes | Read `package.json`; ran `npm install` (no-op, "up to date") | No `@tiptap/*`, `codemirror`, or `@codemirror/theme-one-dark`. `marked`/`diff`/`@vueuse/core` retained. Lockfile genuinely in sync (not hand-edited). |
| FR-10 No TipTap identifiers in CSS | Yes | `grep -rin tiptap resources/` | Zero matches. `.markdown-content` selectors confirmed in `app.css`; `MarkdownEditor.vue:317` uses `markdown-preview-content markdown-content`. |
| FR-11 Table button, selection-safe `insertTable` | Yes | Read `codemirrorCommands.ts`'s `insertTable`; read its 4 new test cases; ran them | Inserts after the line, never touches current line, never deletes a selection (multi-line-selection test explicitly asserts the selected text survives), selects "Header 1". Wired into `EditorToolbar.vue` both as an icon button and in the overflow menu. |
| FR-12 No save/conflict/copy/compare regression | Yes | Read `NoteEditor.vue` end-to-end; diffed logically against plan.md T5's enumerated keep/delete list; ran `noteSaver`/`saveTransport`/`copyTransport`/`visitSafety`/`external/*` (95 tests) and `tests/Feature/Notes/` (54 tests, 243 assertions) | All pass. Source-mode logic for `send`, `saveAsNewNote`, `conflictCopy`, `openCompare`, `readContent`, `applyExternalStatus`, `freeze`/`unfreeze` reads as untouched except for the rich branch's removal. See §4 caveat on git-history limits. |
| FR-13 Documents stop naming TipTap as the editor | **Partially** | `grep -n -i tiptap docs/Masterplan.md` | §20 and §53 headings correctly retitled ("Markdown Editor"); dated ADR-reference notes present at both. **But** `docs/Masterplan.md:1638` ("Phase 4 → Tiptap Editor" in the §48 Development Phases table) still contradicts the retitled §53 for the identical phase — see Issue QA-01 below. |

---

## 2. Issues Found

### QA-01 — MODERATE — `docs/Masterplan.md:1638` still names "Phase 4 → Tiptap Editor", contradicting the retitled §53
- **File:line**: `docs/Masterplan.md:1638` (inside `# 48. Development Phases`)
- **Detail**: §53's heading was correctly amended to "Phase 4 — Markdown Editor" (`docs/Masterplan.md:1803`) with a dated ADR note. But the phase summary table at line 1638 — describing the exact same phase — still reads `Phase 4 → Tiptap Editor`. `implementation.md` §Deviations/§Notes discloses this as a deliberate choice ("mentions not named by the plan... deliberately left untouched — out of the plan's stated T12 scope"), and it is true that `plan.md` T12's line-number list did not include 1638. However, FR-13's own acceptance criterion is broader than the three retitled headings: "a future `system-analyst` reading the Master Plan alone would not plan TipTap work." §48's phase table is exactly the kind of summary a future analyst would scan first (per `CLAUDE.md` §7, every `plan.md` must name the Master Plan phase it implements), and it directly contradicts §53 for the identical phase. This is a self-contradiction within the authoritative document, not merely an unmentioned section.
- **Also present, lower-severity, same root cause**: `docs/Masterplan.md:1157` ("Tiptap" in the §33 Encryption Flow "Saving" diagram — now describes a step that no longer exists in the pipeline) and `:1661`/`:1688` (Phase 0's historical setup list / acceptance criterion "Tiptap can render inside the application" — arguably a historical record of what Phase 0 actually delivered at the time, more defensible to leave). `:1433`/`:1434` ("Markdown → Tiptap representation" under `MarkdownService` responsibilities) is the most defensible of the five: `MarkdownService::composeRich()` is deliberately left in place per Q7, so the backend responsibility it describes is still technically real, just unreachable from the client.
- **Classification**: MODERATE (documentation accuracy, not a code defect; no functional/security/data-integrity impact; but it is the authoritative product spec per `CLAUDE.md` §7 and directly undermines FR-13's stated purpose for the one line most likely to be read).
- **Fix Attempts**: 0
- **Routing**: **Developer** — this is a one-line text correction consistent with the pattern already applied at `:702` and `:1805` (`Phase 4 → Markdown Editor`), not a design or architecture question. No replan needed. Recommend also cleaning up `:1157` for consistency while in the area; `:1433/:1434` and `:1661/:1688` can reasonably stay as-is (Q7 backend deferral / Phase 0 historical record respectively) — flag to the user but do not block on them.

No Critical or High issues found.

---

## 3. Automated Verification Performed

| Command | Result |
|---|---|
| `npm run test:js` | **183 passed (14 files)** |
| `npm run types:check` | **0 errors** |
| `npm run check` | 0 errors; 1 pre-existing unrelated warning (`tests/js/editor/codemirror.test.ts:43`, confirmed pre-existing and not touched by this feature) |
| `npm run build` | Succeeds; `Workspace-*.js` 629.63 kB / gzip 212.79 kB, matches `implementation.md`'s recorded figure |
| `npm install` | "up to date, audited 235 packages" — confirms `package-lock.json` is genuinely in sync with `package.json`, not hand-edited |
| `npm audit` | 5 critical vulns, all in `concurrently`/`vite-plus`/`oxfmt`/`tinypool` (dev tooling) — confirmed pre-existing, unrelated to the 11 packages this feature removed |
| Targeted subset: `codemirrorLivePreview.test.ts`, `markdownPreview.test.ts`, `noteSaver.test.ts`, `saveTransport.test.ts`, `copyTransport.test.ts`, `visitSafety.test.ts`, `tests/js/external/*` | **95 passed** — the explicit FR-12 regression net |
| `php artisan test --compact tests/Feature/Notes/` | **54 passed, 243 assertions** |
| `php artisan test --compact tests/Feature/Services/ tests/Feature/Vaults/` | **708 passed, 13 skipped, 0 failed, 2948 assertions** |
| `git diff --stat HEAD` / `git status` | Confirmed: **no file under `database/migrations/` or `app/` changed**; `git status --porcelain -- '*.php'` is empty |
| Direct filesystem checks | All 9 FR-07 files confirmed deleted; `resources/js/lib/markdown/`, `tests/js/markdown/`, `tests/js/fixtures/` confirmed removed |
| `grep -rn "isTipTap\|@tiptap\|useEditorTick\|SupportedEditor" resources/` | 0 matches |
| `grep -rin tiptap resources/` | 0 matches |
| `grep -n -i tiptap docs/Masterplan.md` | 5 matches outside §20/§53 (QA-01) |

## 4. Verification Limitations Disclosed to the Orchestrator/User

- **Git-history limitation on FR-12's "byte-identical" claim**: `codemirrorCommands.ts`, `codemirrorCommands.test.ts`, `codemirror.test.ts` and the CodeMirror-branch half of `toolbarCommands.test.ts` are **untracked** in git — they were produced by the two prior, uncommitted feature passes (`codemirror-unified-editor`, `codemirror-ui-fixes`) and never committed. `git diff HEAD` on `NoteEditor.vue`/`toolbarCommands.test.ts` therefore compares against a pre-CodeMirror (TipTap/textarea-era) baseline, not against the immediately-prior pass's state, so it cannot mechanically prove the "17 pre-existing expected strings are byte-identical to before this feature" claim the way a clean single-feature diff would. I substituted direct code reading (full read of `NoteEditor.vue`, `toolbarCommands.ts`, `codemirrorCommands.ts` and their tests) plus running the actual test suite (183 JS + 54 PHP Notes tests passing) as the best available substitute, and found no discrepancy. This is a repository/workflow constraint (three features' uncommitted work stacked in one working tree), not a defect in this feature's implementation — but it means QA's confidence here rests on direct review and green tests rather than a clean git diff.
- **No DOM/browser test exists anywhere in this repo** (`vite.config.ts` pins `test.environment: 'node'`), so FR-01's "CodeMirror editor is mounted directly," the Table button's actual click behaviour, the Link dialog round trip, `Ctrl+B` vs. the sidebar, and all CSS-rename visual fidelity (FR-10) are **unverified by any automated check** and rest entirely on the `implementation.md` §6 manual walkthrough.

## 5. Recommendation

- Do **not** move `.ai/features/active/remove-tiptap-rich-mode/` to `.ai/features/completed/` yet.
- Route QA-01 to the **Developer** for a quick fix (update `docs/Masterplan.md:1638`, optionally `:1157`).
- The feature's **automated scope is PASS** and the backend-isolation/no-migration/no-regression guarantees are independently confirmed.
- The **gating item before completion** is the human-run `implementation.md` §6 walkthrough (10 sections) — present that to the user explicitly and do not treat this report's PASS as sufficient on its own, per the explicit instruction in `requirements.md` §5 and `plan.md` §4.

---

**Summary**: PASS (automated) / 0 Critical / 0 High / 1 Moderate (QA-01, routed to Developer) / 0 Low open. Mandatory next step before completion: user-run manual browser walkthrough (`implementation.md` §6); this feature removes the only fallback editor and cannot be certified without it.

---

## QA Round 2 (Fix Verification for QA-01)

### Metadata
- **QA Round**: 2
- **Reviewer**: Senior QA Engineer
- **Date**: 2026-10-07
- **Scope**: Documentation-only fix to `docs/Masterplan.md:1638` and `:1157`, per round 1's QA-01.

### Verdict: QA-01 CLOSED

#### 1. Content verification
`grep -n -i tiptap docs/Masterplan.md` now returns exactly:
```
702:  (dated ADR amendment note, T12)
1433/1434: MarkdownService responsibilities — "Markdown → Tiptap representation" / "Tiptap representation → Markdown"
1661:      Phase 0 historical stack list — "Tiptap"
1688:      Phase 0 historical acceptance criterion — "Tiptap can render inside the application."
1805:      (dated ADR amendment note, T12)
```
- Line 1638 now reads `Phase 4 → Markdown Editor`, matching §53's retitled heading. The §48 phase table no longer contradicts §53 for the same phase.
- Line 1157 now reads `CodeMirror` (the "Saving" flow is `CodeMirror → Markdown → Encrypt → Write encrypted file`), consistent with the "Opening" diagram immediately above it and the rest of the document's single-editor architecture.
- The six remaining mentions are all defensible: `:702`/`:1805` are dated ADR amendment notes documenting the retirement itself (supposed to name Tiptap); `:1433`/`:1434` independently confirmed `MarkdownService::composeRich()` still exists and is still called/tested, so the documented backend responsibility is real code, not a stale reference; `:1661`/`:1688` are Phase 0's historical record of what that already-completed phase delivered at the time.

No new Tiptap mentions were introduced, and no other stale mentions were missed.

#### 2. Change-scope verification
Compared file modification timestamps across all changed/untracked paths against the round-1 `qa-report.md` write time: only `docs/Masterplan.md` and `implementation.md` have a later mtime. Every other changed file in the tree is unchanged since round 1. Confirms the fix round touched only documentation — no PHP, Vue, TypeScript, test, or migration file was touched.

#### 3. Test re-run
Not required and not performed — documentation-only change with no code impact. Round 1's automated verification remains valid.

### Round 2 Issue Log
| ID | Status | Fix Attempts | Notes |
|---|---|---|---|
| QA-01 | **CLOSED** | 1 | Fixed within existing design (plain text correction). No escalation; well under the 3-attempt circuit breaker. |

No new issues found in round 2.

### Round 2 Recommendation
- QA-01 is closed. The feature's automated/documentation scope is now fully PASS with 0 open Critical/High/Moderate/Low issues.
- **This does not clear the feature for `.ai/features/completed/`.** Per round 1's own finding (unchanged by this fix): `vite.config.ts` pins `test.environment: 'node'`, so no automated test in this repo can mount a Vue component or a real `EditorView`. This feature deletes MDVault's only fallback editor, so the mandatory, still-outstanding gate is a **human-run manual browser walkthrough** of `implementation.md` §6 (10 numbered sections). Do not move the folder to completed until the user has run and confirmed that walkthrough.

**Final verdict**: PASS (QA round 2). Issue counts: 0 Critical, 0 High, 0 Moderate, 0 Low open (QA-01 closed). Routing: none remaining. The orchestrator should now request the user's confirmation of the `implementation.md` §6 manual browser walkthrough before moving this feature to `.ai/features/completed/`.
