# Phase 4 Revision 3: editable frontmatter in Rich mode (developer spec)

**Routing:** Level 4, change to the save path. The flow is `senior-developer` → `senior-qa`, then a **short analyst diff review** limited to `MarkdownService`, `NoteService::save`, `SaveNoteContentRequest` and `richContent.ts`. This change touches the byte-preservation invariant, so it needs that review. No full re-review. If QA passes and the diff matches this spec, I will sign off from the diff alone.

## 1. Save contract (server)

**Request** (`app/Http/Requests/Notes/SaveNoteContentRequest.php`), two new fields. Rich saves always send both. When the fields are absent, the current frontmatter is kept, which is today's behaviour.
- `has_frontmatter`: `['sometimes', 'boolean', 'prohibited_unless:mode,rich']`
- `frontmatter`: `['nullable', 'string']`
- `ConvertEmptyStringsToNull` turns `''` into `null`, so map `null` → `''`.
- New accessor `frontmatterEdit(): ?FrontmatterEdit`:
  - returns `null` when `has_frontmatter` is absent;
  - otherwise returns `new FrontmatterEdit((bool) has_frontmatter, (string) (frontmatter ?? ''))`.

**New value object** `app/Support/FrontmatterEdit.php` (`php artisan make:class Support/FrontmatterEdit --no-interaction`):
```php
final readonly class FrontmatterEdit { public function __construct(public bool $present, public string $yaml) {} }
```

**`MarkdownDocument`** gains these fields. They are all LF strings, and when there is no frontmatter the first four are `''` and `frontmatterYaml` is `null`.
- `frontmatterOpen`: the opening delimiter line, e.g. `"---  \n"`;
- `frontmatterInner`: the lines between the delimiters, verbatim, each ending in `"\n"`, or `''`;
- `frontmatterClose`: the closing delimiter line, e.g. `"---\n"`, or `"---"` at EOF;
- `frontmatterSeparator`: the blank lines after the block;
- `?string $frontmatterYaml`: the display value, equal to `frontmatterInner` with exactly one trailing `"\n"` removed.

The existing `frontmatter` stays equal to the concatenation of open, inner, close and separator.

**`MarkdownService::decode`**: same regex, split into capture groups. The matching behaviour must not change:
`/\A(---[ \t]*\n)((?:.*?\n)?)(---[ \t]*(?:\n|\z))((?:[ \t]*\n)*)/s`

**`MarkdownService::composeRich(MarkdownDocument $current, string $body, ?FrontmatterEdit $edit = null): string`**
1. Normalise the body exactly as today: CRLF → LF, then `rtrim` newlines.
2. Choose the block:
   - **`$edit === null` → keep.** Use `$current->frontmatter ?? ''` (today's path).
   - **`! $edit->present` → remove.** Block = `''`.
   - **`$edit->present` and `$yaml === $current->frontmatterYaml` (after CRLF → LF) → keep.** Use the current block byte for byte. This guarantees an unchanged panel never changes bytes.
   - **Otherwise → replace or add.**
     - `$yaml = str_replace("\r\n", "\n", $edit->yaml)`.
     - If any line matches `/^---[ \t]*$/m` → throw `NoteOperationException::invalidFrontmatter()`.
     - `$inner = $yaml === '' ? '' : $yaml."\n"`.
     - Delimiters:
       - if the current file has frontmatter: reuse `frontmatterOpen`, `frontmatterClose` and `frontmatterSeparator`;
       - otherwise: `"---\n"`, `"---\n"`, and separator `"\n"`.
     - If the body is non-empty and the close line doesn't end with `"\n"`, append `"\n"` to it.
     - If the body is `''`, use separator `''` (new blocks only).
     - Block = `open.inner.close.separator`.
3. `$source = $block.$body`, followed by today's trailing-newline rule.
4. **Stability self-check** (replace/add only):
   - `$check = $this->decode($source)`;
   - it must hold that `$check->frontmatterYaml === rtrimOneNewline($inner)` and `$check->body === substr($source, strlen($block))`;
   - otherwise throw `invalidFrontmatter()`. This is defence in depth.

`encode()` is unchanged, so the file's line endings and BOM are re-applied as today.

**`NoteOperationException::invalidFrontmatter()`**, field `frontmatter`, message:
"The frontmatter can't contain a line of three dashes (---), because that would end it early and change the note. Remove that line and try again. Nothing on disk was changed."

**`NoteService::save(..., NoteSaveMode $mode, ?FrontmatterEdit $frontmatter = null)`**: pass the edit to `composeRich`. It is ignored for Source mode. Everything else is unchanged: base hash, size cap, the no-op check, `replaceFile`.

**`NoteContentController`**: pass `$request->frontmatterEdit()`.

**`NoteService::preview()`**: add `frontmatter_yaml` (`?string`) to the `ok` shape and its PHPDoc (`null` for the other states).

**Known pre-existing edge, documented and not fixed.** A rich body that starts with `---` ¶ text ¶ `---` (two horizontal rules) is read back as frontmatter on the next open. The bytes are unchanged; only how the note is split changes. Removing frontmatter from such a note has the same effect.

## 2. Client

- **`resources/js/types/notes.ts`**: `NoteDetail` gains `frontmatter_yaml: string | null`.
- **New pure module `resources/js/lib/editor/richContent.ts`** (relative imports only):
  ```ts
  export type RichContent = { frontmatter: string | null; body: string };
  export function encodeRichContent(c: RichContent): string;   // JSON.stringify([c.frontmatter, c.body]) (deterministic)
  export function decodeRichContent(s: string): RichContent;
  export function richSavePayload(c: RichContent): { content: string; has_frontmatter: boolean; frontmatter: string };
  export function richContentAsText(c: RichContent): string;    // clipboard only: '---\n' + fm + ('\n' unless fm === '' ) + '---\n\n' + body; no fm → body
  export function frontmatterProblem(yaml: string): string | null; // same /^---[ \t]*$/m rule → inline hint text, else null
  ```
- **New component `resources/js/components/editor/FrontmatterPanel.vue`**. It has a single root.
  - Props: `v-model: string | null` and `readonly: boolean`. Emits `change`.
  - When the value is `null`: a ghost "Add frontmatter" button that sets the value to `''`, expands the panel and emits `change`.
  - Otherwise: a Collapsible titled "Frontmatter", open by default when the value is non-empty. Inside:
    - a monospace `<textarea>` with `spellcheck="false"` and `:readonly`, emitting `change` on input;
    - an `InputError`-style hint from `frontmatterProblem()`;
    - a "Remove frontmatter" button with a confirmation Dialog ("Remove the frontmatter block from this note?"). It sets the value to `null` and emits `change`.
  - All buttons are disabled when `readonly`.
  - No YAML parsing and no `v-html`.
- **`NoteEditor.vue`**:
  - Add `const frontmatterState = ref<string | null>(props.note.frontmatter_yaml)`.
  - Replace the read-only frontmatter `<details>` with `<FrontmatterPanel v-model="frontmatterState" :readonly="frozen || richReadOnly" @change="notifyChange" />` inside the Rich branch.
  - The panel is read-only while `richReadOnly`. A frontmatter save sends the rich body too, and that would write an unaccepted reformat.
  - Rich `readContent()` returns `encodeRichContent({ frontmatter: frontmatterState.value, body: tiptapRef.value?.getMarkdown() ?? '' })`.
  - `onEditorReady(markdown)` creates the saver with baseline `encodeRichContent({ frontmatter: frontmatterState.value, body: markdown })`.
  - With content and baseline encoded this way, dirty tracking, autosave, the flush loop, the guard, `overwrite` and `discard` all work unchanged.
  - `send(content, baseHash)`:
    - Rich: payload `{ ...richSavePayload(decodeRichContent(content)), base_hash, mode: 'rich' }`;
    - Source: unchanged.
    - `mapSaveResult` is unchanged; a 422 on `frontmatter` is shown as the error message.
  - `conflictCopy()` in Rich mode copies `richContentAsText(decodeRichContent(readContent()))`.
- **Mode switching** (confirming A1):
  - The in-place branch still runs only after `flush()` returned `'clean'` with `writtenSinceMount === false`, so the props still equal the disk.
  - Rich → Source: keep `sourceText.value = props.note.content`. The pristine-props rule holds, because a clean flush means `frontmatterState` equals `props.note.frontmatter_yaml`.
  - Source → Rich: set `frontmatterState.value = props.note.frontmatter_yaml` before `mode.value = 'rich'`, so the new saver's baseline is built from pristine props.
  - When something was written: keep the existing `router.reload({ only: ['note'] })`. The remount then reads the composed file from disk. **No client-side composition for saving.**
- **Fidelity check**: unchanged. It assesses `note.body` only. Frontmatter is split off on the server, so editing it can never change the classification.

## 3. Tests

**Pest, `tests/Feature/Services/MarkdownServiceTest.php`:**
1. `decode` parts for `---  \ntitle: X\n---\n\n\n# H\n`: open `"---  \n"`, inner `"title: X\n"`, close `"---\n"`, separator `"\n\n"`, yaml `"title: X"`.
2. An empty block `---\n---\n` gives yaml `''`. The degenerate `---\n\n---\n` gives yaml `''`; `composeRich` with an edit of `''` keeps its bytes exactly (the keep path).
3. Replace `title: Y` keeps the original open line, close line and separator byte for byte.
4. Add to `# H\n` gives `---\na: 1\n---\n\n# H\n`. Add to an empty note with an empty body gives `---\na: 1\n---\n`.
5. Remove gives the body only, with the trailing-newline rule applied.
6. Replacing when the close line is at EOF (a frontmatter-only file) plus a new body appends `"\n"` to the close line.
7. CRLF + BOM: `encode(composeRich(replace), crlf, true)` starts with the BOM, has no bare `\n`, and changes only the edited line.
8. The yaml datasets `"a\n---\nb"` and `"--- "` throw `invalidFrontmatter`. `"a: ...\n..."` is allowed. CRLF input is normalised.
9. Stability dataset: `decode(composeRich(...))->frontmatterYaml === yaml` and the body is unchanged.
10. `$edit === null` keeps today's behaviour. The existing tests must stay green.

**Pest, `tests/Feature/Services/NoteServiceTest.php`:**
- Rich saves that add, change and remove frontmatter: check the file bytes, the DB hash, and that `preview()` gives the same `frontmatter_yaml` and body on the next open.
- A CRLF + BOM note with a frontmatter change is preserved.
- An invalid `---` line throws field `frontmatter`; the file and DB are unchanged.
- An unchanged frontmatter plus an unchanged body gives `written` false, with `failFileMoves` showing zero calls.

**Pest, `tests/Feature/Notes/NoteContentTest.php`:**
- `has_frontmatter` together with mode `source` → 422.
- `has_frontmatter: true` with `frontmatter: ''` writes an empty block.
- An invalid frontmatter → 422 `errors.frontmatter`.
- `notes.show` exposes `note.frontmatter_yaml`.

**Vitest, new `tests/js/editor/richContent.test.ts`:**
- `encodeRichContent`/`decodeRichContent` round-trip for `null`, `''`, and values with trailing newlines and unicode.
- `richSavePayload` mapping for `null` and a string.
- `richContentAsText` for `null`, `''` and `a: 1`.
- `frontmatterProblem` cases.

**QA scope:**
- `npm run test:js`
- `php artisan test --compact tests/Feature/Services/MarkdownServiceTest.php tests/Feature/Services/NoteServiceTest.php tests/Feature/Notes`
- then the full suite, `phpstan`, `types:check`, `check` and `build`.

## 4. Manual checks (add to plan T12)
- **M17**: A note without frontmatter → "Add frontmatter" → type `tags: [a]` → wait 2 s. VS Code shows `---\ntags: [a]\n---\n\n` at the top, and the body is byte-identical.
- **M18**: A CRLF + BOM note with frontmatter → change one YAML value in the Rich panel. The file is still CRLF + BOM, and only that line differs. Then "Remove frontmatter" → the block is gone and the body is unchanged.
- **M19**: Type a `---` line in the panel. An inline hint appears, the save is refused with the frontmatter message, and the file is unchanged. Delete the line and the save goes through. Also, on a `reformat` note that hasn't been accepted, the panel is read-only.

## 5. Artifact edits (line-level)

`.ai/decisions/markdown-conversion-and-fidelity.md`: append a bullet to the end of the `## Addendum (Phase 4 delivery, 2026-09-29)` list:
```
- **Frontmatter editing (Revision 3)**: Rich mode shows the leading YAML block as a raw plain-text panel (add, edit, remove). It is never parsed or reformatted, and it is excluded from the fidelity check, which assesses the body only. The panel is read-only while a `reformat` note has not been accepted.
```

`.ai/decisions/note-save-atomic-replace.md`, `## Decision`, first bullet (**Endpoint**): after `{content, base_hash, mode: rich|source}` insert:
` plus, for Rich saves, `has_frontmatter` and `frontmatter` (raw YAML between the delimiters). An absent field keeps the current block. A value equal to the current YAML keeps its bytes exactly. Otherwise the block is replaced with the existing delimiter lines and separator (new blocks use `---`), or it is removed. A `---` line inside is refused, and a decode self-check confirms the frontmatter/body split is stable`

`.ai/features/active/phase-4-tiptap-editor/requirements.md`, §4 table, after the FR-22 row:
```
| **FR-23** | Editable frontmatter (Revision 3) | Rich mode shows an expandable raw-YAML panel with Add, Remove and edit actions. It is saved byte-for-byte between the delimiters, keeping line endings, BOM, delimiter lines and separator. A `---` line inside is refused. It is excluded from the fidelity check. | Given `title: X` changed to `title: Y` in the panel, When saved, Then only that line differs on disk and the next open shows the same frontmatter and body. |
```

`.ai/features/active/phase-4-tiptap-editor/plan.md`:
- Status line → `- **Status**: IN PROGRESS (Revision 3: editable frontmatter in Rich mode; Revision 2 signed off)`
- T12 manual list: add M17–M19 as in §4 above.
- §7 Revision Log, after the Revision 2 row:
```
| 3 | 2026-09-29 | User request: editable frontmatter in Rich mode ("Raw YAML box") | Save contract gains `has_frontmatter`/`frontmatter` (FrontmatterEdit); `MarkdownDocument` exposes the frontmatter parts; `composeRich` keep/replace/add/remove with a `---` guard and a stability self-check; `invalidFrontmatter`; `preview` adds `frontmatter_yaml`; `FrontmatterPanel.vue` and `richContent.ts`; the saver's content encodes frontmatter + body; FR-23; M17–M19; ADR addenda. |
```
- After the analyst diff review passes, set the status back to `COMPLETE (…; Revision 3 signed off 2026-09-29)`.

No new dependencies, folders or migrations. No AI attribution in any artifact, commit or comment.
