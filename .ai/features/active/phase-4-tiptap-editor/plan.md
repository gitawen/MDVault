# Plan: Phase 4: Tiptap Editor

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Master Plan Phase**: **Implements Master Plan Phase 4, Tiptap Editor (`docs/Masterplan.md` §53; §2, §16–18, §20–22, §24, §41–43, §60 Rules 1/5/8/9, §61 items 14–16, §62)**
- **Author**: System Analyst
- **Created Date**: 2026-09-29
- **Task Complexity**: Level 4, Architectural (analyst sign-off after QA)
- **Requirements**: `requirements.md`
- **Status**: SIGNED OFF (F1–F11 approved; Revisions 2–4 signed off 2026-09-29; QA round 4 PASS; R4-01 fixed; awaiting user desktop checks M1–M21)

---

## 1. Summary
Markdown is converted in the browser with the official `@tiptap/markdown` extension, sharing one extension list between the visible editor, a headless converter and the tests. A client-side **fidelity check** decides on open whether a note can be edited in rich text exactly, only with consented reformatting, or only in the lossless **Source mode**.

A new server `MarkdownService` owns the file envelope: BOM, line endings, frontmatter and the trailing newline. Saves go through `NoteService::save`, which runs `FileStorageService::replaceFile` (the one intended overwrite in the codebase) guarded by a base hash, and updates SQLite afterwards.

The UI replaces `NoteViewer` with `NoteEditor`, which has a toolbar, debounced autosave, explicit save, a conflict banner and an unsaved-changes guard.

---

## 2. Architecture & Design
- **Approach**:
  1. **Conversion split** (ADR `markdown-conversion-and-fidelity`):
     - Client, under `resources/js/lib/markdown/`: Markdown ↔ Tiptap JSON through `@tiptap/markdown` 3.31.3 (lexer `marked` 17, GFM on), plus the fidelity check.
     - Server `MarkdownService`: `decode` (bytes → envelope and LF source), `composeRich`, `composeSource`, `encode` (source → bytes with the original EOL and BOM).

     The body sent to Tiptap never contains frontmatter or a BOM, and uses LF line endings.
  2. **Fidelity check** (`assessMarkdown(body)`), in this order:
     1. Lexer scan for structural tokens the rich editor can't hold: `table`, `html`/`tag` (block or inline), `image`, `def`, or a non-empty `tokens.links`. Any of these → `unsupported`.
     2. `R1 = serialize(parse(body))`. If `trimEndNewlines(R1) === trimEndNewlines(body)` → `exact`.
     3. Text patterns: footnotes `\[\^[^\]\s]+\]` and wiki links `\[\[[^\]\n]+\]\]` → `unsupported`.
     4. If `serialize(parse(R1)) === R1` **and** `JSON.stringify(parse(R1)) === JSON.stringify(parse(body))` → `reformat` (with the first differing line), otherwise `unsupported('unstable')`.
  3. **No write without an edit**:
     - The saver's baseline is the visible editor's own `getMarkdown()` captured in `onCreate` (Rich), or the loaded text (Source).
     - Content equal to the baseline is never sent.
     - The server also skips the write when the new bytes hash to the current hash.
  4. **Save** (ADR `note-save-atomic-replace`):
     1. Check the base hash (409 on mismatch).
     2. Compose and encode.
     3. Skip if unchanged.
     4. Check the file is writable.
     5. `replaceFile`: exclusive temp → full write and size check → best-effort fsync → copy perms → **guard: re-hash equals base** → rename (3 attempts) → check afterwards.
     6. Verify the new hash.
     7. DB update. On a DB failure the file is kept and the exception is reported.
  5. **Transport**:
     - `PUT /notes/{note:uuid}/content`, JSON, sent through Inertia v3 `useHttp`.
     - 200 `{saved, file_hash, file_size, updated_at}`; 409 `{reason, message, current_hash}`; 422 validation and operation errors on `content` (or `vault`).
     - The Workspace `note` prop gains `body`, `frontmatter`, `base_hash`, `editable`, `read_only_reason`.
  6. **Client state**:
     - A framework-free `noteSaver` handles debounce, max wait, a single in-flight save, hash chaining, conflict pause, overwrite and discard.
     - `useUnsavedChangesGuard` handles `router.on('before')` plus `beforeunload`.
     - `editorSession` holds per-session maps: the mode per note UUID, and the set of notes accepted for reformat.
- **Alternatives Considered**:
  - Server conversion with league/commonmark plus league/html-to-markdown: rejected. HTML → Markdown is lossy (list markers, escapes, code fences), and it would need Tiptap HTML on the wire.
  - `tiptap-markdown@0.9.0` (markdown-it + prosemirror-markdown; peer `@tiptap/core ^3.0.1`): a mature serializer, but a community package that trails Tiptap releases. **Kept as the T2 fallback.**
  - Hand-rolled prosemirror-markdown mapping: more code, same fidelity class.
  - Opening unsupported notes read-only only: rejected in favour of Source mode, which lets the user still edit safely.
  - Warning at save time instead of at open: rejected, because autosave makes a save-time prompt disruptive. Consent is asked once, at open.
  - Writing with `file_put_contents` in place: rejected, because a crash or disk-full mid-write truncates the only copy.
  - A `force` flag for overwrite: rejected. Keep mine re-sends with the disk's current hash, so a third change still produces a 409.
  - Underline as `++` (Tiptap default), or `<u>`, and alignment as HTML: rejected (F3).
- **Decision Records**:
  - `.ai/decisions/markdown-conversion-and-fidelity.md` (new)
  - `.ai/decisions/note-save-atomic-replace.md` (new)
  - `.ai/decisions/note-file-operations.md` (Decision and Follow-ups lines amended in T0)
  - `.ai/decisions/note-registry-and-indexing.md` (QA-P3-02 sentence added in T0)
  - `.ai/decisions/settings-persistence.md` (no change; no new setting keys)

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| — | none | No migration. `notes.file_hash`, `file_size` and `updated_at` are updated on save. **No `content` column.** |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Service (new) | `app/Services/MarkdownService.php` | Envelope decode, compose and encode (pure string work, no filesystem) |
| Support (new) | `app/Support/MarkdownDocument.php` | Immutable envelope value object |
| Support (new) | `app/Support/NoteSaveResult.php` | Immutable save result |
| Enum (new) | `app/Enums/FileReplaceResult.php` | `Replaced`, `TargetInvalid`, `WriteFailed`, `GuardFailed`, `ReplaceFailed` |
| Enum (new) | `app/Enums/NoteSaveMode.php` | `Rich = 'rich'`, `Source = 'source'` |
| Exception (new) | `app/Exceptions/NoteSaveConflictException.php` | 409 conflicts: `changed` (with current hash) or `missing` |
| Exception (modify) | `app/Exceptions/NoteOperationException.php` | Save failure constructors |
| Service (modify) | `app/Services/FileStorageService.php` | `isWritableFile`, `replaceFile`, private `discardTempFile`, private `flushToDisk` |
| Service (modify) | `app/Services/NoteService.php` | `EDIT_LIMIT`, `save()`, extended `preview()` |
| Service (modify) | `app/Services/VaultIndexService.php` | `folder` on note nodes (QA-P3-01) |
| Request (new) | `app/Http/Requests/Notes/SaveNoteContentRequest.php` | `content`, `base_hash`, `mode` |
| Controller (new) | `app/Http/Controllers/NoteContentController.php` | Invokable JSON save |
| Controller (modify) | `app/Http/Controllers/WorkspaceController.php` | Call `preview()` before `present()` |
| Routes (modify) | `routes/notes.php` | `PUT notes/{note:uuid}/content` |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Module (new) | `resources/js/lib/markdown/extensions.ts` | `markdownExtensions()`: the single extension list |
| Module (new) | `resources/js/lib/markdown/converter.ts` | Headless converter: `parse`, `serialize`, `roundTrip` |
| Module (new) | `resources/js/lib/markdown/assess.ts` | `assessMarkdown()` fidelity check |
| Module (new) | `resources/js/lib/editor/toolbarCommands.ts` | Toolbar command table (run, isActive, canRun) |
| Module (new) | `resources/js/lib/editor/noteSaver.ts` | Autosave engine |
| Module (new) | `resources/js/lib/editor/editorSession.ts` | Session maps: mode per note, reformat consent |
| Composable (new) | `resources/js/composables/useUnsavedChangesGuard.ts` | Navigation and window-close guard |
| Component (new) | `resources/js/components/editor/NoteEditor.vue` | Container: header, mode toggle, status, Save, banners, footer |
| Component (rework) | `resources/js/components/editor/TiptapEditor.vue` | Rich editor: markdown in, `change` out, `getMarkdown()` exposed |
| Component (new) | `resources/js/components/editor/EditorToolbar.vue` | Toolbar buttons |
| Component (new) | `resources/js/components/editor/LinkDialog.vue` | Add, edit or remove a link |
| Component (new) | `resources/js/components/editor/SourceEditor.vue` | Textarea source editor |
| Component (new) | `resources/js/components/editor/NoteConflictAlert.vue` | Conflict and missing banner with confirmations |
| Component (new) | `resources/js/components/editor/UnsavedChangesDialog.vue` | Stay / Discard dialog |
| Component (delete) | `resources/js/components/notes/NoteViewer.vue` | Replaced by `NoteEditor` (F9) |
| Component (modify) | `resources/js/components/notes/MoveNoteDialog.vue` | Preselect `note.folder` |
| Page (modify) | `resources/js/pages/Workspace.vue` | Render `NoteEditor`; no-vault empty state |
| Page (modify) | `resources/js/pages/settings/Editor.vue` | Line-numbers hint copy |
| Types (modify) | `resources/js/types/notes.ts` | New fields and save types |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| PUT | `/notes/{note:uuid}/content` | `notes.content.update` | `NoteContentController` (invokable) | web (CSRF), `whereUuid('note')` |

---

## 3. Implementation Tasks
Rules for every task:
- After each task, run `php vendor/bin/pint --dirty --format agent` and that task's tests.
- After any route change, run `php artisan wayfinder:generate --with-form --no-interaction`.
- Use the Phase 2/3 filesystem test harness (a temp dir, `fakeDocumentsDirectory`, `VaultService::create('Work')`, `writeVaultFiles`, cleanup in `afterEach`).
- Read the two new ADRs first.
- Activate the skills `laravel-best-practices`, `testing-best-practices`, `inertia-vue-development`, `wayfinder-development` and `tailwindcss-development` where relevant.
- **No AI attribution** in any commit, comment or artifact.
- Never run `migrate:fresh` against the desktop DB.

- [x] **T0: Preconditions (orchestrator/user)**
  - Record the F1–F11 answers in §6. If F1 is not the recommended answer, or F2/F3 deviate, stop and ask the analyst for a revision.
  - Save both new ADRs.
  - Edit `.ai/decisions/note-file-operations.md`:
    - **Decision → Compensation**: replace "`unlink` appears only in `FileStorageService::deleteNewEmptyFile`." with "`unlink` appears only in `FileStorageService::deleteNewEmptyFile` and in the private `FileStorageService::discardTempFile`, which accepts only MDVault's own `.mdvault-save-*` temp files (ADR `note-save-atomic-replace`)."
    - **Decision → Never overwrite**: append the bullet "Sole exception: `FileStorageService::replaceFile`, used only by `NoteService::save` behind a base-hash guard (ADR `note-save-atomic-replace`)."
    - **Follow-ups**: replace the Phase 4 bullet with "Phase 4 (delivered): atomic save via `replaceFile`; see ADR `note-save-atomic-replace`."
  - Edit `.ai/decisions/note-registry-and-indexing.md` → Consequences → Negative / trade-offs: after the bullet "An external rename plus an edit…", add: "The narrower case of an external **case-only** rename combined with an edit keeps the UUID: the case-insensitive path step pairs on the path alone (no hash check) and adopts the new hash and size." (QA-P3-02)
  - Create the feature branch `phase-4-tiptap-editor` (never commit on `main`). Suggested: `git add -A && git commit -m "Before Phase 4"` on that branch.
  - Covers: FR-21

- [ ] **T1: Tree `folder` field and Move preselection (QA-P3-01)**
  - `app/Services/VaultIndexService.php` → `buildFolderChildren()`: add `'folder' => $parent` to each note node. Update the PHPDoc node shape.
  - `resources/js/types/notes.ts`: add `folder: string` to `NoteTreeNote`.
  - `MoveNoteDialog.vue`: in the `watch(open)` handler, set `form.folder = props.note?.folder ? props.note.folder : ROOT`. Assignment only; no string operations.
  - Tests in `tests/Feature/Services/VaultIndexServiceTest.php` (`browse` scenario): the `Projects/HRMIS.md` node has `folder === 'Projects'`, and the `Readme.md` node has `folder === ''`. Add `.mdvault-save-abc123` to the ignore-rules dataset.
  - Covers: FR-19, FR-20

- [ ] **T2: Dependencies, JS test harness, round-trip spike (go/no-go gate)**
  - `npm install @tiptap/markdown@^3.31.3 @tiptap/extension-list@^3.31.3 marked@^17.0.1`. Check that `npm ls @tiptap/core marked` shows one version of each.
  - `package.json` scripts: add `"test:js": "vp test run"`.
  - `vite.config.ts`: add `test: { include: ['tests/js/**/*.test.ts'], environment: 'node' }`. If `vp test` fails because of the plugins (Wayfinder runs artisan, laravel-vite-plugin), change the plugin factory to `lazyPlugins(() => process.env.VITEST ? [] : [ …existing… ])` and record this.
  - `tsconfig.json`: add `"tests/js/**/*.ts"` to `include`.
  - Modules under `resources/js/lib/markdown/` and `resources/js/lib/editor/` use **relative imports only** (no `@/`), so tests need no alias.
  - Spike test `tests/js/markdown/spike.test.ts` (`import { describe, expect, it } from 'vite-plus/test'`):
    - Build a headless editor with `new Editor({ element: null, extensions: markdownExtensions(), content: md, contentType: 'markdown' })`, or `editor.markdown` / `MarkdownManager`, whichever works in node.
    - Assert that `editor.getMarkdown()` round-trips a document covering: H1–H3, paragraphs, `**bold**`, `*italic*`, `~~strike~~`, `` `code` ``, `[link](https://x.y)`, `-` lists nested once, `1.` lists, `- [ ]` / `- [x]`, `>` quote, a fenced ```` ```php ```` block and `---`.
  - **Gate**:
    - If headless creation needs a DOM, add `happy-dom` (dev) **only if F10b was approved**, and use `// @vitest-environment happy-dom` in the Tiptap-touching test files.
    - If any core construct above can't round-trip **idempotently** into standard CommonMark/GFM, even after tuning in `extensions.ts` (T3), then **stop**. Record the findings in `implementation.md` and escalate to the analyst (fallback candidate: `tiptap-markdown@0.9.0`).
    - Record the canonical emitted style (bullet marker, emphasis markers, nested indent, ordered-list indent, empty-paragraph output, hard-break output) in `implementation.md`. The T3 fixtures use it.
  - Covers: FR-18 (harness)

- [ ] **T3: Client Markdown module and fidelity check**
  - `resources/js/lib/markdown/extensions.ts`:
    ```ts
    export function markdownExtensions(): Extensions // from @tiptap/core
    ```
    It returns:
    - `StarterKit.configure({ underline: false, trailingNode: false, link: { openOnClick: false, autolink: true, linkOnPaste: true, defaultProtocol: 'https' } })`
    - `TaskList`, `TaskItem.configure({ nested: true })` (from `@tiptap/extension-list`)
    - `Markdown.configure({ markedOptions: { gfm: true }, indentation: { style: 'space', size: 2 } })`

    Heading levels stay 1–6, so notes with H4–H6 aren't lossy; the toolbar exposes only H1–H3. **No Underline, no TextAlign.** Serializer overrides are allowed **only in this file** (e.g. Link: when the text equals the href and the href starts with `http://` or `https://`, emit the bare URL, so GFM bare URLs round-trip). Each override must have a fixture.
  - `resources/js/lib/markdown/converter.ts`:
    ```ts
    export type MarkdownConverter = { parse(markdown: string): JSONContent; serialize(doc: JSONContent): string; roundTrip(markdown: string): string; destroy(): void };
    export function createMarkdownConverter(): MarkdownConverter; // headless editor, the same markdownExtensions()
    export function getMarkdownConverter(): MarkdownConverter;    // lazy module singleton for the browser
    ```
  - `resources/js/lib/markdown/assess.ts`:
    ```ts
    export type UnsupportedReason = 'tables' | 'images' | 'html' | 'reference-links' | 'footnotes' | 'wiki-links' | 'unstable';
    export type MarkdownAssessment =
      | { status: 'exact' }
      | { status: 'reformat'; firstDifference: { line: number; before: string; after: string } }
      | { status: 'unsupported'; reasons: UnsupportedReason[] };
    export function assessMarkdown(body: string, converter: MarkdownConverter): MarkdownAssessment;
    export const UNSUPPORTED_LABELS: Record<UnsupportedReason, string>; // 'tables', 'images', 'HTML', 'reference-style links', 'footnotes', 'wiki links ([[…]])', 'formatting the editor can't reproduce exactly'
    ```
    - Use exactly the algorithm in §2 step 2.
    - Lexer: `marked.lexer(body, { gfm: true })`. Walk recursively through `tokens`, `items[].tokens`, and table `header`/`rows`.
    - `trimEndNewlines = s => s.replace(/\n+$/, '')`.
    - `firstDifference`: the 1-based index of the first differing line between `body` and `R1` (split on `\n`), with both lines (or `''`).
  - Fixtures in `tests/js/fixtures/markdown/`, loaded with `import.meta.glob('../fixtures/markdown/**/*.md', { query: '?raw', import: 'default', eager: true })`:
    - `exact/`: `headings.md`, `emphasis.md` (bold, italic, strike, code, `C++ and C++`, `a * b`), `links.md` (inline link, link with title if supported, bare URL), `lists.md` (nested bullets, ordered starting at 3, tasks checked and unchecked), `blocks.md` (quote with a nested list, fenced code with and without a language, `---`), `unicode.md` (CJK, emoji, accents), `empty.md` (0 bytes), `h4-h6.md`.
    - `reformat/` (each with `*.expected.md`): `star-bullets.md` (`* a`), `underscore-emphasis.md` (`_a_`, `__b__`), `setext-heading.md`, `indented-code.md`, `tilde-fence.md`, `four-space-nesting.md`. Fill each `expected.md` with the actual emitted output once it has been reviewed as semantically identical.
    - `unsupported/`: `table.md`, `image.md`, `html-block.md`, `inline-html.md` (`a <br> b`), `reference-link.md`, `footnote.md`, `wiki-link.md` (`[[Other note]]`).
  - Tests:
    - `tests/js/markdown/roundTrip.test.ts`:
      1. Every `exact/*`: `assess` gives `exact`, `roundTrip(md)` equals md (both trimmed of trailing newlines), and `roundTrip(roundTrip(md)) === roundTrip(md)`.
      2. Every `reformat/*`: `assess` gives `reformat`; `roundTrip(md)` equals the expected file; the expected file is idempotent; and `parse(md)` deep-equals `parse(expected)`.
      3. Every `unsupported/*`: gives `unsupported`, with the reason named in the file stem (dataset map).
      4. `markdownExtensions()` has no extension named `underline` or `textAlign`, and the link extension has `openOnClick === false`.
    - `tests/js/markdown/assess.test.ts`:
      - `firstDifference` line numbers;
      - a structural table is `unsupported` even when surrounded by exact content;
      - a wiki link inside an exact note: if the whole note is `exact`, the result is `exact` (documented order);
      - an unstable input (use a crafted converter stub whose `roundTrip` is not idempotent) gives `unsupported('unstable')`.
  - Covers: FR-01, FR-02, FR-03, FR-18

- [ ] **T4: `MarkdownService` (server envelope)**
  - Commands: `php artisan make:class Services/MarkdownService --no-interaction` (`final`, no constructor) and `php artisan make:class Support/MarkdownDocument --no-interaction`.
  - `App\Support\MarkdownDocument`: `final readonly class`:
    ```php
    public function __construct(
        public bool $hasBom,
        public string $eol,            // MarkdownService::EOL_LF | EOL_CRLF
        public bool $validUtf8,
        public string $source,         // BOM stripped, CRLF→LF, scrubbed if invalid UTF-8
        public ?string $frontmatter,   // leading YAML block incl. closing line and following blank lines, LF
        public string $body,           // source minus frontmatter
        public bool $endsWithNewline,  // source ends with "\n"
    ) {}
    ```
  - `MarkdownService`:
    ```php
    public const EOL_LF = 'lf';
    public const EOL_CRLF = 'crlf';
    public const BOM = "\xEF\xBB\xBF";
    public function decode(string $bytes): MarkdownDocument;
    /** Rich save: current frontmatter + body; trailing-newline policy below. */
    public function composeRich(MarkdownDocument $current, string $body): string;
    /** Source save: the text verbatim, CRLF→LF only. */
    public function composeSource(string $text): string;
    /** LF source → bytes: "\n"→"\r\n" when $eol is CRLF; prefix BOM when $bom. */
    public function encode(string $source, string $eol, bool $bom): string;
    ```
    - `decode`:
      1. BOM check and strip.
      2. `$validUtf8 = mb_check_encoding($text, 'UTF-8')`; if it is false, `$text = mb_scrub($text, 'UTF-8')`.
      3. `$crlf = substr_count($text, "\r\n")`; `$lf = substr_count($text, "\n") - $crlf`; eol = `$crlf > $lf ? CRLF : LF`.
      4. `$source = str_replace("\r\n", "\n", $text)`. Lone `\r` is left as it is.
      5. Frontmatter: `preg_match('/\A---[ \t]*\n(?:.*?\n)?---[ \t]*(?:\n|\z)(?:[ \t]*\n)*/s', $source, $m)`. If it matches → frontmatter `$m[0]`, body = the rest. Otherwise null and body = source.
    - `composeRich`:
      ```
      $body = rtrim(str_replace("\r\n", "\n", $body), "\n")
      $source = ($current->frontmatter ?? '') . $body
      if ($body !== '' && ($current->endsWithNewline || $current->source === '')) $source .= "\n"
      ```
    - `encode`: normalise with `str_replace("\r\n", "\n", $source)` first, then convert. This makes it idempotent.
    - PHPDoc references ADR `markdown-conversion-and-fidelity`.
  - `ArchitectureTest`: add `App\Services\MarkdownService` to the targets of the "index and note services use no raw filesystem functions" rule.
  - Tests in `tests/Feature/Services/MarkdownServiceTest.php` (`php artisan make:test Services/MarkdownServiceTest --pest --no-interaction`):
    1. LF, no BOM, no frontmatter: fields as expected.
    2. BOM detected and stripped from `source`.
    3. CRLF detected and `source` is LF. Mixed: 2 CRLF + 1 LF → CRLF; 1 CRLF + 2 LF → LF.
    4. Frontmatter dataset:
       - `---\ntitle: X\n---\n\n# H\n` → frontmatter `---\ntitle: X\n---\n\n`, body `# H\n`;
       - empty `---\n---\n` → frontmatter;
       - `---\nno close\n` → none;
       - `text\n---\nx\n---\n` → none;
       - frontmatter only `---\na: 1\n---` → body `''`.
    5. Identity: for a dataset of byte strings (plain; BOM; CRLF; BOM + CRLF + frontmatter; no trailing newline; lone `\r` inside a line), `encode(decode(x)->source, eol, hasBom) === x`.
    6. `composeRich` dataset: keeps frontmatter byte-for-byte; adds `\n` when the original ended with one; none when it didn't; an empty original gets `\n`; an empty body with frontmatter gives the frontmatter only; incoming CRLF is normalised.
    7. `composeSource` is verbatim apart from CRLF → LF.
    8. Invalid UTF-8 `"a\xFFb"` → `validUtf8` false, and `source` is valid UTF-8.
  - Covers: FR-07, FR-08, FR-15, FR-19

- [ ] **T5: `FileStorageService::replaceFile` (the atomic replace)**
  - Command: `php artisan make:enum Enums/FileReplaceResult --no-interaction`. Make it a string-backed enum with the cases `Replaced`, `TargetInvalid`, `WriteFailed`, `GuardFailed`, `ReplaceFailed`. Check the generated path is `app/Enums/FileReplaceResult.php`.
  - Add to `FileStorageService` (it stays `final`; no existing method changes):
    ```php
    public const SAVE_TEMP_PREFIX = '.mdvault-save-';
    public const REPLACE_ATTEMPTS = 3;
    public function isWritableFile(string $path): bool; // clearstatcache(); is_file && ! is_link && is_writable
    /**
     * The ONLY method that replaces an existing file (ADR note-save-atomic-replace).
     * $beforeReplace runs after the temp file is complete and immediately before the rename; returning false aborts.
     */
    public function replaceFile(string $path, string $contents, ?callable $beforeReplace = null): FileReplaceResult;
    ```
    - `replaceFile` algorithm:
      1. If `! is_file($path) || is_link($path)` → `TargetInvalid`.
      2. `$tmp = siblingPath($path, self::SAVE_TEMP_PREFIX.Str::random(12))`.
      3. If `! createFile($tmp)` (exclusive, empty) → `WriteFailed`.
      4. `try { $written = $this->files->put($tmp, $contents); } catch (\Throwable) { $written = false; }`. If `$written !== strlen($contents) || size($tmp) !== strlen($contents)` → `discardTempFile($tmp)`, return `WriteFailed`.
      5. `flushToDisk($tmp)`: best effort, `@fopen($tmp, 'r+')`, `@fsync`, `fclose` in `finally`. Failures are ignored.
      6. `$perms = @fileperms($path); if ($perms !== false) { @chmod($tmp, $perms & 0777); }`
      7. If `$beforeReplace !== null && ! $beforeReplace()` → discard, `GuardFailed`.
      8. Up to `REPLACE_ATTEMPTS` × `attemptFileMove($tmp, $path)`, with `usleep(100_000)` between attempts. `Filesystem::move` is `rename()`, **which replaces the target: intended here only.**
      9. `clearstatcache()`. If it did not move, or `file_exists($tmp)` → discard, `ReplaceFailed`. Otherwise `Replaced`.
    - `private function discardTempFile(string $path): bool`:
      - Only acts if `str_starts_with(basename($path), self::SAVE_TEMP_PREFIX) && is_file($path) && ! is_link($path)`.
      - Then `@unlink`, and return `! file_exists`.
    - PHPDoc on both methods: a Windows lock or antivirus makes the rename fail with the original intact; a crash may leave a `.mdvault-save-*` orphan that holds the user's new text (ignored by the indexer, never auto-deleted).
  - `tests/Pest.php` helper:
    ```php
    /** Substitute Filesystem so put() writes only the first $truncateTo bytes (null = normal) and returns false when truncated; $onPut runs before each put (e.g. to simulate an external writer). Resolve services AFTER calling this. */
    function fakeFilePuts(?int $truncateTo = null, ?Closure $onPut = null): object
    ```
  - `ArchitectureTest`: add `arch('only FileStorageService deletes, writes or syncs files')->expect(['unlink', 'file_put_contents', 'fsync'])->toOnlyBeUsedIn('App\Services\FileStorageService');`. If `NativeTrash` or anything else legitimately trips it, record it in `implementation.md` and add an `ignoring()` with the reason.
  - Tests to add to `tests/Feature/Services/FileStorageServiceTest.php`:
    1. It replaces the content and returns `Replaced`; there is no `.mdvault-save-*` left (`glob`).
    2. Guard returns false → `GuardFailed`; the original bytes are intact; no temp is left.
    3. `failFileMoves([1, 2, 3])` → `ReplaceFailed`; intact; no temp.
    4. `failFileMoves([1])` → `Replaced` (retry works).
    5. `fakeFilePuts(truncateTo: 3)` → `WriteFailed`; intact; no temp.
    6. A missing target → `TargetInvalid`; nothing is created. A symlink target → `TargetInvalid` (`->skipOnWindows()`).
    7. Permissions are preserved: `chmod 0640` → still `0640` (`->skipOnWindows()`).
    8. `isWritableFile`: true for a normal file; false after `chmod($p, 0444)` (on Windows this sets the read-only attribute). Restore `0644` in `finally`.
    9. `discardTempFile` never deletes a non-temp name. Test it through `replaceFile` on a target named `note.md`: the target always survives every failure path. This is covered by tests 2, 3 and 5; assert the target exists in each.
  - Covers: FR-10, FR-13, FR-19

- [ ] **T6: `NoteService::save` and the extended `preview`**
  - Commands:
    - `php artisan make:enum Enums/NoteSaveMode --no-interaction` (`Rich = 'rich'`, `Source = 'source'`)
    - `php artisan make:exception NoteSaveConflictException --no-interaction` (`final`, extends `\RuntimeException`, private constructor, like `NoteOperationException`)
    - `php artisan make:class Support/NoteSaveResult --no-interaction`
  - `NoteSaveConflictException`:
    - `changed(string $relative, string $currentHash)`: "“{relative}” was changed outside MDVault after you opened it. Your edits haven't been saved yet."
    - `missing(string $relative)`: "The file for this note is no longer at {relative}. It may have been moved, renamed or deleted outside MDVault. Your text is still in the editor."
    - Accessors: `reason(): string` (`'changed'|'missing'`) and `currentHash(): ?string`.
  - New `NoteOperationException` constructors, all with field `content`. Messages contain only the user's own names:
    - `saveLocked(string $relative)`: "“{relative}” couldn't be saved because another program is using it. Close that program and try again. Your text is still in the editor; the file on disk wasn't changed."
    - `saveWriteFailed(string $relative)`: "“{relative}” couldn't be saved: MDVault couldn't write the new version (the disk may be full, or the folder isn't writable). Your text is still in the editor; the file on disk wasn't changed."
    - `readOnlyFile(string $relative)`: "“{relative}” is read-only, so MDVault won't change it. Make it writable in your file manager and try again. Your text is still in the editor."
    - `contentTooLarge()`: "This note is larger than 1 MB, which the editor can't save. The file on disk wasn't changed. Copy your text, or split the note."
    - `notEditable(string $relative)`: "“{relative}” can't be edited in MDVault (it is larger than 1 MB, isn't valid UTF-8, or isn't a regular file). Nothing was changed."
    - `saveUnreadable(string $relative)`: "MDVault couldn't read “{relative}” to check it before saving. Close any programs that may be locking it and try again. Your text is still in the editor."
  - `App\Support\NoteSaveResult`: `final readonly class` with `public bool $written, public string $fileHash, public int $fileSize, public ?string $updatedAt`, and `toArray(): array{saved: bool, file_hash: string, file_size: int, updated_at: ?string}`.
  - `NoteService`:
    - Add `MarkdownService $markdown` to the constructor.
    - Add the constant `EDIT_LIMIT = self::PREVIEW_LIMIT`.
    - `save`:
      ```php
      /** @throws NoteOperationException|NoteSaveConflictException */
      public function save(Note $note, string $content, string $baseHash, NoteSaveMode $mode): NoteSaveResult;
      ```
      Order:
      1. `assertVaultAvailable`. `$abs = absolutePath($note)`.
      2. If `! $this->files->isFile($abs)` → `NoteSaveConflictException::missing`. If `isSymlink($abs)` → `notEditable`.
      3. `$current = read($abs)`; if null → `saveUnreadable`.
      4. `$currentHash = hashString($current)`. If `! hash_equals($currentHash, $baseHash)` → `NoteSaveConflictException::changed($rel, $currentHash)`.
      5. `$doc = decode($current)`. If `strlen($current) > EDIT_LIMIT || ! $doc->validUtf8` → `notEditable`.
      6. `$source = $mode === Rich ? composeRich($doc, $content) : composeSource($content)`. `$bytes = encode($source, $doc->eol, $doc->hasBom)`. If `strlen($bytes) > EDIT_LIMIT` → `contentTooLarge`.
      7. `$newHash = hashString($bytes)`. If `$newHash === $currentHash` → reconcile the DB if it is stale (the same try/report as step 11), and return `written: false`.
      8. If `! isWritableFile($abs)` → `readOnlyFile`.
      9. `$result = replaceFile($abs, $bytes, fn (): bool => $this->hashes->matches($abs, $currentHash))`. Then `match`:
         - `TargetInvalid` → `missing`;
         - `WriteFailed` → `saveWriteFailed`;
         - `GuardFailed` → `changed($rel, $this->hashes->hashFile($abs) ?? $currentHash)`;
         - `ReplaceFailed` → `saveLocked`.
      10. `$diskHash = hashFile($abs)`. If `$diskHash !== $newHash` → `changed($rel, $diskHash ?? $newHash)`. Someone wrote right after us; the user decides.
      11. `try { $note->update(['file_hash' => $newHash, 'file_size' => strlen($bytes)]); } catch (\Throwable $e) { report($e); }`. The file wins; do **not** compensate.
      12. Return `written: true` with `updated_at` as `$note->updated_at?->toIso8601String()`.
    - `preview()`: the new return shape is
      `array{content: ?string, body: ?string, frontmatter: ?string, base_hash: ?string, state: 'ok'|'missing'|'too_large'|'unreadable', is_valid_utf8: bool, editable: bool, read_only_reason: 'too_large'|'invalid_utf8'|null}`.
      - `missing` / `unreadable`: nulls, `editable` false, reason null.
      - `too_large`: reconcile as today; `editable` false, reason `'too_large'`.
      - `ok`:
        - reconcile as today;
        - `$doc = decode($content)`;
        - `content = $doc->source`, `body = $doc->body`, `frontmatter = $doc->frontmatter`;
        - `base_hash = hashString($raw)`, the hash of the bytes read;
        - `is_valid_utf8 = $doc->validUtf8`;
        - `editable = $doc->validUtf8`; `read_only_reason = $doc->validUtf8 ? null : 'invalid_utf8'`.
      - Update the class PHPDoc to reference ADR `note-save-atomic-replace`.
  - Tests to add to `tests/Feature/Services/NoteServiceTest.php`. Every failure test asserts that the file bytes, the DB hash and the UUID are unchanged, and that no `.mdvault-save-*` file is left:
    1. **Rich save**:
       - `Note.md` = `---\ntitle: X\n---\n\n# Old\n`;
       - `save($note, "# New\n", hash, Rich)` → the file is `---\ntitle: X\n---\n\n# New\n`;
       - `written` true;
       - the result hash equals `hash_file`, and the DB matches;
       - `relative_path` and `uuid` are unchanged.
    2. **Source save** is verbatim.
    3. **CRLF + BOM**: a rich save produces bytes that start with the BOM, have no bare `\n`, and end with `\r\n`.
    4. **No-op**:
       - saving `$doc->body` unchanged → `written` false;
       - `failFileMoves([1, 2, 3])` shows `calls === 0`;
       - the modification time is unchanged.
    5. **Stale base hash**: an external edit after reading → `NoteSaveConflictException` with reason `changed`, and `currentHash` equals the disk hash. The external content is intact.
    6. **Missing file** → reason `missing`; no file is created.
    7. **Lock**: `failFileMoves([1, 2, 3])` → field `content` (`saveLocked`).
    8. **Short write**: `fakeFilePuts(truncateTo: 1)` → `saveWriteFailed`.
    9. **Read-only**: `chmod 0444` → `readOnlyFile`. Restore the permissions afterwards.
    10. **Too large**: content longer than 1,048,576 bytes → `contentTooLarge`.
    11. **Not editable**: a current file of 1 MiB + 1 byte → `notEditable`. `"a\xFFb"` → `notEditable`.
    12. **Race**: `fakeFilePuts(onPut: fn () => file_put_contents($abs, 'external'))` → `changed`; the file is `'external'`.
    13. **DB failure after the write**:
        - with `Exceptions::fake()` and `Note::saving` throwing when `$note->exists`, the save returns `written` true;
        - the file has the new content;
        - `Exceptions::assertReported(RuntimeException::class)`;
        - after `Note::flushEventListeners()`, the record in `fresh()` has the old hash, and a following `preview()` reconciles it to the disk hash.
    14. **Missing vault** → field `vault`.
    15. **preview**:
        - `ok` fields are correct for LF, CRLF and BOM (the BOM is not in `content`; `base_hash === hash_file`);
        - frontmatter split;
        - a stale DB hash (after an external edit) gives `base_hash` equal to the disk hash;
        - `too_large` → `editable` false with reason `too_large`;
        - invalid UTF-8 → reason `invalid_utf8`.
    16. **Chaining**: two consecutive saves using the first result's `fileHash` as the second's base both succeed.
  - Covers: FR-03, FR-07, FR-08, FR-10 to FR-15

- [ ] **T7: HTTP save endpoint and Workspace prop changes**
  - Commands: `php artisan make:controller NoteContentController --invokable --no-interaction` and `php artisan make:request Notes/SaveNoteContentRequest --no-interaction`.
  - `SaveNoteContentRequest`:
    - `authorize()` true.
    - Rules:
      - `content`: `['present', 'nullable', 'string', closure: strlen($value ?? '') <= NoteService::EDIT_LIMIT else "This note is larger than 1 MB, which the editor can't save. The file on disk wasn't changed."]`;
      - `base_hash`: `['required', 'string', 'regex:/^[0-9a-f]{64}$/']`;
      - `mode`: `['required', Rule::enum(NoteSaveMode::class)]`.
    - Accessor `contentText(): string` returns `(string) ($this->validated('content') ?? '')`.
  - `NoteContentController::__invoke(SaveNoteContentRequest $request, Note $note, NoteService $notes): JsonResponse`:
    - `try { $result = $notes->save($note, $request->contentText(), $request->validated('base_hash'), NoteSaveMode::from($request->validated('mode'))); return response()->json($result->toArray()); }`
    - `catch (NoteSaveConflictException $e) { return response()->json(['reason' => $e->reason(), 'message' => $e->getMessage(), 'current_hash' => $e->currentHash()], 409); }`
    - `catch (NoteOperationException $e) { throw ValidationException::withMessages([$e->field() => $e->getMessage()]); }`
  - `routes/notes.php`: `Route::put('notes/{note:uuid}/content', NoteContentController::class)->whereUuid('note')->name('notes.content.update');`
  - `WorkspaceController`: change the `note` closure to
    `function () use ($note, $active, $notes) { if (! $note || ! $active) { return null; } $preview = $notes->preview($note); return [...$notes->present($note), ...$preview]; }`
    `preview()` must run **before** `present()`, so that `file_hash` and `file_size` reflect the reconciled disk state.
  - Regenerate Wayfinder.
  - Tests in `tests/Feature/Notes/NoteContentTest.php` (`make:test Notes/NoteContentTest --pest`; harness plus `open`):
    1. `putJson(route('notes.content.update', $uuid), ['content' => "# New\n", 'base_hash' => …, 'mode' => 'rich'])` → 200 with the keys `saved` (true), `file_hash`, `file_size`, `updated_at`; the disk is updated; no `id` or `vault_id` in the JSON.
    2. Unchanged → `saved` false.
    3. External edit → 409, `reason` `changed`, `current_hash` equals `hash_file`.
    4. File deleted → 409 `missing`.
    5. Validation dataset → 422 on the field: missing or malformed `base_hash`; `mode` `'html'`; content of 1 MiB + 1 byte.
    6. `content: ''` and `content: null` both save an empty body (the file becomes frontmatter only, or `''`).
    7. `failFileMoves([1, 2, 3])` → 422 `errors.content`.
    8. Unknown UUID → 404; `/notes/1/content` → 404; `GET` → 405.
    9. `GET notes.show` → `note.body`, `note.frontmatter`, `note.base_hash`, `note.editable` true, `note.read_only_reason` null. For a CRLF + BOM file, `note.content` has no `\r` and no BOM.
    10. After an external edit, `GET notes.show` gives `note.file_hash === note.base_hash ===` the disk hash (the ordering fix).
  - Update existing tests only where the shape changed (e.g. `NoteManagementTest` still asserts `note.content` `'Hello'`, which is unchanged for LF files).
  - Covers: FR-11, FR-12, FR-13, FR-15, FR-22

- [ ] **T8: Autosave engine `noteSaver`**
  - `resources/js/lib/editor/noteSaver.ts` has no Vue or Inertia imports:
    ```ts
    export const AUTOSAVE_DELAY_MS = 1500;
    export const AUTOSAVE_MAX_WAIT_MS = 10_000;
    export type SaveOutcome =
      | { kind: 'saved'; saved: boolean; fileHash: string; fileSize: number; updatedAt: string | null }
      | { kind: 'conflict'; reason: 'changed' | 'missing'; currentHash: string | null; message: string }
      | { kind: 'error'; message: string };
    export type NoteSaverStatus = 'clean' | 'dirty' | 'saving' | 'saved' | 'conflict' | 'error';
    export type NoteSaverState = { status: NoteSaverStatus; baseHash: string; lastSavedAt: Date | null; message: string | null; conflict: { reason: 'changed' | 'missing'; currentHash: string | null } | null };
    export type NoteSaver = {
      notifyChange(): void;
      flush(): Promise<'clean' | 'saved' | 'failed'>;
      overwrite(): Promise<'saved' | 'failed'>; // only after a 'changed' conflict: resend with baseHash = conflict.currentHash
      discard(): void;                           // mark clean and stop timers (Reload / Discard and leave)
      isDirty(): boolean;                        // pending change, in-flight save, or an unsaved failure
      getState(): NoteSaverState;
      dispose(): void;
    };
    export function createNoteSaver(options: { baseHash: string; baseline: string; readContent: () => string; send: (content: string, baseHash: string) => Promise<SaveOutcome>; onState?: (state: NoteSaverState) => void; delayMs?: number; maxWaitMs?: number }): NoteSaver;
    ```
    Rules:
    - **`notifyChange`**: sets pending and status `dirty`, restarts the idle timer, and starts the max-wait timer if it isn't running. It does nothing but mark pending while status is `conflict`.
    - **Timers** call `flush()`.
    - **`flush`**:
      1. Clear the timers. If a save is in flight, await it first.
      2. `content = readContent()`. If it equals the baseline → pending false, status `clean` (or keep `saved`), return `'clean'`.
      3. Send.
         - On `saved`: baseline = content; baseHash = fileHash; lastSavedAt = now; status `saved`. If `notifyChange` was called during the flight, schedule again.
         - On `conflict` → status `conflict`, return `'failed'`.
         - On `error` → status `error`, return `'failed'`. Keep pending; the next `notifyChange` reschedules.
    - **Single flight**: never two sends at once.
  - Tests in `tests/js/editor/noteSaver.test.ts` (`vi.useFakeTimers()`):
    1. No send before 1500 ms; exactly one send after it.
    2. A change every 500 ms for 12 s → the first send at 10 s.
    3. Content equal to the baseline → no send; status `clean`.
    4. A change during the flight → a second send after the first resolves, with the first result's `fileHash` as its base.
    5. A conflict → status `conflict`; later changes don't send; `overwrite()` sends with `currentHash`.
    6. An error → status `error`; the next change sends again.
    7. `discard()` → `isDirty()` false; no send when the timers would have fired.
    8. `flush()` when clean → `'clean'`, no send.
  - Covers: FR-03, FR-09, FR-11

- [ ] **T9: Editor UI**
  - `resources/js/lib/editor/toolbarCommands.ts`:
    - `export type ToolbarCommandId = 'undo'|'redo'|'bold'|'italic'|'strike'|'code'|'paragraph'|'h1'|'h2'|'h3'|'bulletList'|'orderedList'|'taskList'|'blockquote'|'codeBlock'|'horizontalRule'|'clearFormatting'`
    - `export const toolbarCommands: Record<ToolbarCommandId, { label: string; shortcut: string | null; run(editor: Editor): boolean; isActive(editor: Editor): boolean; canRun(editor: Editor): boolean }>`
    - `clearFormatting` = `chain().focus().unsetAllMarks().clearNodes().run()`. Links go through `LinkDialog`.
    - Test file `tests/js/editor/toolbarCommands.test.ts`: for each command, a headless editor with the content `para text` selected (`setTextSelection`) → `run` → `getMarkdown()` equals the expected string (dataset), and matches no `/<[a-z][^>]*>/i`. The link case uses `setLink({ href: 'https://example.com' })` → `[para text](https://example.com)`, and `setLink({ href: 'javascript:alert(1)' })` creates no link.
  - `lib/editor/editorSession.ts`: `getMode(uuid)`, `setMode(uuid, mode)`, `hasAcceptedReformat(uuid)`, `acceptReformat(uuid)`, backed by module-level `Map`/`Set` (session only; nothing persisted).
  - `TiptapEditor.vue` (rework):
    - Props: `markdown: string`, `editable: boolean`, `preferences: EditorPreferences`.
    - Emits: `change`.
    - `useEditor({ extensions: markdownExtensions(), content: markdown, contentType: 'markdown', editable, onCreate: ({ editor }) => emit('ready', editor.getMarkdown()), onUpdate: () => emit('change') })`.
    - `defineExpose({ getMarkdown: () => editor.value?.getMarkdown() ?? '' })`.
    - Renders `EditorToolbar` (hidden when `! editable`) and `EditorContent`.
    - Keeps the existing preference classes.
    - Single root.
  - `EditorToolbar.vue`:
    - `role="toolbar" aria-label="Formatting"`, with groups separated by `Separator`.
    - Icons (`@lucide/vue`): `Undo2`, `Redo2`, `Bold`, `Italic`, `Strikethrough`, `Code`, `Pilcrow`, `Heading1`, `Heading2`, `Heading3`, `List`, `ListOrdered`, `ListTodo`, `Quote`, `SquareCode`, `Link`, `Minus`, `RemoveFormatting`.
    - Each is a `Button` (variant ghost, size icon) with `:aria-pressed`, `:disabled="! canRun"` and `:title="label + shortcut"`.
    - The Link button opens `LinkDialog`.
  - `LinkDialog.vue`:
    - `v-model:open`, prop `initialHref`.
    - A URL `Input`. Apply → `extendMarkRange('link').setLink({ href })`, or `unsetLink()` when empty. A Remove button.
    - Shows the message "That link type isn't allowed." if `setLink` returns false.
  - `SourceEditor.vue`:
    - `<textarea>` bound with `v-model`, `spellcheck="false"`, `class="font-mono …"`.
    - Font size and line height from preferences; `wrap="off"` plus `whitespace-pre` when `word_wrap === false`.
    - Emits `change`; exposes `getText()`.
  - `NoteConflictAlert.vue`:
    - Props: `conflict`, `message`. Emits `reload`, `overwrite`, `copy`, `reindex`.
    - `changed`: the buttons Reload from disk, then a confirm Dialog: "Discard your unsaved edits and load the version on disk?"; Keep my version, then a confirm: "Replace the file on disk with your version? The changes made outside MDVault will be lost."; and Copy my text.
    - `missing`: Copy my text and Re-index vault.
  - `UnsavedChangesDialog.vue`: `v-model:open`, `message`, emits `stay` and `discard`. Text: "Your latest changes couldn't be saved: {message}".
  - `NoteEditor.vue`:
    - Props: `note: NoteDetail`, `vaultUuid: string`, `preferences: EditorPreferences`, `runtime: 'desktop' | 'browser'`. Single root `<article>`.
    - Header: title, `relative_path`, a mode toggle (two `Button`s, "Rich text" / "Source"; Rich is disabled with a title giving the reasons when the assessment is `unsupported`), the status text (`aria-live="polite"`: Saved {time} / Unsaved changes / Saving… / Couldn't save / Conflict), and a Save button.
    - States carried over from `NoteViewer` (same copy): `missing` with Re-index; `too_large` ("larger than 1 MB … read-only; open it in another editor"); `unreadable`. `invalid_utf8` gets a read-only `<pre>` plus the existing UTF-8 alert.
    - Assessment: when `note.editable && note.body !== null`, compute `assessMarkdown(note.body, getMarkdownConverter())` once in `onMounted` (show "Checking formatting…" while it runs).
    - Initial mode: `unsupported` → `source`; otherwise `editorSession.getMode(uuid) ?? 'rich'`.
    - `reformat` and not accepted: the rich view is read-only, with an `Alert`: "Editing will rewrite this note's Markdown formatting (first change: line {n}: “{before}” → “{after}”). The content stays the same." plus the buttons "Edit in rich text (reformat on save)" (`acceptReformat` → editable) and "Edit as source".
    - `unsupported`: an `Alert`: "This note uses Markdown the rich-text editor can't preserve yet ({labels}). It's open in source mode so nothing is lost."
    - Frontmatter in rich mode: a `<details>` "Frontmatter (edit in source mode)" containing a `<pre>` with the text (interpolated, never HTML).
    - Saver: `createNoteSaver` with:
      - `baseHash: note.base_hash`;
      - `baseline`: the rich `ready` markdown, or `note.content` for source;
      - `readContent`: the rich `getMarkdown()`, or the source `getText()`;
      - `send`: through `useHttp` against `update.url(note.uuid)` from Wayfinder `@/routes/notes/content` (or `@/actions/App/Http/Controllers/NoteContentController`), with payload `{ content, base_hash, mode }`.
      - Map the responses to `SaveOutcome`: 200 → `saved`; 409 → `conflict` (from the JSON); 422 → `error` (the first message in `errors`); network → `error` "MDVault couldn't reach its local server. Your text is still here."
      - Check the `useHttp` API with `search-docs` before coding.
      - `change` events → `notifyChange()`. The Save button and Ctrl/Cmd+S (a `window` keydown listener with `preventDefault`, removed on unmount) → `flush()`.
      - The saver is disposed on unmount.
    - Mode switch:
      1. `await flush()`; if it fails, stay.
      2. `setMode`.
      3. If a save was written since mount → `router.reload({ only: ['note'] })`, so the remount picks the mode up from the session. Otherwise switch in place.
    - Conflict actions:
      - Reload → `discard()`, then `router.reload({ only: ['note'] })`;
      - Keep mine → `overwrite()`;
      - Copy → `navigator.clipboard.writeText(readContent())`, then a toast;
      - Re-index → `router.post(reindex.url(vaultUuid))`, which goes through the guard.
    - Footer: size, `SHA-256 {first 12}…`, and the latest hash after saves. Reuse `formatBytes` (moved from `NoteViewer` into this component).
    - Calls `useUnsavedChangesGuard({ isDirty: saver.isDirty, flush: saver.flush, discard: saver.discard, runtime })`.
  - `pages/Workspace.vue`:
    - Replace `NoteViewer` with `<NoteEditor v-if="note" :key="`${note.uuid}:${note.base_hash ?? note.state}`" :note :vault-uuid :preferences="editor" :runtime="status.runtime" />`.
    - Replace the no-vault `TiptapEditor` demo and `demoContent` with a muted empty state: "Open or create a vault to start writing." with a link to the Vaults page.
    - Delete `resources/js/components/notes/NoteViewer.vue`.
  - Types (`types/notes.ts`):
    - `NoteDetail` gains `body: string | null; frontmatter: string | null; base_hash: string | null; editable: boolean; read_only_reason: 'too_large' | 'invalid_utf8' | null`.
    - Add `NoteSaveResponse` and `NoteSaveConflictResponse`.
  - Presentation only: no path logic, no `v-html`, no filesystem.
  - Covers: FR-01, FR-02, FR-04, FR-05, FR-06, FR-07, FR-09, FR-11, FR-12, FR-15, FR-16, FR-22

- [ ] **T10: Unsaved-changes guard**
  - `resources/js/composables/useUnsavedChangesGuard.ts`:
    - Registers on mount and removes on unmount.
    - **`router.on('before', handler)`** (keep the returned unsubscribe):
      - If `bypass` is set, or `! isDirty()` → allow.
      - Otherwise `event.preventDefault()`, capture `event.detail.visit`, and run `flush()`:
        - `'saved'`/`'clean'` → replay with `router.visit(visit.url, { method: visit.method, data: visit.data, only: visit.only, except: visit.except, preserveState: visit.preserveState, preserveScroll: visit.preserveScroll, replace: visit.replace, headers: visit.headers })`;
        - `'failed'` → open `UnsavedChangesDialog` with the saver message. Stay → nothing. Discard → `discard()`, set `bypass` for one visit, replay.
      - Check the `PendingVisit` field names with `search-docs`.
    - **`beforeunload`**:
      - If `isDirty()` → `event.preventDefault(); event.returnValue = '';`, then `flush()`.
      - On success, when `runtime === 'desktop'`, call `window.close()`. In the browser the native prompt is shown and nothing more is needed.
      - On failure the editor's error or conflict banner stays visible.
    - The dialog instance is rendered by `NoteEditor` (the composable returns the reactive `dialog` state).
  - Covers: FR-17

- [ ] **T11: Editor settings copy**
  - `pages/settings/Editor.vue`: replace "Saved for a future editor update." with "Not used by the editor yet." Leave the setting and its validation unchanged (F7).
  - Covers: FR-16

- [ ] **T12: Quality gates and handover**
  - Run:
    - `php vendor/bin/pint --dirty --format agent`
    - `php artisan test --compact` (full suite)
    - `vendor/bin/phpstan analyse` (level 7; no new baseline entries)
    - `npm run test:js`
    - `npm run types:check`
    - `npm run build`
    - `npm run check`
    - `php artisan wayfinder:generate --with-form --no-interaction`
  - Greps. Each must give the stated result:
    - `rg -n "v-html" resources/js` → none.
    - `rg -n "unlink" app` → only `FileStorageService` (`deleteNewEmptyFile`, `discardTempFile`).
    - `rg -n "replaceFile\(" app` → the definition, plus only `NoteService::save`.
    - `rg -n "->move\(|rename\(" app/Services` → only `FileStorageService`, plus the `NoteService::rename` method name.
    - `rg -n "Underline|TextAlign|text-align" resources/js` → none, apart from `underline: false`.
    - `rg -n "openOnClick: false" resources/js/lib/markdown` → one match.
    - `rg -n "from '@/" resources/js/lib/markdown resources/js/lib/editor` → none.
    - `rg -n "content" database/migrations/*notes*` → none.
    - `rg -n "NoteViewer" resources/js` → none.
    - `rg -n "Co-Authored-By|Generated with" .` over the changed files → none.
  - `php artisan route:list --path=notes` shows `PUT notes/{note}/content`.
  - Record everything in `implementation.md`: the T2 spike findings and canonical style, every serializer override and its fixture, and any `ignoring()` entry with its reason.
  - **Manual desktop checks (user, `composer native:dev`)**:
    - **M1**: Open a note written in VS Code with headings, lists, a code block and a link → rich and editable, or a reformat notice that shows the first difference.
    - **M2**: Type, wait 2 s → VS Code or Notepad shows the change. The footer hash changes. Closing without typing leaves the file's modified time unchanged.
    - **M3**: Ctrl+S saves immediately. The status shows "Saved".
    - **M4**: Type in MDVault without saving, edit the same file in VS Code, then Ctrl+S in MDVault → the conflict banner appears. Test Reload (VS Code's text appears) and Keep my version (MDVault's text wins after confirming).
    - **M5**: A note with a table opens in Source mode. Edit one line and save → VS Code's diff shows only that line.
    - **M6**: A Notepad file with CRLF and a BOM, edited in both modes → still CRLF and UTF-8 with BOM (VS Code status bar).
    - **M7**: A frontmatter note edited in rich mode → the frontmatter is byte-identical.
    - **M8**: Type, then immediately click another note → the first is saved. Type, then close the window → it saves, then closes.
    - **M9**: Make a file read-only in Explorer → a read-only message; the text is kept. Remove the read-only flag → the save works.
    - **M10**: A note of about 1.2 MB → read-only notice. A note of about 900 KB → it opens within about 1 s.
    - **M11**: The Move dialog preselects the current folder.
    - **M12**: Each toolbar button, then close and reopen the note → the formatting is preserved (§53 "Reloading preserves formatting").
    - **M13**: Type `C++ and C++`, save, reopen → the text is unchanged.
    - **M14**: Frontmatter note, Rich → Source, type one character → frontmatter byte-identical.
    - **M15**: A reformat note, "Edit as source", type one character → the external diff shows only that character.
    - **M16**: Click another note while typing → no input is accepted after the click, and everything typed before it is saved.
    - **M17**: A note without frontmatter → "Add frontmatter" → type `tags: [a]` → wait 2 s. VS Code shows `---
tags: [a]
---

` at the top, and the body is byte-identical.
    - **M18**: A CRLF + BOM note with frontmatter → change one YAML value in the Rich panel. The file is still CRLF + BOM, and only that line differs. Then "Remove frontmatter" → the block is gone and the body is unchanged.
    - **M19**: Type a `---` line in the panel. An inline hint appears, the save is refused with the frontmatter message, and the file is unchanged. Delete the line and the save goes through. Also, on a `reformat` note that hasn't been accepted, the panel is read-only.
    - **M20**: With fresh settings, create the note "Plan #1". VS Code shows `title: "Plan #1"`, today's local `created`, and the commented `tags`/`aliases`. It opens in Rich mode with the panel filled and the body empty. Type a body → the frontmatter is byte-identical, followed by a blank line and then the body.
    - **M21**: In Settings → Editor, edit the template to add `status: draft`, save, and create a note → the new note has it. Turn the toggle off → the next new note is 0 bytes. Existing notes' modified times are unchanged throughout. A `---` line in the template is refused with the message.
  - Covers: all FRs (verification)

---

## 4. Test Plan
| Test File | Scenario | Covers |
|---|---|---|
| `tests/js/markdown/spike.test.ts` | Headless round trip of the core set (T2 gate) | FR-18 |
| `tests/js/markdown/roundTrip.test.ts` | Fixtures: `exact` (byte-for-byte, idempotent), `reformat` (expected output, idempotent, same doc), `unsupported` (reasons), extension list has no underline/alignment, `openOnClick` false | FR-01, FR-02, FR-04, FR-18 |
| `tests/js/markdown/assess.test.ts` | Check order, `firstDifference`, unstable detection | FR-02, FR-03 |
| `tests/js/editor/toolbarCommands.test.ts` | Each command → expected Markdown, no HTML; link protocol rejection | FR-04, FR-05 |
| `tests/js/editor/noteSaver.test.ts` | Debounce, max wait, no-op, single flight, hash chaining, conflict and overwrite, error, discard | FR-03, FR-09, FR-11 |
| `tests/Feature/Services/MarkdownServiceTest.php` | BOM, EOL, frontmatter, identity property, compose policies, invalid UTF-8 | FR-07, FR-08, FR-15 |
| `tests/Feature/Services/FileStorageServiceTest.php` | `replaceFile` success and every failure path (the target always intact, no temp left), retry, perms, `isWritableFile` | FR-10, FR-13, FR-19 |
| `tests/Feature/Services/NoteServiceTest.php` | `save` in both modes, envelope, no-op, conflicts, race, lock, short write, read-only, size, not editable, DB failure, chaining; extended `preview` | FR-03, FR-07, FR-08, FR-10 to FR-15 |
| `tests/Feature/Services/VaultIndexServiceTest.php` | `folder` on note nodes; `.mdvault-save-*` ignored | FR-19, FR-20 |
| `tests/Feature/Notes/NoteContentTest.php` | HTTP 200/409/422/404/405, empty content, no ids, `notes.show` new props, base-hash ordering fix | FR-11 to FR-13, FR-15, FR-22 |
| `tests/Unit/ArchitectureTest.php` | `MarkdownService` has no filesystem; `unlink`/`file_put_contents`/`fsync` only in `FileStorageService` | FR-19 |
| (static) greps, `types:check`, `build`, `check` | Boundaries, no `v-html`, no underline/alignment, relative imports | FR-19, FR-22 |
| (manual) M1–M13 | Desktop behaviour, conflicts, envelope, guard, performance | FR-01 to FR-17, FR-20 |

**Test scope for QA**:
- Targeted first:
  ```
  php artisan test --compact tests/Feature/Services/MarkdownServiceTest.php tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/NoteServiceTest.php tests/Feature/Services/VaultIndexServiceTest.php tests/Feature/Notes tests/Feature/WorkspaceTest.php tests/Unit/ArchitectureTest.php
  npm run test:js
  ```
- Then the **full suite** `php artisan test --compact`. It is required because `tests/Pest.php`, `NoteService`'s constructor, `WorkspaceController` and the routes all change.
- `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run build`, `npm run check`, and the T12 greps.
- Code review:
  - `replaceFile` is the only replacing call, and it is guarded by the base hash;
  - no write without an edit (the baseline comes from `onCreate`/loaded text; the server no-op path);
  - on every failure the original file is intact and no temp is left;
  - a DB failure never compensates the file;
  - no `content` column or content persistence;
  - no path or filesystem logic in Vue;
  - no `v-html`; `openOnClick: false`;
  - single-root components;
  - no AI attribution anywhere.
- M1–M13 are **user-verified manual checks**, not defects.

---

## 5. Risks & Mitigations
- **Risk**: `@tiptap/markdown` is an early release and may have fidelity gaps. **Mitigation**: the T2 go/no-go spike; the fidelity check (a note is never rewritten unless the round trip is exact, or the user consents and the document is provably unchanged); Source mode; fixtures; the fallback `tiptap-markdown@0.9.0`.
- **Risk**: many external notes classify as `reformat`, which adds friction. **Mitigation**: one click per note per session; tune the serializer style towards common conventions in `extensions.ts`, with fixtures.
- **Risk**: the headless editor needs a DOM in Vitest. **Mitigation**: contingent `happy-dom` (F10b).
- **Risk**: Windows locks, antivirus or OneDrive make the rename fail. **Mitigation**: 3 attempts, 100 ms apart; a clear "close other programs" message; the original is intact; the text stays in the editor.
- **Risk**: a time-of-check/time-of-use gap between the guard and the rename. **Mitigation**: accepted (§44); the post-replace hash check reports a conflict; the Phase 5 watcher.
- **Risk**: a crash between the temp write and the rename leaves `.mdvault-save-*` orphans. **Mitigation**: dot-prefixed, so the indexer ignores them; they hold the user's text; the ADR follow-up adds cleanup or recovery UI in Phase 5.
- **Risk**: replacing the file changes its inode and ACL inheritance and breaks hard links. **Mitigation**: documented in the ADR; permissions are copied; symlinks are refused; Windows tunnelling keeps the creation time.
- **Risk**: Electron cancels the window close silently. **Mitigation**: save, then `window.close()`, then the error banner (M8).
- **Risk**: a stale base hash from prop ordering. **Mitigation**: `preview()` runs before `present()`, an explicit `base_hash`, and test T7-10.
- **Risk**: the sonnet developer uses `file_put_contents` on the target, or adds a `force` flag. **Mitigation**: the ADR, the arch rule, the greps, and a code-review item.

---

## 6. Open Questions (user approvals; recommended answers in bold)
- [x] **F1: Markdown conversion location and library.** Options:
  - (a) client-side, official `@tiptap/markdown@^3.31.3` (lexer `marked` 17; same version train as the installed Tiptap 3.31.3), plus a server `MarkdownService` for BOM, line endings, frontmatter and the trailing newline;
  - (b) client-side, community `tiptap-markdown@0.9.0` (markdown-it + prosemirror-markdown; peer `@tiptap/core ^3.0.1`);
  - (c) server-side league/commonmark (Markdown → HTML) plus an HTML → Markdown converter (lossy).

  **Recommended: (a).** (b) is the fallback if the T2 spike fails.
- [x] **F2: Round-trip fidelity strategy.** **Recommended:**
  - a fidelity check on open: `exact` → edit in rich text; `reformat` (same document, different syntax) → read-only until "Edit in rich text (reformat on save)", or Source mode; `unsupported` (tables, images, HTML, reference links, footnotes, wiki links, unstable) → Source mode only;
  - never write unless the content changed;
  - frontmatter, BOM, line endings and the trailing newline are preserved on the server.
- [x] **F3: Underline and alignment.** Options:
  - (a) omit both;
  - (b) Tiptap's `++underline++` (non-standard, and corrupts "C++ and C++");
  - (c) HTML `<u>` / `<p align>` passthrough (such notes would then be "unsupported" by F2).

  **Recommended: (a)**, per §20 "only features with a reliable Markdown representation".
- [x] **F4: Save strategy.** **Recommended:**
  - autosave 1.5 s after the last change, at most every 10 s while typing, not configurable in Phase 4;
  - Ctrl/Cmd+S and a Save button;
  - an atomic temp-then-replace with verification, re-hash and DB update (new ADR);
  - on a lock, disk full, read-only or unreadable file: the file is untouched, a clear message, and the text stays in the editor;
  - on a DB failure after a successful write: keep the file, report, and reconcile on the next open.
- [x] **F5: Stale-write protection UX (before Phase 5).** **Recommended:**
  - the base hash is checked before and immediately before the replace;
  - on a mismatch: a banner with Reload from disk (confirm) / Keep my version (confirm, overwrites) / Copy my text;
  - if the file is missing: Copy my text / Re-index (no file is recreated);
  - Compare/diff is left to Phase 5.
- [x] **F6: Large and invalid files.** **Recommended:**
  - notes over 1 MiB stay read-only (the Phase 3 cap);
  - invalid UTF-8 notes are read-only (editing would destroy bytes);
  - the server refuses saves whose file would exceed 1 MiB.
- [x] **F7: Editor preferences.** **Recommended:**
  - font family, size, line height and word wrap apply to both Rich and Source modes;
  - "Show line numbers" stays stored but unused, with the hint "Not used by the editor yet." (no rich-text meaning; a textarea gutter is deferred).
- [x] **F8: Unsaved-changes guard.** **Recommended:**
  - any in-app navigation (note or vault switch, close vault, tree actions, re-index) saves first, then continues;
  - if the save fails, a Stay / Discard-and-continue dialog;
  - desktop window close saves, then closes (it stays open with the error if the save fails);
  - the browser shows its native prompt.
- [x] **F9: The note viewer.** **Recommended:**
  - replace `NoteViewer.vue` with `NoteEditor.vue` (Rich/Source toggle; the missing, too-large and unreadable states and the footer are carried over);
  - replace the no-vault demo editor with an empty state.
- [x] **F10: JS test tooling.** **Recommended:**
  - (a) Vitest through the already-installed Vite+ (`vp test`, Vitest 4.1.11), with no new test runner;
  - a new folder `tests/js/` (tests plus `.md` fixtures);
  - a script `"test:js": "vp test run"`;
  - a `test` block in `vite.config.ts`.
  - **(b) Contingent:** add `happy-dom` as a dev dependency **only if** the T2 spike proves the headless editor needs a DOM.
- [x] **F11: New dependencies and folders.** **Recommended: approve:**
  - `@tiptap/markdown@^3.31.3`;
  - `@tiptap/extension-list@^3.31.3` (already installed as a transitive dependency; declared for TaskList/TaskItem);
  - `marked@^17.0.1` (already pulled in by `@tiptap/markdown`; declared because the fidelity check calls its lexer);
  - the new folders `tests/js/`, `resources/js/lib/markdown/`, `resources/js/lib/editor/`.

  No composer changes. No migration.

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-09-29 | Initial plan | — |
| 2 | 2026-09-29 | Level 4 analyst review | Fix round 2: A1 (in-place mode switch re-baselines from props), A2 (serializeEditor on all serialization paths), R2-01 (editor frozen during guarded navigation), R2-02 (dispose stops the flush loop; no iteration cap); M14–M16 added; ADR addendum. |
| 3 | 2026-09-29 | User request: editable frontmatter in Rich mode ("Raw YAML box") | Save contract gains `has_frontmatter`/`frontmatter` (FrontmatterEdit); `MarkdownDocument` exposes the frontmatter parts; `composeRich` keep/replace/add/remove with a `---` guard and a stability self-check; `invalidFrontmatter`; `preview` adds `frontmatter_yaml`; `FrontmatterPanel.vue` and `richContent.ts`; the saver's content encodes frontmatter + body; FR-23; M17–M19; ADR addenda. |
| 4 | 2026-09-29 | User request: frontmatter template for new notes | SettingKey `editor.new_note_template_enabled` (default true) and `editor.new_note_template` (inner YAML, default in `MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE`); Editor settings section with Reset; `MarkdownService::renderNewNoteTemplate` (JSON-quoted `{{title}}`, local `{{date}}` from the client timezone) and `assertValidFrontmatterYaml`; `NoteService::create` writes the template in the exclusive create; `FileStorageService::deleteNewFileWithContents` (exact-bytes compensation) and short-write handling in `createFile`; Phase 3 tests pin template-off; FR-24; M20–M21; E4 and ADR amendments. |

R4-01 (analyst review): composeRich reuses an existing block's separator when the body is empty; blank-line separator only dropped for new blocks.
