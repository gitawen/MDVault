# QA Report: Phase 4: Tiptap Editor, Round 4

## Metadata
- **Feature Name**: Phase 4: Tiptap Editor
- **Feature ID**: mdv-p4
- **Author**: Senior QA Engineer
- **QA Round**: 4
- **Date**: 2026-09-29
- **Scope**: three post-sign-off changes — a Level 2 responsive-toolbar UI request, Revision 3 (editable frontmatter in Rich mode), and Revision 4 (new-note frontmatter template)
- **Baseline**: same branch `phase-4-tiptap-editor`, everything still uncommitted
- **Verdict**: **PASS**

> PASS requires zero open Critical/High or MAJOR issues. One new Low-severity, non-blocking observation is opened (R4-01).

---

## 1. Responsive Toolbar (Level 2)

Reviewed `EditorToolbar.vue`, `Workspace.vue`, `NoteEditor.vue` (header/footer), and the container chain (`TiptapEditor.vue`, `Workspace.vue`'s `<main>`).

- **No overlap**: the toolbar gained `shrink-0` (so wrapping onto multiple rows no longer compresses its own height — the actual root cause named in the request) and is `sticky top-0 z-10 bg-card` inside the editor's own scroll area, sitting above `EditorContent`, not over it.
- **Every command reachable at every width**: the "always visible" set (undo, redo, bold, italic, H1–H3, bullet/ordered list, link, plus the "More formatting" trigger) is never hidden. The overflow set (paragraph, strike, inline code, task list, blockquote, code block, horizontal rule, clear formatting) is `hidden md:inline-flex` inline and duplicated into a `DropdownMenu` whose trigger is `md:hidden` — so above `md` the inline row is the only way to reach them (dropdown trigger hidden) and below `md` the dropdown is the only way (inline row hidden). No command is unreachable at any width.
- **`aria-pressed`/`disabled`/`title` preserved**: confirmed on every inline button, including the ones now also duplicated into the dropdown. **`aria-checked` in the dropdown**: uses `DropdownMenuCheckboxItem` with `:checked="isActive(id)"`, the idiomatic reka-ui/Radix pattern for `role="menuitemcheckbox"` + `aria-checked`, consistent with how this primitive is already used elsewhere in the app.
- **No script or save-logic changes**: `EditorToolbar.vue`'s script only adds a presentation-only icon map and the overflow id list; `toolbarCommands.ts`, `noteSaver.ts`, `send()`/`readContent()` are untouched. Confirmed by diff and by the fact `toolbarCommands.test.ts` and `noteSaver.test.ts` needed no changes.
- **No horizontal scroll at 360 px, reasoned from the classes**: the toolbar row is `flex flex-wrap` (not `nowrap`), so overflow becomes vertical wrapping, not horizontal scroll. `Workspace.vue`'s `<main>` (the editor's flex ancestor) already carries `min-w-0` + `overflow-auto` (pre-existing), which is the load-bearing class that stops any wide descendant from forcing the page wider — any residual overflow would be contained as an internal scroll region inside `<main>`, not a page-level horizontal scrollbar. `NoteEditor.vue`'s header now wraps the title+path into their own `min-w-0 flex-1` sub-row (with `truncate` on the path span) so a long `relative_path` can't force the row wide; the button group is `flex-wrap`. The footer's two spans are `shrink-0` inside a `flex-wrap` container. `Workspace.vue`'s vault-path span gained `min-w-0 flex-1 truncate` for the same reason. I did not render this in a browser (none available here); this is a class-level review as requested. Recommend the user visually confirm on a real 360 px viewport as part of manual testing.
- **Verification**: `npm run types:check`/`check`/`build` clean; `npm run test:js` unaffected (presentation-only, no new unit-testable logic); `php artisan test --compact tests/Feature/WorkspaceTest.php` unaffected (no prop shape changed) — reconfirmed as part of this round's full-suite run (§5).

No issues found in this area.

---

## 2. Revision 3: Editable Frontmatter in Rich Mode

Verified `revision-3-frontmatter-spec.md` §1–§3 against the code line by line.

### Server
- **`MarkdownDocument`**: gained `frontmatterOpen`/`Inner`/`Close`/`Separator` (default `''`) and `?frontmatterYaml` (default `null`) — matches the spec exactly, including the "all `''`/`null` when there's no frontmatter" invariant.
- **`decode`**: the regex is the spec's regex verbatim, split into the four named capture groups; `frontmatterYaml` is `frontmatterInner` with exactly one trailing `\n` stripped (`rtrimOneNewline`). `frontmatter` still equals the concatenation of the four parts.
- **`composeRich(current, body, ?FrontmatterEdit $edit)`**: traced every branch against §1.2's decision tree —
  - `$edit === null` → keep (`$current->frontmatter ?? ''`) — identical to pre-Revision-3 behaviour, confirmed the 5 pre-existing manual `MarkdownDocument` construction sites in the test file still compile and pass unchanged.
  - `! $edit->present` → remove (`''`).
  - `$edit->present` and the (CRLF-normalised) YAML equals `$current->frontmatterYaml` → keep the current block byte-for-byte. This is the guarantee that an untouched panel never changes bytes — verified by a dedicated test with a degenerate blank-inner block.
  - Otherwise → replace/add: the `---`-line guard (`assertValidFrontmatterYaml`) runs before anything else; delimiters are reused verbatim when frontmatter already exists, or `"---\n"`/`"---\n"`/`"\n"` for a brand-new block; a non-empty body gets a trailing `\n` appended to the close line if it's missing (covers the "frontmatter-only file, close line at EOF" case, tested); a `''` body forces `$separator = ''`.
  - **Stability self-check** (replace/add only): re-decodes the composed source and asserts `frontmatterYaml`/`body` match what was intended, throwing `invalidFrontmatter()` otherwise — matches §1's "defence in depth" wording exactly.
- **`encode()` is unchanged** — confirmed no diff to this method; CRLF/BOM preservation for a frontmatter edit is exercised end-to-end by a dedicated CRLF+BOM test (`composeRich` → `encode`, asserting the result starts with the BOM, contains no bare `\n`, and changes only the edited line).
- **`NoteOperationException::invalidFrontmatter()`**: field `frontmatter`, message matches the spec verbatim.
- **`SaveNoteContentRequest`**: `has_frontmatter` (`sometimes|boolean|prohibited_unless:mode,rich`) and `frontmatter` (`nullable|string`) added exactly as specified; `frontmatterEdit()` correctly maps "absent" → `null` (via `$this->has('has_frontmatter')`, not `validated()`, so it distinguishes "field never sent" from "field sent as `false`") and re-maps `ConvertEmptyStringsToNull`'s `null` back to `''`.
- **`preview()`/`previewResult()`**: `frontmatter_yaml` added to both the `ok` shape and the null-filled other-states shape, and to both PHPDoc annotations.
- **`NoteService::save()`**: gained the fifth `?FrontmatterEdit $frontmatter` parameter, passed only into `composeRich` for Rich mode; `NoteContentController` passes `$request->frontmatterEdit()` through.

### Client
- **`richContent.ts`**: `encodeRichContent`/`decodeRichContent` (`JSON.stringify`/`parse` of a fixed-order `[frontmatter, body]` tuple — deterministic by construction, since a tuple has no key-ordering ambiguity, unlike an object); `richSavePayload`; `richContentAsText` (clipboard-only, matches the spec's exact string template); `frontmatterProblem` (same `/^---[ \t]*$/m` rule as the server, used purely as an inline hint). All relative imports, no framework dependency. 14 Vitest cases confirmed solid, including round-trips with unicode/trailing newlines and a determinism check.
- **`FrontmatterPanel.vue`**: single root `<div>`, no YAML parsing, no `v-html`; "Add frontmatter" sets `''` and opens the panel; the textarea/remove button are `:disabled`/`:readonly` on `readonly`; remove goes through a confirmation dialog. Matches spec.
- **`NoteEditor.vue`**: `frontmatterState` initialised from `props.note.frontmatter_yaml`. Rich `readContent()` and the saver's `onEditorReady` baseline both go through `encodeRichContent({frontmatter: frontmatterState.value, body: ...})` — confirmed the saver's dirty tracking, flush loop, guard, `overwrite`/`discard` all operate on this combined string unchanged, since none of `noteSaver.ts` needed to change (verified no diff to `noteSaver.ts` for this revision). `send()` builds the Rich payload via `richSavePayload(decodeRichContent(content))` (a 422 on `frontmatter` flows through `mapSaveResult` unchanged, shown as the error message — confirmed by an `errors.frontmatter` HTTP test). `conflictCopy()` uses `richContentAsText(decodeRichContent(readContent()))` in Rich mode, `readContent()` unchanged in Source mode.
- **Panel read-only condition**: `:readonly="frozen || richReadOnly"` — exactly the required combination.
- **Mode switching keeps the A1 pristine-props rule**: the in-place branch still runs only after `flush()` returns non-`'failed'` with `writtenSinceMount === false`. Rich→Source keeps `sourceText.value = props.note.content` (frontmatter included, since `note.content` is the whole pristine file). Source→Rich sets `frontmatterState.value = props.note.frontmatter_yaml` *before* `mode.value = 'rich'`, so the freshly-remounted `TiptapEditor`'s `ready` event builds the new saver's baseline from pristine props, never from whatever Source last held. `saver?.dispose(); saver = null;` still runs unconditionally first, exactly as A1 required.
- **No client-side composition for saving**: confirmed — the client only ever sends `{content: body, has_frontmatter, frontmatter}` separately; the actual byte-level frontmatter+body merge happens exclusively in `MarkdownService::composeRich` on the server. `richContentAsText` (the one place that *does* concatenate them) is explicitly clipboard-only and never sent to the server.
- **Conflict copy works**: traced `conflictCopy()` → `richContentAsText(decodeRichContent(readContent()))` for Rich mode, producing a full-file-shaped preview text a user can paste elsewhere after a conflict.
- **Fidelity check unchanged**: still assesses `note.body` only; frontmatter editing can never change the `exact`/`reformat`/`unsupported` classification, matching §2's explicit statement.

No issues found in this area beyond R4-01 (below, Low, non-blocking).

---

## 3. Revision 4: New-Note Frontmatter Template

Verified `revision-4-new-note-template-spec.md` §1–§5 against the code.

- **`createFile`**: now checks `fwrite`'s return against `strlen($contents)`; on a short write, `ftruncate($handle, 0)` inside the existing `try`/`finally` (so `fclose` always runs) then `deleteNewEmptyFile($path)`, returning `false` — a partial file is never left behind. **Stays exclusive**: still `fopen($path, 'x')`, unchanged — never overwrites.
- **`deleteNewFileWithContents(path, contents)`**: unlinks only when `is_file && !is_link && filesize === strlen($contents)` **and** `hash_equals($contents, $actual-bytes-read)` — matches the spec's four-part guard exactly, including the symlink refusal (tested, `->skipOnWindows()`, see §5). **`deleteNewEmptyFile`** is now `return $this->deleteNewFileWithContents($path, '')` — its 0-byte case, exactly as specified.
- **`NoteService::create`**: gained the fourth `?string $timezone` parameter; steps 1–5 unchanged; the template is rendered *before* the exclusive create and written in the single `createFile()` call (no second write); `$hash`/`$size` are read from disk (`hashFile()`/`size()`, falling back to the in-memory value only if the disk read races); a DB-insert failure's `catch` compensates via `deleteNewFileWithContents($absolute, $source)` (not the old 0-byte-only `deleteNewEmptyFile`) and rethrows — verified by the spec's own "content appended mid-flight" compensation-guard test, which proves a file that no longer matches `$source` byte-for-byte survives.
- **`renderNewNoteTemplate`**: returns `''` for an empty template; `{{title}}` → `json_encode($title, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)` via the spec's exact regex (`/(["\']) \{\{\s*title\s*\}\}\1|\{\{\s*title\s*\}\}/`, quote-consuming so an already-quoted placeholder is replaced, not doubled — confirmed by a dedicated test with an embedded `"`); `{{date}}` → `$date->format('Y-m-d')`, tolerating internal whitespace; any other `{{...}}` left untouched; both substitutions use `preg_replace_callback` (not `preg_replace` with a replacement string), correctly avoiding `$`/`\`-in-replacement corruption for titles containing those characters (a real risk `preg_replace` would have had); wraps the result as `"---\n{$rendered}\n---\n\n"`; self-checks via `assertValidFrontmatterYaml()` (defence in depth, matches spec §3's explicit call-out). The dangerous-title dataset (`C#`, `it's`, `-dash`, `[x]`, `yes`, `123`, `Café`) is tested against `json_encode()` directly, and the documented example (`title: "Meeting"`, `created: 2026-09-29`, commented tags/aliases) is reproduced byte-for-byte.
- **`assertValidFrontmatterYaml`**: correctly extracted as a shared public method, used by `composeRich`, `renderNewNoteTemplate`, and the settings request's closure (each per spec).
- **Settings validation**: `new_note_template_enabled` (`required|boolean`); `new_note_template` (`nullable|string|max:4000|required_if_accepted:new_note_template_enabled` + the closure) in exactly the spec's rule order; the closure normalises CRLF→LF, rejects a NUL byte with the exact message, and reuses `assertValidFrontmatterYaml()` for the `---` rule, converting the caught exception into the exact specified validation message; the `required_if_accepted` message matches verbatim. `EditorController::update` stores the *normalised* value (CRLF→LF, trailing newlines trimmed) and only touches the setting key when the submitted value isn't `null` — correctly implementing "empty + disabled leaves the stored template unchanged" per the settings-persistence "`null` = revert to default" rule (confirmed by a dedicated test: set a non-default template, submit empty+disabled, assert the stored value is untouched).
- **Timezone**: `CreateNoteDialog.vue` → `StoreNoteRequest` (`nullable|string|timezone:all`) → `NoteController::store` → `NoteService::create` → `CarbonImmutable::now($timezone ?? config('app.timezone'))`, traced end-to-end; a near-midnight-UTC/`Pacific/Kiritimati` test proves the client's local date wins.
- **Existing notes never touched**: confirmed by a dedicated test (create an unrelated new note, assert an existing note's hash and mtime are unchanged) and by code inspection — `create()` never reads or writes any file other than the one it's creating.
- **Phase 3 tests pinned, not deleted**: `NoteServiceTest.php`'s `'create makes an empty file...'` test is renamed with a `(template off)` suffix and explicitly sets `EditorNewNoteTemplateEnabled` to `false` first, since the feature default is now "on". Confirmed this is the only place such pinning was needed (no equivalent empty-file assertion existed in `NoteManagementTest.php` to pin).
- **Interaction with Revision 3**: `assessMarkdown('')` giving `{status: 'exact'}` (needed for a freshly templated note's empty body to open in Rich mode) is now an explicit `assess.test.ts` case, confirming the converter's pre-existing blank-input special case already covers it with no code change needed.

**Deviation assessed**: the `{{title}}`/`{{date}}` hint text hoisted to script constants (`titlePlaceholder`/`datePlaceholder`) because Vue's template compiler parses the first `}}` inside `{{ '{{title}}' }}` as closing the *outer* mustache, producing a genuine build error (`Unterminated string constant`) — this is a correct, standard, well-known Vue workaround for embedding literal double-brace text; the rendered page text is unaffected. **Accepted.**

No issues found in this area beyond R4-01 (below, Low, non-blocking).

---

## 4. New Skip and `SettingsServiceTest` Change

- **New skip identified**: `tests/Feature/Services/FileStorageServiceTest.php`'s `'deleteNewFileWithContents refuses a symlink even with matching bytes'`, marked `->skipOnWindows()`. This is legitimate: it is one of Revision 4's own new tests (per the spec's own test list: "a symlink target is refused even with matching bytes (`skipOnWindows`)"), and it follows the exact same, already-established pattern as 7 of the other 8 pre-existing skips in this suite (symlink-creation tests that can't reliably run without elevated privileges on Windows). Confirmed by reading the test: it creates a real symlink via `symlink()` and asserts the compensation method refuses it — correctly exercising the new symlink guard on platforms where it can run.
- **`SettingsServiceTest`'s exact-shape assertion**: the `'group returns every field...'` test's expected array now includes `'new_note_template_enabled' => true` and `'new_note_template' => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE'`, matching the two new `SettingKey` cases and their declared defaults exactly. **Confirmed correct.**

---

## 5. Test Execution

| Command | Result |
|---|---|
| `php artisan test --compact` (full suite) | `535 tests, 526 passed, 1482 assertions, 9 skipped` (8 pre-existing + 1 new legitimate symlink skip, see §4) |
| `npm run test:js` | `7 test files, 89 tests, all passed` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | clean, no output |
| `npm run check` | "All 84 files are correctly formatted" / "no warnings or lint errors in 77 files" |
| `npm run build` | succeeds (same pre-existing chunk-size warning only) |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `getMarkdown` grep (`rg -n "\.getMarkdown\(\)" resources/js`) | 2 matches, both inside `serializeEditor` in `extensions.ts` |
| T12 greps | `v-html` none; `unlink` — 2 sites, both in `FileStorageService` (`deleteNewFileWithContents` — the new site, and `discardTempFile`), confirmed by reading both call sites; `replaceFile(` only defined + used in `NoteService::save`; `->move(\|rename(` only `FileStorageService`/the `NoteService`/`VaultService` `rename()` method names; `Underline\|TextAlign` only in a doc comment; `openOnClick: false` one match; no `@/` imports in `lib/markdown`/`lib/editor`; no `content` in note migrations; no `NoteViewer` |
| AI-attribution grep (`Co-Authored-By\|Generated with\|Claude\|Anthropic`, case-insensitive, over `resources/js`, `app`, `tests`, and the feature folder) | zero hits in any source/test file; the only hits are this feature's own QA/planning documents quoting the "no AI attribution" rule |

All commands pass; no regressions.

---

## 6. New Issues (Round 4)

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| R4-01 | Low | MINOR (follow-up, not blocking) | `app/Services/MarkdownService.php::composeRich()` | The spec's §1.2 replace/add step "If the body is `''`, use separator `''`" is annotated "(new blocks only)" in the spec's prose, but the code applies it unconditionally, including when *reusing* an existing frontmatter block's original delimiters/separator. No test exercises the specific combination of an *existing* frontmatter block (with a real, possibly multi-blank-line separator) + a YAML replace-edit + a body that becomes empty in the same save. The code matches the spec's literal, un-gated algorithm step, so this is not a spec violation — more a gap between the spec's parenthetical and its literal wording, plus a missing test for a narrow combination. | Add a `composeRich` test for "existing frontmatter, replaced YAML, body now empty" to confirm and lock in the intended behaviour (whether the original separator should be zeroed or preserved in that combination), and adjust the spec's parenthetical or the code to match, whichever is intended. | 0 |

No Critical, High, or MAJOR issues found in any of the three changes.

---

## 7. Manual Checks (User-Verified, Not Defects)

- **M1–M13**: carried over from earlier rounds (VS Code round-trips, autosave/Ctrl+S, conflicts, CRLF/BOM, frontmatter byte-identity, navigation/close saves, read-only files, size limits, Move-dialog preselection, toolbar formatting, `C++ and C++`).
- **M14–M16** (round 3): Rich→Source frontmatter byte-identity; a `reformat` note's "Edit as source" produces a one-line diff; clicking another note while typing accepts no further input after the click.
- **M17–M19** (Revision 3, new this round): a note without frontmatter gets one added via the panel and the body stays byte-identical; a CRLF+BOM note's frontmatter edit and later removal each touch only what they should; a `---` line in the panel is refused with an inline hint and the file is unchanged, and the panel is read-only on an unaccepted `reformat` note.
- **M20–M21** (Revision 4, new this round): a fresh note picks up the rendered template (quoted title, local date, commented tags/aliases) and opens in Rich mode with the panel filled and an empty body; editing the template in Settings and creating a note reflects the change, turning the toggle off makes the next note 0 bytes, existing notes' mtimes are unaffected throughout, and a `---` line in the template is refused.

These are the user's own checks per the plan's T12, not counted as defects here.

---

## 8. Routing Recommendation

- [x] **PASS** — no Critical/High/MAJOR issues found across the responsive toolbar, Revision 3, or Revision 4.
- [ ] FAIL — MINOR/MODERATE → Senior Developer
- [ ] FAIL — MAJOR → System Analyst

Optional, non-blocking follow-up for the Developer if there's appetite: R4-01 (Low/MINOR).
