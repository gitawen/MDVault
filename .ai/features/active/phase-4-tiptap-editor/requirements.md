# Requirements: Phase 4: Tiptap Editor

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Master Plan Phase**: Phase 4, Tiptap Editor (`docs/Masterplan.md` §53; §2 core principle, §16 content on disk, §17 hashing, §18 filesystem as truth, §20 editor, §21 round trip, §22 save strategy, §24 never silently overwrite, §41 service boundaries, §42 Vue rules, §43 error handling, §60 Rules 1, 5, 8, 9, §61 items 14–16, §62 testing)
- **Author**: System Analyst
- **Created Date**: 2026-09-29
- **Task Complexity**: Level 4, Architectural (first intended file overwrite; client-side conversion boundary)
- **Status**: UNDER REVIEW (pending approvals F1–F11 in `plan.md` §6)

---

## 1. Problem Statement
Phase 3 lets users organise notes and read them as raw Markdown, but not edit them. Phase 4 must turn MDVault into an editor. Markdown files stay the source of truth (§2, Rule 1). Opening a note must never change it. Saving must produce valid Markdown that round-trips without unexpected loss (§21, §53). A save must never silently overwrite a change made outside MDVault (§24, Rule 8).

Three constraints shape the design:
- Tiptap and ProseMirror run in the browser, and a PHP HTML-to-Markdown converter is lossy.
- Markdown written by other tools uses syntax the rich editor can't reproduce: tables, HTML, footnotes, reference links, alternative list markers.
- The filesystem and SQLite fail independently (§43).

## 2. Goals & Non-Goals
### In Scope (Goals)
- **Conversion** (F1): client-side Markdown ↔ Tiptap with the official `@tiptap/markdown` extension. A server-side `MarkdownService` preserves the file envelope: UTF-8 BOM, line endings, YAML frontmatter and the trailing newline.
- **Fidelity check on open** (F2), with three outcomes:
  - `exact`: edit in rich text;
  - `reformat`: rich text is read-only until the user allows reformatting, or the user uses Source mode;
  - `unsupported`: Source mode only.

  Nothing is ever written unless the user changed something.
- **Source mode**: a lossless plain-text editor for any editable note.
- **Toolbar** (§20/§53, F3): undo, redo, bold, italic, strike, H1–H3 (plus paragraph), bullet list, ordered list, task list, blockquote, inline code, code block, link (add, edit, remove), horizontal rule, clear formatting. Underline and alignment are **omitted** because they have no Markdown representation.
- **Save strategy** (F4):
  - debounced autosave (1.5 s idle, 10 s max wait);
  - explicit save (Ctrl/Cmd+S and a button);
  - a status indicator;
  - an atomic write: temp sibling `.mdvault-save-*`, then replace, then verify, then re-hash, then a DB update.
- **Stale-write protection** (F5):
  - the client sends the hash of the bytes it loaded;
  - the server refuses the save if the disk differs (HTTP 409);
  - the banner offers Reload from disk / Keep my version (with confirmation) / Copy my text.
- **Read-only cases** (F6): notes over 1 MiB, and notes that aren't valid UTF-8, are read-only with a clear reason.
- **Editor preferences** (F7) apply to both modes. "Show line numbers" stays stored but unused, and its hint is updated.
- **Unsaved-changes guard** (F8): Inertia navigation (note or vault switch, tree operations, re-index) and window close save first. If the save fails, a Stay / Discard dialog appears.
- **Viewer** (F9): `NoteViewer.vue` is replaced by `NoteEditor.vue`. The no-vault demo editor is replaced by an empty state.
- **Tests**: Pest for the server, and Vitest through `vp test` (F10) for the conversion, the fidelity check, the toolbar command output and the autosave engine, all driven by fixture `.md` files.
- **Carry-overs**:
  - QA-P3-01: a `folder` field on tree note nodes; the Move dialog preselects it;
  - QA-P3-02: an ADR clarification.

### Out of Scope (Non-Goals)
- A filesystem watcher, live detection of external changes, a Compare/diff conflict view, and background re-hash (Phase 5).
- Tables, images, callouts, advanced blocks, Markdown-backed underline or alignment, and HTML passthrough.
- Opening links in the OS browser, and link previews.
- A configurable autosave interval, and line numbers.
- Search, backup, encryption, sync.
- Folder rename/move (follow-up item), and `.markdown` files.

## 3. User Personas & Stories
- **As a** note-taker, **I want to** open a Markdown note and edit it with formatting buttons, **so that** I don't have to write Markdown syntax by hand.
- **As an** Obsidian or VS Code user, **I want** MDVault never to rewrite my notes' formatting unless I edit them and agree, **so that** my files stay mine.
- **As a** user with tables or HTML in a note, **I want to** still edit that note safely as plain text, **so that** nothing is lost.
- **As a** user who also edits files in other tools, **I want** MDVault to refuse to overwrite a newer version on disk and let me choose, **so that** I never lose work silently.
- **As a** user, **I want** my changes saved automatically and before I switch notes or close the app, **so that** I never lose typing.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Open in rich text | An editable note whose body round-trips exactly opens in the Tiptap editor with formatting rendered. | Given a note written in the supported syntax, When it is opened, Then headings, emphasis, lists, task lists, quotes, code, links and rules render, and the editor is editable. |
| **FR-02** | Fidelity check | On open, the client classifies the body as `exact`, `reformat` or `unsupported`. `unsupported` means tables, images, HTML, reference definitions, footnotes or wiki links (the last two only when the note isn't `exact`), or an unstable round trip. | Given a note with a table, When opened, Then it opens in Source mode with a notice that names "tables", and Rich mode is disabled. Given `* item` bullets, Then it shows a reformat notice with the first difference and an "Edit in rich text (reformat on save)" action. |
| **FR-03** | No write without an edit | Opening, switching mode or navigating never writes the file unless the content differs from what was loaded. An unchanged save is a no-op on the server. | Given a note is opened and closed without typing, Then the file bytes and modification time are unchanged. Given a save whose resulting bytes equal the disk, Then no write happens and the response has `saved: false`. |
| **FR-04** | Toolbar | The toolbar lists the §20 items except underline and alignment, with active and disabled states and keyboard shortcuts. Each command produces valid Markdown without raw HTML. | Given each toolbar command is applied, When serialised, Then the Markdown matches the expected fixture and contains no HTML tags. |
| **FR-05** | Links | A dialog adds, edits or removes a link. Clicking a link in the editor never navigates the app window. Unsafe protocols are rejected by the Link extension. | Given a selection and the URL `https://example.com`, Then it serialises as `[text](https://example.com)`. Given `javascript:alert(1)`, Then no link is created. |
| **FR-06** | Source mode | A plain-text editor for the whole file (frontmatter included). It is available for every editable note, and it is the default for `unsupported` notes. Switching mode saves first. | Given a table note is edited in Source mode, When saved, Then the file equals the typed text (with the original line endings and BOM re-applied) and nothing else changed. |
| **FR-07** | Frontmatter preserved | A leading YAML block (`---` … `---`) is split off by the server, shown read-only in Rich mode, and re-attached byte-for-byte on a rich save. | Given `---\ntitle: X\n---\n\n# H\n`, When the body is edited in Rich mode, Then the saved file starts with exactly `---\ntitle: X\n---\n\n`. |
| **FR-08** | Envelope preserved | The BOM, the dominant line ending (CRLF or LF) and the trailing-newline presence are preserved on save. New (empty) notes get a trailing newline. | Given a CRLF file with a BOM, When saved from either mode, Then the file still starts with the BOM and uses only CRLF. |
| **FR-09** | Autosave and explicit save | Debounced autosave (1.5 s idle, 10 s max wait), Ctrl/Cmd+S, a Save button, and a status indicator (Saved / Unsaved changes / Saving… / Couldn't save / Conflict). Only one save is in flight at a time, and the base hash chains from one save to the next. | Given continuous typing, Then a save happens at most every 10 s. Given typing during a save, Then a second save follows with the new base hash. |
| **FR-10** | Atomic save | Write the temp sibling `.mdvault-save-<random>` exclusively, verify its size, fsync it (best effort), copy the target's permissions, re-check the base hash, replace with rename (3 attempts), verify the new hash, then update the DB. | Given a successful save, Then the file has the new content, no `.mdvault-save-*` file remains, and the DB hash equals SHA-256 of the file. |
| **FR-11** | Stale-write protection | The request carries `base_hash`. The server compares it with the current disk hash before writing and again just before the replace. A mismatch gives HTTP 409 `{reason: 'changed', current_hash}`. The UI shows Reload / Keep mine (confirm) / Copy. | Given the file was edited externally after opening, When saving, Then the response is 409 and the external content is intact. Given "Keep my version" is confirmed, Then the save is re-sent with `current_hash` and succeeds. |
| **FR-12** | Missing file on save | If the note's file is gone, the response is 409 `{reason: 'missing'}` and no file is created. The editor keeps the text, and the banner offers Copy and Re-index. | Given the file was deleted externally, When saving, Then 409 missing, no file exists at the path, and the editor still holds the text. |
| **FR-13** | Save failures | A lock (the replace fails), a failed or short write (disk full), a read-only file, an unreadable file or a missing vault gives a clear user message. The file is unchanged, the DB is unchanged, and the text stays in the editor. | Given replace fails 3 times, Then 422 `errors.content` says close other programs, and the original bytes are intact. |
| **FR-14** | DB failure after write | If the DB update fails after a successful replace, the save still succeeds (the file is the truth). The exception is reported, and the next open or re-index reconciles. | Given `Note::saving` throws during the save, Then the response is success, the file has the new content, and the exception is reported. |
| **FR-15** | Read-only cases and size cap | Notes over 1 MiB (`too_large`) and invalid UTF-8 notes (`invalid_utf8`) are shown read-only with a reason. The server refuses to save content whose encoded size exceeds 1 MiB, or a note whose current file is not editable. | Given a 1 MiB + 1 byte file, Then the note is not editable and its save is refused. Given `"a\xFFb"`, Then the note is read-only with the reason `invalid_utf8`. |
| **FR-16** | Preferences | Font family, size, line height and word wrap apply in both modes. The "Show line numbers" hint reads "Not used by the editor yet." | Given font_size 20, Then both editors render at 20px. |
| **FR-17** | Unsaved-changes guard | Any Inertia visit while there are unsaved changes (or a save is in flight) waits for a save, then continues. If the save fails, a dialog offers Stay / Discard changes and continue. On window close in the desktop app, save and then close; in the browser, the native prompt appears. | Given the user types and immediately clicks another note, Then the first note is saved before the second opens. |
| **FR-18** | Round-trip tests | Fixture `.md` files: `exact/*` round-trip byte-for-byte and are idempotent; `reformat/*` produce the stored expected output, are idempotent and keep the same document; `unsupported/*` are classified with the right reasons. | The `npm run test:js` round-trip suite passes. |
| **FR-19** | Boundaries | `MarkdownService` does no filesystem work. `FileStorageService::replaceFile` is the only method that replaces a file. `unlink` appears only in `deleteNewEmptyFile` and in the private `discardTempFile`, which accepts `.mdvault-save-*` names only. The Vue side does no path or filesystem logic. The indexer ignores `.mdvault-save-*`. | The architecture tests and T12 greps pass. |
| **FR-20** | Tree folder field (QA-P3-01) | Tree note nodes include `folder` (the `''` root or a relative folder path). `MoveNoteDialog` preselects it without client path logic. | Given `Projects/HRMIS.md`, Then its node has `folder === 'Projects'`, and the Move dialog opens with "Projects" selected. |
| **FR-21** | ADR clarification (QA-P3-02) | Add a sentence to `note-registry-and-indexing.md`: a case-only external rename plus an edit keeps the UUID. | The ADR contains the sentence. |
| **FR-22** | Security and privacy | No `v-html`. Note content is never logged. The save endpoint is CSRF-protected (web middleware). JSON responses expose no `id` or `vault_id`. | Greps pass. The HTTP test asserts that no ids appear. |

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - a local single user, with no auth (ADR `local-app-without-authentication`);
  - web middleware and CSRF on `PUT`;
  - the Link extension's protocol allow-list;
  - no HTML rendering of note content outside ProseMirror's schema. HTML-bearing notes are `unsupported`, so they never enter the rich editor.
- **Performance**:
  - the fidelity check (at most 3 parses and 2 serialisations) should take under about 300 ms for a 1 MiB note on a typical laptop (checked manually in M10);
  - no serialisation on every keystroke: serialisation happens only on the debounce tick or a flush;
  - the save hashes stream (`hash_file`) and the payload is capped at 1 MiB.
- **Accessibility & UX**:
  - the toolbar has `role="toolbar"`, `aria-label`, `aria-pressed` and a `title` with the shortcut on every button;
  - the status is `aria-live="polite"`;
  - every dialog is focus-trapped (reka-ui);
  - every Vue component has a single root.
- **Reliability & Data Integrity**:
  - the atomic replace keeps the original intact on every failure path;
  - the base hash is checked twice;
  - the file is always the truth (no DB rollback of a file write);
  - orphan temp files are dot-prefixed, ignored by the indexer, and hold the user's text, so they are recoverable and never deleted except by the call that created them.

## 6. Technical Constraints & Context
- Framework: Laravel 13 (PHP 8.4); NativePHP desktop runtime.
- Frontend: Inertia.js v3 + Vue 3 + Tailwind CSS v4 + shadcn-vue (reka-ui); Tiptap 3.31.3.
- Routing: Laravel Wayfinder (`@/actions/`, `@/routes/`).
- Testing: Pest 5; Vitest 4.1.11 through `vp test` (Vite+ 0.3.0).
- Code style: Laravel Pint, and `vp check` (oxlint + oxfmt).
- Database: SQLite (metadata only; no `content` column, ever).

## 7. Risks & Assumptions
- **Assumption**: `@tiptap/markdown` (described upstream as an early release) reproduces the core supported set exactly and idempotently. The T2 spike is a go/no-go gate, and the fallback is `tiptap-markdown@0.9.0`.
- **Assumption**: a headless Tiptap `Editor` (or `MarkdownManager`) runs in Vitest's node environment. If it doesn't, `happy-dom` is added as a dev dependency (contingent approval F10).
- **Risk**: most real-world notes classify as `reformat` (other `-`/`*` or indent styles). **Mitigation**: a one-click consent per note per session, Source mode, and fixture-driven tuning of the serializer style in one file.
- **Risk**: a time-of-check/time-of-use gap between the second hash check and the rename. **Mitigation**: accepted (single user, §44). A post-replace verification catches it afterwards and reports a conflict. Phase 5 adds a watcher.
- **Risk**: Electron `beforeunload` cancels a close silently. **Mitigation**: save, then `window.close()` in the desktop runtime; an error banner if the save fails (manual check M8).

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified (CRLF, BOM, frontmatter, empty notes, invalid UTF-8, >1 MiB, locks, read-only, disk full, missing file or vault, DB failure, races, symlinks)
- [ ] Approved to proceed to Planning (`plan.md`), pending F1–F11
