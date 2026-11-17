# Plan: Image Attachment & Shared Media Storage

## Metadata
- **Feature Name**: Image Attachment & Shared Media Storage
- **Feature ID**: feat-media-attachments
- **Author**: System Analyst
- **Created Date**: 2026-11-17
- **Task Complexity**: Level 3 — Complex Development
- **Requirements**: `requirements.md`
- **Status**: APPROVED

---

## 1. Summary
Add end-to-end support for inserting images and screenshots into Markdown notes via a new rich-text toolbar Image button (`Ctrl+Alt+I`), an interactive `ImageDialog.vue` (file upload, clipboard paste, drag-and-drop, external URL, and edit-at-cursor), direct screenshot paste (`Ctrl+V`/`Cmd+V`), and editor drag-and-drop. Attached raster images (`png`, `jpg`, `gif`, `webp`) are validated server-side, written exclusively via `FileStorageService` into `<MDVault root>/Media/` with filenames prefixed by the sanitized vault name (`<vault>-<stem>-<Ymd-His>-<8hex>.<ext>`), and served safely inline from `GET /media/{filename}`. Vault creation/registration/rename guards prevent collisions with the `Media` folder, and encrypted vaults explicitly refuse unencrypted media uploads.

---

## 2. Architecture & Design
- **Approach**: Filesystem-backed shared media directory resolved via `StoragePathService::mediaDirectory()` (`<rootPath>/Media`), managed by a dedicated `MediaService` delegating all disk I/O to `FileStorageService`, exposed via two endpoints (`POST /vaults/{vault:uuid}/media` and `GET /media/{filename}`), and consumed on the frontend through a shared `mediaUpload.ts` transport + `ImageDialog.vue` + CodeMirror paste/drop/keymap extensions.
- **Alternatives Considered**:
  - Per-vault `assets/` folders — rejected because the requirement specifies a shared media folder inside the MDVault root folder with vault-name-prefixed filenames.
  - SQLite `media` table — rejected because Markdown + filesystem are MDVault's source of truth and database resets must not break image references.
  - Allowing SVG or plaintext uploads in encrypted vaults — rejected for security (stored XSS via SVG; plaintext leakage violating ADR `encrypted-vault-storage-layout`).
- **Decision Records**: `.ai/decisions/media-attachment-storage.md`

### Data Model Changes
| Table | Change | Columns / Indexes / Constraints |
|---|---|---|
| None | No schema changes | Media files are purely filesystem-backed in `<rootPath>/Media/` |

### Backend Components
| Type | Path | Responsibility |
|---|---|---|
| Service (Modify) | `app/Services/StoragePathService.php` | Add `MEDIA_FOLDER_NAME = 'Media'` and `mediaDirectory(): string` (canonicalizing existing root) |
| Service (Modify) | `app/Services/VaultService.php` | Prevent creating, registering, or renaming a vault at/overlapping `mediaDirectory()` |
| Exception (Modify) | `app/Exceptions/VaultOperationException.php` | Add `reservedForMedia(string $field)` factory |
| Exception (Create) | `app/Exceptions/MediaOperationException.php` | Domain exception for media upload/storage failures (`vaultUnavailable`, `encryptedVaultUnsupported`, `mediaFolderUnavailable`, `mediaFolderConflict`, `invalidImage`, `writeFailed`) |
| Service (Create) | `app/Services/MediaService.php` | Validates raster MIME/dimensions, generates vault-prefixed safe filenames, creates `Media/` lazily, writes via `FileStorageService::createFileFromStream()`, and resolves files safely for serving |
| Form Request (Create) | `app/Http/Requests/Media/StoreVaultMediaRequest.php` | Validates `image` (`required`, `file`, `mimes:png,jpg,jpeg,gif,webp`, `max:10240`) |
| Controller (Create) | `app/Http/Controllers/MediaController.php` | `store(StoreVaultMediaRequest, Vault, MediaService): JsonResponse` and `show(string $filename, MediaService): BinaryFileResponse` |
| Provider (Modify) | `app/Providers/NativeAppServiceProvider.php` | Configure `phpIni()` with `upload_max_filesize => '12M'` and `post_max_size => '16M'` |
| Routes (Create/Modify) | `routes/media.php`, `routes/web.php` | Register `vaults.media.store` and `media.show` routes |

### Frontend Components
| Type | Path | Responsibility |
|---|---|---|
| Types (Modify) | `resources/js/types/notes.ts` | Add `MediaUploadResponse` type |
| Helper (Create) | `resources/js/lib/editor/mediaUpload.ts` | Client-side pre-validation (MIME/ext/10 MB), Wayfinder + `useHttp` upload, and error mapping (`mapMediaUploadResult`) |
| Helper (Modify) | `resources/js/lib/editor/codemirrorCommands.ts` | Add `insertImage()`, `imageAt()`, and `isImageActive()` pure CM6 helpers |
| Keymap (Modify) | `resources/js/lib/editor/codemirrorKeymap.ts` | Add `Mod-Alt-i` shortcut calling `onImage` |
| Editor Setup (Modify) | `resources/js/lib/editor/codemirror.ts` | Wire `onImage`, `onPasteImage`, and `onDropImages` via `EditorView.domEventHandlers` |
| Component (Create) | `resources/js/components/editor/ImageDialog.vue` | Modal dialog for uploading/pasting/dropping local images, entering external URLs, editing alt/src at cursor, and live previewing selected images |
| Component (Modify) | `resources/js/components/editor/EditorToolbar.vue` | Add Image button (`ImageIcon`, `Ctrl+Alt+I`), `openImageDialog()`, and `ImageDialog` integration |
| Component (Modify) | `resources/js/components/editor/MarkdownEditor.vue` | Pass `vaultUuid` and `vaultEncrypted` to toolbar, handle direct CodeMirror paste/drop image uploads with unmount/note-switch guards |
| Component (Modify) | `resources/js/components/editor/NoteEditor.vue` | Accept optional `vaultEncrypted` prop and pass `vaultUuid` + `vaultEncrypted` to `MarkdownEditor` |
| Page (Modify) | `resources/js/pages/Workspace.vue` | Pass `:vault-encrypted="currentVault.is_encrypted"` to `NoteEditor` |

### Routes
| Method | URI | Name | Controller@action | Middleware |
|---|---|---|---|---|
| `POST` | `/vaults/{vault:uuid}/media` | `vaults.media.store` | `MediaController@store` | `web` |
| `GET` | `/media/{filename}` | `media.show` | `MediaController@show` | `web` |

*(Route parameter constraints: `whereUuid('vault')` on `vaults.media.store`; `where('filename', '[A-Za-z0-9][A-Za-z0-9._-]*')` on `media.show`.)*

---

## 3. Implementation Tasks
*Ordered so each task leaves the application in a working state.*

- [ ] **T1 — Add `mediaDirectory()` to `StoragePathService` & protect it in `VaultService`**
  - Files: `app/Services/StoragePathService.php`, `app/Exceptions/VaultOperationException.php`, `app/Services/VaultService.php`
  - Details:
    - In `StoragePathService`: add `public const MEDIA_FOLDER_NAME = 'Media';` and `public function mediaDirectory(): string`, returning `<canonical rootPath()>/Media` without creating it on disk.
    - In `VaultOperationException`: add `public static function reservedForMedia(string $field): self` with user-facing message `"That folder name or location is reserved for MDVault's shared Media folder."`.
    - In `VaultService`: add private `assertNotMediaDirectory(string $path, string $field): void` checking `$this->files->isSameOrInside($path, $this->paths->mediaDirectory()) || $this->files->isSameOrInside($this->paths->mediaDirectory(), $path)` and throwing `VaultOperationException::reservedForMedia($field)`. Call it from `create()` on `$target` (`'name'`), `rename()` on `$to` (`'name'`), and inside `assertSafeFolder()` on `$path` (`$field`) so `register()` and `remove(..., moveFolderToTrash: true)` are covered too.
  - Covers: FR-01, FR-03

- [ ] **T2 — Create `MediaOperationException` and `MediaService`**
  - Files: `app/Exceptions/MediaOperationException.php`, `app/Services/MediaService.php`
  - Commands:
    - `php artisan make:class Exceptions/MediaOperationException --no-interaction`
    - `php artisan make:class Services/MediaService --no-interaction`
  - Details:
    - `MediaOperationException` (extending `\RuntimeException` with `field(): string`, matching `NoteOperationException`): factories `vaultUnavailable()`, `encryptedVaultUnsupported()`, `mediaFolderUnavailable()`, `mediaFolderConflict()`, `invalidImage()`, `tooLarge()`, `writeFailed()`.
    - `MediaService` (injecting `StoragePathService`, `FileStorageService`, `VaultService`):
      - Constants: `MAX_UPLOAD_BYTES = 10 * 1024 * 1024`, `ALLOWED_MIME_EXTENSIONS = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp']`.
      - `store(Vault $vault, UploadedFile $file): array{filename: string, url: string, mime_type: string, size: int}`:
        1. Refresh vault status (`$this->vaults->refreshStatus($vault)`). If `VaultStatus::Missing`, throw `vaultUnavailable()`.
        2. If `$vault->is_encrypted`, throw `encryptedVaultUnsupported()`.
        3. Validate `$file->isValid()`, inspect real MIME via `$file->getMimeType()` against `ALLOWED_MIME_EXTENSIONS`, verify `$file->getSize()` `<= MAX_UPLOAD_BYTES`, and verify `getimagesize($realPath)` succeeds with positive width & height (no SVG/polyglot).
        4. `ensureMediaDirectoryReady()`: call `$this->paths->ensureRootReady()`, get `$mediaDir = $this->paths->mediaDirectory()`, refuse if `$this->vaults->overlappingVault($mediaDir) !== null` (`mediaFolderConflict()`), refuse if `$this->files->exists($mediaDir)` and (`!$this->files->isDirectory($mediaDir)` or `$this->files->isSymlink($mediaDir)`), create `$mediaDir` with `$this->files->makeDirectory($mediaDir)` if missing, check `$this->files->isWritableDirectory($mediaDir)`.
        5. Generate safe filename: `<vaultSlug>-<stemSlug>-<UTC Ymd-His>-<8hex>.<ext>` where `sanitizeSegment()` uses `Str::ascii()`, replaces `[^A-Za-z0-9_-]+` with `-`, trims `-_`, falls back to `'vault'` / `'screenshot'`, caps lengths (`48` for vault, `32` for stem).
        6. Open read stream on `$file->getRealPath()` (`fopen(..., 'rb')`, closed in `finally`) and write exclusively via `$this->files->createFileFromStream($target, $stream, self::MAX_UPLOAD_BYTES)` (retry up to 3 times on random collision). Return `filename`, `url => route('media.show', ['filename' => $filename], absolute: false)`, `mime_type`, `size`.
      - `resolveForServing(string $filename): ?array{path: string, mime: string, size: int}`:
        1. Reject empty, NUL, `/`, `\`, leading `.`, `.` or `..`, or characters outside `[A-Za-z0-9._-]`.
        2. Ensure `mediaDirectory()` exists as non-symlink directory; join path; check `isFile($target) && !$isSymlink($target)`.
        3. Verify canonical containment: `isSameOrInside($canonicalTarget, $canonicalMedia) && !$samePath($canonicalTarget, $canonicalMedia)`.
        4. Inspect content MIME with `(new \finfo(FILEINFO_MIME_TYPE))->file($canonicalTarget)`; return `null` unless in `ALLOWED_MIME_EXTENSIONS`.
        5. Return `['path' => $canonicalTarget, 'mime' => $mime, 'size' => $size]`.
  - Covers: FR-01, FR-02, FR-03, FR-04, FR-05, FR-06

- [ ] **T3 — Create `StoreVaultMediaRequest`, `MediaController`, routes, and Wayfinder definitions**
  - Files: `app/Http/Requests/Media/StoreVaultMediaRequest.php`, `app/Http/Controllers/MediaController.php`, `routes/media.php`, `routes/web.php`, `resources/js/routes/...` (generated)
  - Commands:
    - `php artisan make:request Media/StoreVaultMediaRequest --no-interaction`
    - `php artisan make:controller MediaController --no-interaction`
  - Details:
    - `StoreVaultMediaRequest`: `authorize(): bool => true`; `rules()` validates `'image' => ['required', 'file', 'mimes:png,jpg,jpeg,gif,webp', 'max:10240']` with clear error messages.
    - `MediaController`:
      - `store(StoreVaultMediaRequest $request, Vault $vault, MediaService $media): JsonResponse`: calls `$media->store($vault, $request->file('image'))` and returns `response()->json($stored, 201)`. Catches `MediaOperationException` and throws `ValidationException::withMessages([$e->field() => $e->getMessage()])` (returns HTTP 422 JSON cleanly since Inertia `useHttp` sends `Accept: application/json`).
      - `show(string $filename, MediaService $media): BinaryFileResponse`: calls `$resolved = $media->resolveForServing($filename); abort_if($resolved === null, 404);` and returns `response()->file($resolved['path'], ['Content-Type' => $resolved['mime'], 'Content-Disposition' => 'inline; filename="'.$filename.'"', 'X-Content-Type-Options' => 'nosniff'])`.
    - `routes/media.php`: register `Route::post('vaults/{vault:uuid}/media', [MediaController::class, 'store'])->whereUuid('vault')->name('vaults.media.store');` and `Route::get('media/{filename}', [MediaController::class, 'show'])->where('filename', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('media.show');`.
    - `routes/web.php`: `require __DIR__.'/media.php';`.
    - Regenerate Wayfinder routes: `php artisan wayfinder:generate --with-form --no-interaction`.
  - Covers: FR-04, FR-05, FR-06

- [ ] **T4 — Raise NativePHP PHP upload INI limits**
  - Files: `app/Providers/NativeAppServiceProvider.php`
  - Details: Update `phpIni()` to return `['upload_max_filesize' => '12M', 'post_max_size' => '16M']` so desktop screenshot/photo uploads up to 10 MB are never rejected by PHP's default 2 MB limit.
  - Covers: FR-04

- [ ] **T5 — Add TypeScript response type and shared upload/transport helper (`mediaUpload.ts`)**
  - Files: `resources/js/types/notes.ts`, `resources/js/lib/editor/mediaUpload.ts`
  - Details:
    - Add `MediaUploadResponse` (`{ filename: string; url: string; mime_type: string; size: number }`) to `resources/js/types/notes.ts`.
    - Create `resources/js/lib/editor/mediaUpload.ts` with:
      - `MAX_MEDIA_UPLOAD_BYTES = 10 * 1024 * 1024`
      - `ALLOWED_IMAGE_MIME_TYPES` (`image/png`, `image/jpeg`, `image/gif`, `image/webp`) and extension fallback check (`validateImageFile(file: File): string | null`)
      - Pure `mapMediaUploadResult(result)` mapper (mirroring `saveTransport.ts` for unit testability under Node: `success`, `validation`, `httpException` including 413/422/423, `network`)
      - `uploadVaultMedia(vaultUuid: string, file: File): Promise<MediaUploadOutcome>` using Wayfinder `store.url(vaultUuid)` + Inertia `useHttp` (automatically converts `File` to `FormData` and passes through `keyring.ts` interceptor)
      - Helper `deriveAltText(file: File): string` (strips extension from non-generic names; falls back to `'screenshot'` or `'image'`).
  - Covers: FR-04, FR-05, FR-08, FR-09

- [ ] **T6 — Add CodeMirror image commands, `Ctrl+Alt+I` keymap, and preview tests**
  - Files: `resources/js/lib/editor/codemirrorCommands.ts`, `resources/js/lib/editor/codemirrorKeymap.ts`, `resources/js/lib/editor/codemirror.ts`
  - Details:
    - In `codemirrorCommands.ts`:
      - Add `insertImage(view: CommandTarget, url: string, alt?: string, range?: { from: number; to: number }): boolean`: strips `\r\n` and `[`/`]` from `alt` (fallback `'image'`), inserts `![${cleanAlt}](${url})` at `range ?? state.selection.main`, places cursor after the inserted image syntax (`from + insertion.length`), focuses view.
      - Add `imageAt(state: EditorState, pos: number): { from: number; to: number; alt: string; src: string } | null`: matches `!\[([^\]]*)\]\(([^)]*)\)` on the line containing `pos`.
      - Add `isImageActive(state: EditorState): boolean`.
    - In `codemirrorKeymap.ts`: add optional `onImage?: () => void` option and `{ key: 'Mod-Alt-i', run: () => { onImage?.(); return true; } }`.
    - In `codemirror.ts`: accept `onImage`, `onPasteImage?: (file: File) => void`, `onDropImages?: (files: File[], pos: number | null) => void` in `buildCodeMirrorExtensions()`, wire `onImage` into `markdownFormattingKeymap`, and register `EditorView.domEventHandlers({ paste, drop })`:
      - `paste(event, view)`: if `!view.state.readOnly`, extract image `File`(s) from `event.clipboardData?.items`/`files`; if at least one image file exists and `event.clipboardData?.getData('text/plain')` is empty, call `event.preventDefault()`, invoke `onPasteImage(file)`, and return `true`.
      - `drop(event, view)`: if `!view.state.readOnly`, extract image `File`(s) from `event.dataTransfer?.files`; if at least one image file exists, call `event.preventDefault()`, resolve `const pos = view.posAtCoords({ x: event.clientX, y: event.clientY })`, invoke `onDropImages(files, pos)`, and return `true`.
  - Covers: FR-07, FR-08, FR-09, FR-10

- [ ] **T7 — Build `ImageDialog.vue`**
  - Files: `resources/js/components/editor/ImageDialog.vue`
  - Details:
    - Single-root Reka UI `Dialog` following `LinkDialog.vue` & `MermaidDialog.vue` styling.
    - Props: `view?: EditorView`, `vaultUuid?: string`, `vaultEncrypted?: boolean`, `initialSrc?: string`, `initialAlt?: string`, `range?: { from: number; to: number }`.
    - Two-mode toggle (`Upload / Paste` and `Image URL`; defaults to `Image URL` when editing an existing `http(s)://` URL or when `vaultEncrypted` is true).
    - **Upload / Paste mode**:
      - Shows `Alert` warning if `vaultEncrypted` is true and disables upload controls.
      - File picker button + `<input type="file" accept="image/png,image/jpeg,image/gif,image/webp">` + drag-and-drop zone + dialog `@paste` listener + optional "Paste from clipboard" button (`navigator.clipboard?.read()` with graceful unsupported/denied feedback).
      - Displays local preview thumbnail (`URL.createObjectURL`) and byte size; revokes object URLs on reset/close/unmount.
    - **Image URL mode**:
      - Text/URL input validated with `isAllowedHref()` (rejecting `javascript:`, `data:`, `vbscript:`) with inline live thumbnail preview when valid.
    - Shared **Alt Text** input (pre-filled from selection / `initialAlt` / selected filename stem).
    - **Apply / Insert Image** action: uploads via `uploadVaultMedia()` if in Upload mode (showing spinner and keeping dialog open with inline error on failure), then calls `insertImage(view, url, alt, props.range)` and closes dialog.
  - Covers: FR-05, FR-07, FR-08

- [ ] **T8 — Integrate Image button into `EditorToolbar.vue` and wire `MarkdownEditor.vue`, `NoteEditor.vue`, and `Workspace.vue`**
  - Files: `resources/js/components/editor/EditorToolbar.vue`, `resources/js/components/editor/MarkdownEditor.vue`, `resources/js/components/editor/NoteEditor.vue`, `resources/js/pages/Workspace.vue`
  - Details:
    - `EditorToolbar.vue`:
      - Add optional props `vaultUuid?: string`, `vaultEncrypted?: boolean`, `readonly?: boolean`.
      - Add Image button (`ImageIcon` from `@lucide/vue`) immediately after the `Link` button (`title="Insert image (Ctrl+Alt+I)"`, `aria-label="Insert image"`, `:aria-pressed="isImageActive()"`, `:disabled="!props.view || props.readonly"`).
      - Add `openImageDialog()` (inspecting `imageAt()` at caret for edit-in-place and `sliceDoc` selection for initial alt text), expose `openImageDialog` via `defineExpose`, and mount `<ImageDialog>`.
    - `MarkdownEditor.vue`:
      - Accept optional props `vaultUuid?: string`, `vaultEncrypted?: boolean`.
      - Pass `:vault-uuid="vaultUuid"`, `:vault-encrypted="vaultEncrypted"`, and `:readonly="!isInteractive"` to `<EditorToolbar>`.
      - Wire `onImage`, `onPasteImage`, and `onDropImages` in `buildCodeMirrorExtensions()`:
        - For paste/drop: if `!isInteractive.value`, ignore; if `vaultEncrypted`, show `toast.error("Image attachments aren't supported in encrypted vaults yet.")`; if `!vaultUuid`, ignore; validate with `validateImageFile()`, show loading toast while calling `uploadVaultMedia()`, guard `isMounted && noteUuid === uploadNoteUuid && view.value && isInteractive.value` after await, call `insertImage()` (at `pos` range if dropped, or current selection if pasted), and update toast to success/error.
    - `NoteEditor.vue`: accept optional `vaultEncrypted?: boolean`, pass `:vault-uuid="props.vaultUuid"` and `:vault-encrypted="props.vaultEncrypted"` to `<MarkdownEditor>`.
    - `Workspace.vue`: pass `:vault-encrypted="currentVault.is_encrypted"` to `<NoteEditor>`.
  - Covers: FR-05, FR-07, FR-08, FR-09

- [ ] **T9 — Add backend Feature tests and frontend Unit tests**
  - Files: `tests/Feature/Media/MediaAttachmentTest.php`, `tests/Feature/Vaults/VaultManagementTest.php`, `tests/js/editor/codemirrorCommands.test.ts`, `tests/js/editor/mediaUpload.test.ts`, `tests/js/markdownPreview.test.ts`
  - Command: `php artisan make:test --pest Media/MediaAttachmentTest --no-interaction`
  - Details: Implement comprehensive test coverage per Section 4 below, run `vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan analyse`, `npm run types:check`, `npm run test:js`, and `php artisan test --compact`.
  - Covers: FR-01 through FR-10

---

## 4. Test Plan

| Test File | Scenario | Covers |
|---|---|---|
| `tests/Feature/Media/MediaAttachmentTest.php` | Uploads valid PNG/JPEG/GIF/WEBP for active unencrypted vault -> lazily creates `<rootPath>/Media/`, stores file whose name contains sanitized vault name, returns 201 JSON `{filename, url, mime_type, size}` without internal IDs or OS paths | FR-01, FR-02 |
| `tests/Feature/Media/MediaAttachmentTest.php` | Sanitizes vault names with spaces/special chars/unicode into URL- & CommonMark-safe filenames; multiple uploads never overwrite existing files | FR-02 |
| `tests/Feature/Media/MediaAttachmentTest.php` | Rejects missing file, SVG (`image/svg+xml`), HTML/text disguised as `.png`, corrupt/non-image binary, and >10 MB payload with 422 and leaves `Media/` clean | FR-04 |
| `tests/Feature/Media/MediaAttachmentTest.php` | Rejects upload when vault is missing on disk (422), when vault is encrypted (locked or unlocked -> 422, nothing written), or when a registered vault overlaps `Media/` | FR-03, FR-05 |
| `tests/Feature/Media/MediaAttachmentTest.php` | `GET /media/{filename}` serves valid stored images inline with matching `Content-Type` and `X-Content-Type-Options: nosniff`; returns 404 for missing file, path traversal (`../`), dotfile, symlink, or non-image/SVG manually dropped in `Media/` | FR-06 |
| `tests/Feature/Vaults/VaultManagementTest.php` | Creating a vault named `Media`, renaming a vault to `Media` inside root, or registering `<rootPath>/Media` as an existing vault fails validation cleanly | FR-03 |
| `tests/js/editor/codemirrorCommands.test.ts` | `insertImage()` inserts `![alt](url)`, strips brackets/newlines in alt text, supports range replacement, and `imageAt()` / `isImageActive()` detect images at cursor without matching regular `[text](url)` links | FR-07, FR-08, FR-09 |
| `tests/js/editor/mediaUpload.test.ts` | `validateImageFile()`, `deriveAltText()`, and `mapMediaUploadResult()` handle valid types, SVG/oversize rejection, 201 success, 413/422/423 HTTP exceptions, and network errors | FR-04, FR-05, FR-08, FR-09 |
| `tests/js/markdownPreview.test.ts` | `renderMarkdownPreview()` renders `/media/...` and `https://` images with escaped `alt`/`title`/`src` and emits no `<img>` for `javascript:`/`data:`/`vbscript:` protocols | FR-10 |

**Test scope for QA**:
- `php artisan test --compact tests/Feature/Media/MediaAttachmentTest.php tests/Feature/Vaults/VaultManagementTest.php tests/Feature/Notes/NoteContentTest.php`
- `npm run test:js`
- `vendor/bin/phpstan analyse`
- `npm run types:check`
- `npm run check`

---

## 5. Risks & Mitigations
- **Risk**: Windows/macOS path canonicalization differences when `rootPath()` points through a symlink/junction (e.g. `/var` -> `/private/var` on macOS) cause `VaultService` or `MediaService` path comparisons to disagree.
  **Mitigation**: `StoragePathService::mediaDirectory()` canonicalizes `$root` via `realpath` when `$root` exists on disk, and all containment checks delegate to `FileStorageService::isSameOrInside()` / `samePath()`.
- **Risk**: `useHttp` upload concurrency if the user pastes multiple screenshots or switches notes before an upload completes.
  **Mitigation**: Each `uploadVaultMedia()` call creates its own `useHttp` instance, and `MarkdownEditor.vue` checks mount status, `noteUuid` match, and `isInteractive` before applying the CodeMirror transaction.
- **Risk**: PHP default 2 MB `upload_max_filesize` blocks Retina screenshots in NativePHP desktop.
  **Mitigation**: Raise `upload_max_filesize` (`12M`) and `post_max_size` (`16M`) in `NativeAppServiceProvider::phpIni()`.

---

## 6. Open Questions
- None (architectural trade-offs recorded in `.ai/decisions/media-attachment-storage.md`).

---

## 7. Revision Log
| Revision | Date | Reason | Changes |
|---|---|---|---|
| 1 | 2026-11-17 | Initial plan | — |
