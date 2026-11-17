# Implementation: Image Attachment & Shared Media Storage

## Metadata
- **Feature Name**: Image Attachment & Shared Media Storage
- **Feature ID**: feat-media-attachments
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: READY FOR QA

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T1 — Add `mediaDirectory()` to `StoragePathService` & protect it in `VaultService` | DONE | Added `MEDIA_FOLDER_NAME`, `mediaDirectory()`, `VaultOperationException::reservedForMedia()`, and `VaultService::assertNotMediaDirectory()` (with case-insensitive check directly under `rootPath()`). |
| T2 — Create `MediaOperationException` and `MediaService` | DONE | Added `FileStorageService::createFileFromPath()` so `MediaService` uses zero raw `fopen` calls while streaming via `createFileFromStream()`. |
| T3 — Create `StoreVaultMediaRequest`, `MediaController`, routes, and Wayfinder definitions | DONE | Created `StoreVaultMediaRequest`, `MediaController`, `routes/media.php`, required it in `routes/web.php`, cleared route cache, and regenerated Wayfinder routes/actions. |
| T4 — Raise NativePHP PHP upload INI limits | DONE | Set `upload_max_filesize => '12M'` and `post_max_size => '16M'` in `NativeAppServiceProvider::phpIni()`. |
| T5 — Add TypeScript response type and shared upload/transport helper (`mediaUpload.ts`) | DONE | Added `MediaUploadResponse` to `resources/js/types/notes.ts` and created `resources/js/lib/editor/mediaUpload.ts`. |
| T6 — Add CodeMirror image commands, `Ctrl+Alt+I` keymap, and paste/drop handlers | DONE | Added `insertImage`, `imageAt`, `isImageActive` to `codemirrorCommands.ts`, `Mod-Alt-i` to `codemirrorKeymap.ts`, and `onImage`/`onPasteImage`/`onDropImages` handlers to `codemirror.ts`. |
| T7 — Build `ImageDialog.vue` | DONE | Created `ImageDialog.vue` with Upload/Paste + Image URL tabs, dropzone, `@paste` handler, `navigator.clipboard.read()` fallback, encrypted vault notice, object URL cleanup, and live preview. |
| T8 — Integrate Image button into `EditorToolbar.vue` and wire `MarkdownEditor.vue`, `NoteEditor.vue`, and `Workspace.vue` | DONE | Added toolbar Image button, wired `openImageDialog`, direct paste/drop upload with mount/note-switch/interactive guards, and passed `vaultUuid` and `vaultEncrypted` down from `Workspace.vue`. |
| T9 — Add backend Feature tests and frontend Unit tests | DONE | Added `MediaAttachmentTest.php`, `mediaUpload.test.ts`, and extended `VaultManagementTest.php`, `NativeAppServiceProviderTest.php`, `codemirrorCommands.test.ts`, and `tests/js/editor/markdownPreview.test.ts`. |

---

## 2. Files Changed
| Action | Path | Summary |
|---|---|---|
| modified | `app/Services/StoragePathService.php` | Added `MEDIA_FOLDER_NAME = 'Media'` and `mediaDirectory()`. |
| modified | `app/Services/FileStorageService.php` | Added `createFileFromPath()` helper wrapping `fopen(..., 'rb')` + `createFileFromStream()` with `finally { fclose(...) }`. |
| modified | `app/Exceptions/VaultOperationException.php` | Added `reservedForMedia(string $field)`. |
| modified | `app/Services/VaultService.php` | Added `assertNotMediaDirectory()` called in `create()`, `rename()`, and `assertSafeFolder()`. |
| created | `app/Exceptions/MediaOperationException.php` | Domain exception for media upload/storage failures without OS paths. |
| created | `app/Services/MediaService.php` | Raster image validation (`getimagesize()` + MIME), safe vault-prefixed filenames, lazy `Media/` creation, exclusive stream writes, and safe serving resolution. |
| created | `app/Http/Requests/Media/StoreVaultMediaRequest.php` | Multipart `image` validation (`required`, `file`, `mimes:png,jpg,jpeg,gif,webp`, `max:10240`). |
| created | `app/Http/Controllers/MediaController.php` | `store()` returning 201 JSON (or 422 validation error) and `show()` streaming inline with `nosniff`. |
| modified | `app/Providers/NativeAppServiceProvider.php` | Set `upload_max_filesize => '12M'` and `post_max_size => '16M'` in `phpIni()`. |
| created | `routes/media.php` | Registered `vaults.media.store` and `media.show` with UUID and filename regex constraints. |
| modified | `routes/web.php` | Required `routes/media.php`. |
| created | `resources/js/routes/vaults/media/index.ts`, `resources/js/routes/media/index.ts`, `resources/js/actions/App/Http/Controllers/MediaController.ts` | Generated via `php artisan wayfinder:generate --with-form --no-interaction`. |
| modified | `resources/js/types/notes.ts` | Added `MediaUploadResponse` type. |
| created | `resources/js/lib/editor/mediaUpload.ts` | Client pre-validation, alt-text derivation, `mapMediaUploadResult()`, and `uploadVaultMedia()` via Wayfinder + `useHttp`. |
| modified | `resources/js/lib/editor/codemirrorCommands.ts` | Added `insertImage()`, `imageAt()`, and `isImageActive()`. |
| modified | `resources/js/lib/editor/codemirrorKeymap.ts` | Added `Mod-Alt-i` shortcut invoking `onImage`. |
| modified | `resources/js/lib/editor/codemirror.ts` | Wired `onImage`, `onPasteImage`, and `onDropImages` via `EditorView.domEventHandlers`. |
| created | `resources/js/components/editor/ImageDialog.vue` | Modal dialog supporting file upload, clipboard paste, drag-and-drop, external/relative URL editing, and previews. |
| modified | `resources/js/components/editor/EditorToolbar.vue` | Added Image button next to Link, `openImageDialog()`, and mounted `ImageDialog`. |
| modified | `resources/js/components/editor/MarkdownEditor.vue` | Passed `vaultUuid`, `vaultEncrypted`, and `readonly` to `EditorToolbar`; implemented guarded direct editor paste/drop upload handler. |
| modified | `resources/js/components/editor/NoteEditor.vue` | Accepted optional `vaultEncrypted` and forwarded `vaultUuid` + `vaultEncrypted` to `MarkdownEditor`. |
| modified | `resources/js/pages/Workspace.vue` | Passed `:vault-encrypted="currentVault.is_encrypted"` to `NoteEditor`. |
| created | `tests/Feature/Media/MediaAttachmentTest.php` | Feature tests covering FR-01 through FR-06. |
| modified | `tests/Feature/Vaults/VaultManagementTest.php` | Added test for reserved `Media` folder collision on create, rename, and register. |
| modified | `tests/Feature/NativeAppServiceProviderTest.php` | Added test verifying `phpIni()` upload limits. |
| modified | `tests/js/editor/codemirrorCommands.test.ts` | Added tests for `insertImage`, `imageAt`, and `isImageActive`. |
| created | `tests/js/editor/mediaUpload.test.ts` | Unit tests for `validateImageFile`, `deriveAltText`, and `mapMediaUploadResult`. |
| modified | `tests/js/editor/markdownPreview.test.ts` | Added tests for `/media/...` and `https://` image rendering and disallowed protocol blocking. |

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | passed |
| `vendor/bin/phpstan analyse app/Exceptions/MediaOperationException.php app/Exceptions/VaultOperationException.php app/Http/Controllers/MediaController.php app/Http/Requests/Media/StoreVaultMediaRequest.php app/Providers/NativeAppServiceProvider.php app/Services/FileStorageService.php app/Services/MediaService.php app/Services/StoragePathService.php app/Services/VaultService.php` | passed (0 errors) |
| `php artisan test --compact tests/Feature/Media/MediaAttachmentTest.php tests/Feature/Vaults/VaultManagementTest.php tests/Feature/NativeAppServiceProviderTest.php tests/Feature/Notes/NoteContentTest.php` | passed (48 passed, 342 assertions) |
| `npm.cmd run types:check` | passed (`vue-tsc --noEmit` exit code 0) |
| `npm.cmd run test:js` | passed (20 test files, 293 tests passed) |
| `npx.cmd vp check resources/js/types/notes.ts resources/js/lib/editor/mediaUpload.ts resources/js/lib/editor/codemirrorCommands.ts resources/js/lib/editor/codemirrorKeymap.ts resources/js/lib/editor/codemirror.ts resources/js/components/editor/ImageDialog.vue resources/js/components/editor/EditorToolbar.vue resources/js/components/editor/MarkdownEditor.vue resources/js/components/editor/NoteEditor.vue resources/js/pages/Workspace.vue tests/js/editor/codemirrorCommands.test.ts tests/js/editor/mediaUpload.test.ts tests/js/editor/markdownPreview.test.ts` | passed (0 errors, 0 warnings across 13 files) |

*Note on repo-wide static checks*: Running bare `vendor/bin/phpstan analyse` and `npm.cmd run check` also surfaced pre-existing issues in untouched files (`app/Http/Middleware/HandleInertiaRequests.php`, `app/Services/DirectoryBrowserService.php`, `resources/js/components/vaults/AddExistingVaultDialog.vue`, `resources/js/pages/settings/Editor.vue`, `tests/js/editor/codemirrorLivePreview.test.ts`). None of those were modified.

---

## 4. Deviations from Plan
- **Stream helper in `FileStorageService`**: Added `FileStorageService::createFileFromPath()` instead of calling `fopen(..., 'rb')` directly inside `MediaService::store()`, so `MediaService` complies strictly with NFR-1 ("No raw filesystem functions (`fopen`, `unlink`, `mkdir`, `rename`) in controllers or `MediaService`") while still writing exclusively via `FileStorageService::createFileFromStream()`.
- **Case-insensitive `Media` guard on case-sensitive filesystems**: In `VaultService::assertNotMediaDirectory()`, added a `strcasecmp(basename($path), StoragePathService::MEDIA_FOLDER_NAME) === 0` check when `$path` is directly inside `rootPath()` so creating/renaming a vault to `media` (lowercase) is blocked on Linux/macOS as well as Windows.
- **`ImageDialog.vue` default tab when editing existing `/media/...` images**: `ImageDialog.vue` defaults to the `Image URL` tab whenever `initialSrc` is non-empty (both `http(s)://...` and `/media/...`), rather than only `http(s)://...`, so editing an existing attached image's alt text or URL works immediately without requiring a new file selection.
- **Test file path**: `plan.md` listed `tests/js/markdownPreview.test.ts`; the existing preview test suite in the repository is `tests/js/editor/markdownPreview.test.ts`, so the new preview tests were added there.

---

## 5. Notes for QA
- Verify manual browser/desktop UX for:
  - Clicking the Image toolbar button and pressing `Ctrl+Alt+I` (`Cmd+Alt+I` on macOS) to open `ImageDialog.vue`, including editing an existing `![alt](/media/...)` under the cursor.
  - Dragging and dropping one or multiple images onto CodeMirror, and pasting screenshots (`Ctrl+V`) both directly in CodeMirror and inside `ImageDialog.vue`.
  - Encrypted vault UX: upload controls and paste/drop are blocked with the explanatory alert/toast, while external `http(s)://` URL insertion in `ImageDialog.vue` remains available.
  - Note that `route:clear` was run during implementation because a stale route cache existed in the local workspace; ensure any cached routes are cleared/rebuilt in deployment environments.
