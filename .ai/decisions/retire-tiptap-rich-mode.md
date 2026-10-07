# ADR: Retiring TipTap and the Rich/Source Editor Bifurcation

- **Status**: Accepted (2026-10-07) — P1 satisfied and Q2–Q6 answered yes per `plan.md` §6; implemented by `.ai/features/active/remove-tiptap-rich-mode/`
- **Date**: 2026-10-07
- **Executes**: `.ai/decisions/codemirror-unified-editor.md` §Decision-1
- **Retires**: `.ai/decisions/codemirror-markdown-editor.md` §Decision-1 (the 150 KB `assessMarkdown` bypass) and its "Preserves WYSIWYG for Normal Files" rationale
- **Supersedes**: `.ai/decisions/markdown-conversion-and-fidelity.md` (status to be set to Superseded)
- **Master Plan**: Phase 4 — Editor (§20, §21, §41, §53, amended by this feature); non-negotiables 1, 5, 9, 11, 13

## Context

ADR `codemirror-unified-editor` (accepted 2026-10-07) §Decision-1 required making CodeMirror 6 the single editor engine and deleting the rich vs. source bifurcation. The implementing feature made CodeMirror the *default* and left TipTap behind a mode toggle. Its `implementation.md`, `qa-report.md` and `analyst-review.md` each read that as complete; none records a reason for keeping TipTap. The gap was then carried forward explicitly as a non-goal of the `codemirror-ui-fixes` bug-fix pass (its `requirements.md` §2 and `plan.md` Q2), which fixed 22 defects in the CodeMirror branch while keeping both editors working.

What the half-finished state costs: two save paths, one of which reconstructs the file from a serialised ProseMirror AST (`MarkdownService::composeRich()`) and therefore needs `assessMarkdown` plus a user consent dialog to warn about silent reformatting; a rich mode that is unavailable for notes with images, HTML, reference links, footnotes, wiki links or over 150 KB; nine `@tiptap/*` packages in the bundle; and a two-branch command table whose single-branch coverage is how `clearFormatting`'s literal `: true` no-op shipped.

## Decision

1. **Delete TipTap and the mode bifurcation.** One editor (CodeMirror 6), one toolbar command path, no mode state, no per-note mode memory, no fidelity assessment, no reformat-consent flow, no rich-mode frontmatter panel.
2. **Frontmatter is document text.** The leading YAML block is edited in the one editor, where it lives on disk. This replaces a second representation of the same bytes with none, strengthening Master Plan non-negotiable #1.
3. **Accept four permanent capability deltas**, with mitigations:
   - *No WYSIWYG mode at all.* Markdown markers stay visible, styled by the live-preview decorations; Split and Preview cover rendered reading. Obsidian-style marker-hiding remains available as a future feature on the same decoration plugin. TipTap's WYSIWYG was already unavailable for a large share of real notes, and for "reformat" notes only available after consenting to a rewrite.
   - *No table cell Tab-navigation.* Replaced by a Table toolbar button over the existing `insertTable` command — the trade-off `codemirror-unified-editor` §Consequences already accepted. `insertTable` is first corrected so it cannot delete a selection.
   - *No semantic list indent/outdent on Tab.* Replaced by inserting the `indent_size` preference, which is the correct textual operation and round-trips exactly.
   - *No autolink / link-on-paste.* Minor: a bare URL is already a valid GFM autolink on disk.
4. **Delete the round-trip fidelity corpus** — five JS test files and 28 Markdown fixtures — because it asserts a Markdown→AST→Markdown pipeline that no longer exists. The two behaviours in it that were not TipTap-specific (the `indent_size` Tab preference; "the toolbar table has no `link` command") are explicitly re-homed.
5. **Defer the backend.** `NoteSaveMode::Rich`, `MarkdownService::composeRich()`, `FrontmatterEdit`, the `has_frontmatter`/`frontmatter` request fields and the redundant `body`/`frontmatter`/`frontmatter_yaml` Inertia payload keys become unreachable but stay. Removing them is zero user-visible change against ~25 Pest tests and the encrypted save path; it belongs in its own feature. Keeping this one inside `resources/js/**` + `package.json` + `docs/` is what makes the day TipTap is deleted reviewable and revertible.
6. **Amend `docs/Masterplan.md` §20, §21 and §53** so the authoritative spec stops naming an editor the codebase does not contain, keeping every toolbar item and acceptance criterion verbatim.
7. **Gate on human verification.** The removal does not begin until the `codemirror-ui-fixes` manual browser walkthrough is confirmed. `vite.config.ts` pins `test.environment: 'node'`, so no test in this repo can mount a Vue component or an `EditorView`; TipTap is currently the only fallback for a CodeMirror defect, and the suite cannot tell us whether that fallback is still needed.

## Consequences

- **Positive**:
  - Only one write path remains, and it is byte-verbatim: the "silent reformat on save" risk class is eliminated structurally rather than detected and consented to.
  - Notes with images, HTML, reference links, footnotes, wiki links or over 150 KB open normally, with no banner.
  - Note open loses up to six headless ProseMirror editor create/destroy cycles and two full-document AST `JSON.stringify` comparisons from the main thread.
  - Eleven npm packages leave the bundle (nine `@tiptap/*`, `@codemirror/theme-one-dark`, `codemirror`).
  - Every shared editor module has one code path, so a toolbar fix can no longer be correct in one branch and a no-op in the other.
- **Negative / Trade-offs**:
  - The four capability deltas in Decision 3 are permanent unless re-added deliberately.
  - No automated test can certify the result; a manual walkthrough is mandatory before completion, both for the prior pass (as a precondition) and for this one.
  - Until the deferred follow-up lands, the backend retains a save mode and an Inertia payload shape with no client.
