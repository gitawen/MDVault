# Phase 4 Revision 4: frontmatter template for new notes (developer spec)

**Routing:** `senior-developer` (after Revision 3) → `senior-qa`, then **one combined analyst diff review of Revisions 3 and 4**. Revision 4 needs the review because it changes the create path's compensation rule, which is ADR territory. The review is limited to:
- `FileStorageService::createFile`, `FileStorageService::deleteNewFileWithContents`
- `MarkdownService` (the Revision 3 compose logic plus `renderNewNoteTemplate`)
- `NoteService::create`, `NoteService::save`
- the settings request

No full re-review.

## 1. Settings

**`app/Enums/SettingKey.php`**, two new cases, both in the `Editor` group:
- `EditorNewNoteTemplateEnabled = 'editor.new_note_template_enabled'`: type `Boolean`, default `true`.
- `EditorNewNoteTemplate = 'editor.new_note_template'`: type `String`, default `MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE`. This follows the existing `StoragePathService::FOLDER_NAME` pattern.

**Storage:** only the **inner YAML** is stored, LF, with no delimiters and no trailing newline. This matches the Revision 3 panel. The delimiters are added when a note is created.

**The default template** (`MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE`) is exactly:
```php
public const DEFAULT_NEW_NOTE_TEMPLATE = "title: {{title}}\ncreated: {{date}}\n# tags: []\n# aliases: []";
```

**`UpdateEditorSettingsRequest`**, add:
- `new_note_template_enabled`: `['required', 'boolean']`
- `new_note_template`:
  - `['nullable', 'string', 'max:4000', 'required_if_accepted:new_note_template_enabled', closure]`
  - The closure normalises CRLF → LF, then rejects:
    - NUL, with the message "The template contains an invalid character.";
    - any line matching `/^---[ \t]*$/m`, with the message "The template can't contain a line of three dashes (---). MDVault adds the --- lines around it for you."
  - The closure must reuse `MarkdownService::assertValidFrontmatterYaml()` (see §3).
  - Message for `required_if_accepted`: "Enter a template, or turn the template off."

**Why an empty template doesn't mean "no frontmatter":** the `settings-persistence` ADR defines `set(key, null)` as "revert to default", and Laravel converts `''` to `null`. An empty field would therefore silently bring the default back. The toggle is the off switch. An empty field while the template is enabled is rejected. An empty field while it is disabled leaves the stored template unchanged.

**`EditorController::update`**: add these to `setMany`:
- `EditorNewNoteTemplateEnabled => $request->boolean('new_note_template_enabled')`
- `EditorNewNoteTemplate => rtrim(str_replace("\r\n", "\n", $t), "\n")`, only when `$t = $request->validated('new_note_template')` is not null.

**`EditorController::edit`**: add the prop `defaultNewNoteTemplate => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE`.

**`resources/js/types/settings.ts`**: `EditorPreferences` gains `new_note_template_enabled: boolean` and `new_note_template: string`. They arrive through `group(Editor)`, so the Workspace `editor` prop also carries them. That is harmless.

**`resources/js/pages/settings/Editor.vue`**, a new section "New notes":
- a Checkbox "Add frontmatter to new notes";
- a monospace `<textarea>` bound to `form.new_note_template` (disabled when the checkbox is off), with `InputError`;
- the hint: "Written between --- lines at the top of notes MDVault creates. {{title}} becomes the note's name (quoted for you, so don't add quotes). {{date}} becomes today's date (YYYY-MM-DD). Existing notes are never changed.";
- a ghost button "Reset to default" that sets the textarea to `defaultNewNoteTemplate`.

## 2. Placeholders

`MarkdownService::renderNewNoteTemplate(string $template, string $title, CarbonInterface $date): string` does pure string work. It returns the **full file source**, or `''` when `$template === ''`:
1. `{{title}}` is replaced by a YAML double-quoted scalar: `json_encode($title, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)`. A JSON string is always a valid YAML 1.2 double-quoted scalar. This makes `#`, `'`, a leading `-`, `[`, `&`, `!`, `yes`, `null` and `123` all safe.
   - Regex: `/(["'])\{\{\s*title\s*\}\}\1|\{\{\s*title\s*\}\}/`. An already-quoted placeholder has its quotes replaced, not doubled.
2. `{{date}}` is replaced by `$date->format('Y-m-d')`, unquoted (a YAML date, the Obsidian convention). Regex: `/\{\{\s*date\s*\}\}/`.
3. Any other `{{…}}` is left unchanged. No other placeholders exist.
4. Result: `"---\n".$rendered."\n---\n\n"`. This is LF, with no BOM, and a blank-line separator. The body is empty.

**Timezone:** the user's local date is used.
- `CreateNoteDialog.vue` sends `timezone: Intl.DateTimeFormat().resolvedOptions().timeZone`.
- `StoreNoteRequest` adds `timezone`: `['nullable', 'string', 'timezone:all']`.
- `NoteService` uses `CarbonImmutable::now($timezone ?? config('app.timezone'))`.
- `app.timezone` is `UTC`. That fallback only happens if the browser doesn't send a timezone.

**Example:** the note "Meeting", created on 2026-09-29, gets this file:
```
---
title: "Meeting"
created: 2026-09-29
# tags: []
# aliases: []
---

```

## 3. Create path

**Recommendation:** write the content in the exclusive create, and compensate only when the file still holds exactly those bytes.

The rejected alternative was: create empty → DB insert → `replaceFile`. It adds a second write and a second DB update that can each fail, and it can leave a note without its template.

**`FileStorageService::createFile($path, $contents)`** (the existing signature):
- Check that `fwrite` returned `strlen($contents)`.
- On a short write: `ftruncate($handle, 0)`, `fclose`, then `deleteNewEmptyFile($path)`, and return `false`. A partial file is never left behind.

**New method `FileStorageService::deleteNewFileWithContents(string $path, string $contents): bool`** (compensation only):
- It unlinks only when all of the following hold:
  - `is_file` and `! is_link`;
  - `filesize === strlen($contents)`;
  - the bytes read equal `$contents` (`hash_equals`).
- Deleting a file whose bytes equal what this call generated loses nothing.
- `deleteNewEmptyFile` becomes `return $this->deleteNewFileWithContents($path, '');`.

**`MarkdownService::assertValidFrontmatterYaml(string $yaml): void`**: extract the Revision 3 `---`-line rule into this public method. It throws `NoteOperationException::invalidFrontmatter()`. It is used by `composeRich`, by `renderNewNoteTemplate` (defence in depth), and by the settings closure (which catches the exception and calls `$fail` with the settings message).

**`NoteService`:**
- Inject `SettingsService`.
- New signature: `create(Vault $vault, ?string $folder, string $name, ?string $timezone = null)`.
- Steps 1–5 (validation, folder, conflict checks) are unchanged.
- Step 6: `$source = $settings->boolean(EditorNewNoteTemplateEnabled) ? $markdown->renderNewNoteTemplate((string) $settings->string(EditorNewNoteTemplate), $stem, CarbonImmutable::now(...)) : ''`.
- Step 7: `! createFile($abs, $source)` → `createFailed`.
- Step 8: `$hash = hashFile($abs) ?? hashString($source)` and `$size = size($abs) ?? strlen($source)`. These are the bytes on disk, which is the truth.
- Step 9: DB insert inside `try`. The `catch` calls `deleteNewFileWithContents($abs, $source)` and rethrows.

`NoteController::store` passes `$request->validated('timezone')`.

**Invariants:**
- Never overwrite: `fopen('x')` is unchanged.
- LF, no BOM.
- Existing notes are never read or modified by this feature.

## 4. Interaction with Revision 3

- After creation, the redirect to `notes.show` runs `preview()`. It returns `frontmatter_yaml` equal to the rendered inner YAML, and `body` equal to `''`.
- The fidelity check on an empty body gives `exact`. This is already covered: the converter special-cases blank input, and the `exact/empty.md` fixture is 0 bytes.
- So the note opens in Rich mode, with the panel showing the template and an empty editor.
- On the first body save, the unchanged panel takes the Revision 3 **keep** path. The block, including its `"\n"` separator, stays byte-identical. The body is followed by `"\n"`, because the file ended with a newline.
- Add one Vitest case to `assess.test.ts`: `assessMarkdown('')` gives `{ status: 'exact' }`.

## 5. Tests (Pest)

**Existing Phase 3 tests that assert empty new files** (NoteServiceTest create #1 and the empty-hash `e3b0…` checks, and NoteManagementTest):
- add `app(SettingsService::class)->set(SettingKey::EditorNewNoteTemplateEnabled, false)` in their setup;
- **don't delete them**; they now cover "template off".

**`tests/Feature/Services/MarkdownServiceTest.php`:**
- `renderNewNoteTemplate` produces the exact example output above for the default template.
- Title escaping dataset: `C#`, `it's`, `-dash`, `[x]`, `yes`, `123`, `Café`. Each result's `title:` line equals `title: ` followed by `json_encode(title)`, and none contains a raw unquoted value.
- A quoted placeholder `title: "{{title}}"` produces single quoting.
- `{{ date }}` with spaces works. `{{unknown}}` is left unchanged.
- An empty template gives `''`.
- A `---` line throws.

**`tests/Feature/Services/NoteServiceTest.php`:**
- Template on (the default): the file equals the rendered source, the DB `file_hash` equals `hash_file`, and `file_size` equals `filesize`.
- `preview()` gives `frontmatter_yaml` and `body ''`.
- Template off: 0 bytes (the existing behaviour).
- `Carbon::setTestNow` plus the timezone `Pacific/Kiritimati` near midnight UTC gives the local date.
- DB failure (`Note::creating` throws) removes the new file and leaves `Note::count() === 0`.
- Compensation guard: in the same failure, have `onPut`/a `creating` listener append bytes to the file first. The file must survive. This proves only an unchanged new file is ever deleted.
- An existing note in the vault is byte-identical before and after `create()`.

**`tests/Feature/Services/FileStorageServiceTest.php`:**
- `deleteNewFileWithContents` deletes on an exact match, and refuses a different size, different bytes, or a symlink.
- `createFile` with contents writes those exact bytes and still refuses an existing file.

**`tests/Feature/Settings/EditorSettingsTest.php`:**
- The defaults appear in the `preferences` prop (enabled `true`, the default template).
- A patch saves the toggle and the template (CRLF normalised, trailing newlines trimmed).
- A `---` line → `assertSessionHasErrors('new_note_template')`.
- Enabled with an empty template → an error. Disabled with an empty template → saved, and the stored template is unchanged.
- More than 4000 characters → an error.
- The `defaultNewNoteTemplate` prop is present.

**`tests/Feature/Notes/NoteManagementTest.php`:** `store` with `timezone: 'Not/AZone'` → `assertSessionHasErrors('timezone')`. A valid store writes the template.

**QA scope:**
- `php artisan test --compact tests/Feature/Services tests/Feature/Notes tests/Feature/Settings`
- `npm run test:js`
- the full suite, `phpstan`, `types:check`, `check`, `build`

## 6. Manual checks (plan T12)
- **M20**: Fresh settings, New note "Plan: Q4"… that name is invalid on Windows, so use "Plan #1". VS Code shows `title: "Plan #1"`, today's local `created`, and the commented `tags`/`aliases`. It opens in Rich mode with the panel filled and the body empty. Type a body → the frontmatter is byte-identical, followed by a blank line and then the body.
- **M21**: In Settings → Editor, edit the template to add `status: draft`, save, and create a note → the new note has it. Turn the toggle off → the next new note is 0 bytes. Existing notes' modified times are unchanged throughout. A `---` line in the template is refused with the message.

## 7. Artifact edits (line-level)

`.ai/decisions/note-file-operations.md`:
- In **Options Considered → Create order (b)**, replace "on a DB failure, remove the file only if it is still 0 bytes" with "on a DB failure, remove the file only if its bytes still exactly equal what this call wrote (0 bytes, or the new-note template since Phase 4 Revision 4)".
- In **Decision → Compensation**, replace "It either renames back, or unlinks a 0-byte file created by the same call." with "It either renames back, or unlinks a file created by the same call whose bytes still exactly equal what that call wrote (`deleteNewFileWithContents`; `deleteNewEmptyFile` is its 0-byte case)."
- Append to **Follow-ups**: `- Phase 4 Revision 4 (delivered): new notes may start with a frontmatter template (setting \`editor.new_note_template*\`); E4's "new notes are created as empty files" now applies only when the template is off.`

`.ai/features/completed/phase-3-markdown-files/plan.md`, §6 E4: after "new notes are created as empty files." append ` (Amended in Phase 4 Revision 4: when the new-note template setting is on, which is the default, new notes start with a rendered frontmatter block; existing notes are never touched.)`

`.ai/decisions/settings-persistence.md`, **Follow-ups**: append `- Phase 4 Revision 4 adds \`editor.new_note_template_enabled\` (boolean, default true) and \`editor.new_note_template\` (string, default in code). An empty template is refused while enabled, because \`null\` means "revert to default".`

`.ai/features/active/phase-4-tiptap-editor/requirements.md`, §4 table, after the FR-23 row:
```
| **FR-24** | New-note frontmatter template (Revision 4) | Settings → Editor has an on/off toggle (default on) and an inner-YAML template (default: quoted title, local `Y-m-d` created date, commented tags/aliases). `{{title}}` is inserted as a JSON-quoted YAML scalar and `{{date}}` as the local date; unknown placeholders are left unchanged. It is applied only when MDVault creates a note (LF, no BOM, `---` delimiters plus a blank line), with an exclusive create, the DB hash equal to the file, and compensation that deletes only an unchanged new file. Existing notes are never touched. It amends Phase 3 E4. | Given the template is on, When the note "Plan #1" is created, Then the file is `---\ntitle: "Plan #1"\ncreated: <today>\n# tags: []\n# aliases: []\n---\n\n`, and the note opens in Rich mode with the panel filled and an empty body. |
```

`.ai/features/active/phase-4-tiptap-editor/plan.md`:
- Status line → `- **Status**: IN PROGRESS (Revisions 3–4: editable frontmatter; new-note template)`
- T12 manual list: add M20 and M21 as in §6.
- §7 Revision Log, after the Revision 3 row:
```
| 4 | 2026-09-29 | User request: frontmatter template for new notes | SettingKey `editor.new_note_template_enabled` (default true) and `editor.new_note_template` (inner YAML, default in `MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE`); Editor settings section with Reset; `MarkdownService::renderNewNoteTemplate` (JSON-quoted `{{title}}`, local `{{date}}` from the client timezone) and `assertValidFrontmatterYaml`; `NoteService::create` writes the template in the exclusive create; `FileStorageService::deleteNewFileWithContents` (exact-bytes compensation) and short-write handling in `createFile`; Phase 3 tests pin template-off; FR-24; M20–M21; E4 and ADR amendments. |
```
- After the combined analyst diff review passes, set the status to `COMPLETE (…; Revisions 3–4 signed off <date>)`.

No new dependencies, folders or migrations. No AI attribution in any artifact, commit or comment.
