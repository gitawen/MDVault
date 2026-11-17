# QA Report: Image Attachment & Shared Media Storage

## Metadata
- **Feature Name**: Image Attachment & Shared Media Storage
- **Feature ID**: feat-media-attachments
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-11-17
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 (Shared Media Folder in MDVault Root) | yes | `tests/Feature/Media/MediaAttachmentTest.php` | ✅ |
| FR-02 (Vault-Named Safe Filenames) | yes | `tests/Feature/Media/MediaAttachmentTest.php` | ✅ |
| FR-03 (Vault & Media Folder Collision Protection) | yes | `tests/Feature/Vaults/VaultManagementTest.php`, `tests/Feature/Media/MediaAttachmentTest.php` | ✅ |
| FR-04 (Server-Side Upload Validation & NativePHP Limits) | yes | `tests/Feature/Media/MediaAttachmentTest.php`, `tests/Feature/NativeAppServiceProviderTest.php`, `tests/js/editor/mediaUpload.test.ts` | ✅ |
| FR-05 (Encrypted Vault Guard) | yes | `tests/Feature/Media/MediaAttachmentTest.php`, `tests/js/editor/mediaUpload.test.ts` | ✅ |
| FR-06 (Secure Media Serving Endpoint) | yes | `tests/Feature/Media/MediaAttachmentTest.php` | ✅ |
| FR-07 (Rich-Text Toolbar Image Button & Shortcut) | yes | `tests/js/editor/codemirrorCommands.test.ts` (`imageAt`, `isImageActive`; UI/keymap verified by inspection & type-check) | ✅ |
| FR-08 (Image Dialog: Upload, Paste, Drop, URL & Edit) | yes | `tests/js/editor/codemirrorCommands.test.ts`, `tests/js/editor/mediaUpload.test.ts` (component verified by inspection & `vue-tsc`) | ✅ |
| FR-09 (Direct Editor Screenshot Paste & Drag-and-Drop) | yes | `tests/js/editor/codemirrorCommands.test.ts`, `tests/js/editor/mediaUpload.test.ts` (CodeMirror DOM handlers verified by inspection & `vue-tsc`) | ✅ |
| FR-10 (Safe Preview Rendering) | yes | `tests/js/editor/markdownPreview.test.ts` | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Media/MediaAttachmentTest.php tests/Feature/Vaults/VaultManagementTest.php tests/Feature/NativeAppServiceProviderTest.php tests/Feature/Notes/NoteContentTest.php` | 48 passed (342 assertions) |
| `php artisan test --compact tests/Feature/Vaults` | 80 passed (691 assertions) |
| `npm.cmd run test:js` | 20 test files passed, 293 tests passed |
| `vendor/bin/phpstan analyse app/Exceptions/MediaOperationException.php app/Exceptions/VaultOperationException.php app/Http/Controllers/MediaController.php app/Http/Requests/Media/StoreVaultMediaRequest.php app/Providers/NativeAppServiceProvider.php app/Services/FileStorageService.php app/Services/MediaService.php app/Services/StoragePathService.php app/Services/VaultService.php` | passed (0 errors) |
| `vendor/bin/phpstan analyse` (repo-wide) | 2 pre-existing errors in untouched files (`app/Http/Middleware/HandleInertiaRequests.php:50`, `app/Services/DirectoryBrowserService.php:287`) |
| `npm.cmd run types:check` | passed (`vue-tsc --noEmit` exit code 0) |
| `npx.cmd vp check <13 changed JS/TS/Vue files>` | passed (13 files formatted, 0 warnings/lint errors) |
| `npm.cmd run check` (repo-wide) | 3 pre-existing formatting issues in untouched files (`AddExistingVaultDialog.vue`, `Editor.vue`, `codemirrorLivePreview.test.ts`) |
| `vendor/bin/pint --test --dirty` | passed |

```text
# vendor/bin/phpstan analyse (repo-wide pre-existing errors in untouched files)
C:\Users\cherw\Herd\MDVault\app\Http\Middleware\HandleInertiaRequests.php:50 Cannot cast non-empty-array|non-falsy-string to string.
C:\Users\cherw\Herd\MDVault\app\Services\DirectoryBrowserService.php:287 Parameter #1 $array (list<array{name: string, path: non-falsy-string}>) of array_values is already a list, call has no effect.

# npm.cmd run check (repo-wide pre-existing formatting issues in untouched files)
resources/js/components/vaults/AddExistingVaultDialog.vue
resources/js/pages/settings/Editor.vue
tests/js/editor/codemirrorLivePreview.test.ts
```

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Medium | MINOR | `resources/js/components/editor/ImageDialog.vue:289-306` | `apply()` awaits `uploadVaultMedia()` and then calls `insertImage(active, ...)` without checking whether the dialog is still mounted and open (`open.value`). If the user dismisses the dialog via Escape/backdrop click or navigates to another note mid-upload, the resolved callback can insert into a closed dialog or destroyed `EditorView`. | Guard after `await uploadVaultMedia(...)` with component mount state and `open.value` (matching `MarkdownEditor.vue`'s async upload guard), or block dialog dismissal while `isUploading` is true. | 0 |
| QA-02 | Low | MINOR | `tests/js/editor/codemirror.test.ts` | `Mod-Alt-i` keymap registration (`codemirrorKeymap.ts`) and `paste`/`drop` image `domEventHandlers` (`codemirror.ts`) do not have direct unit assertions in `codemirror.test.ts`. | Add assertions in `tests/js/editor/codemirror.test.ts` verifying `Mod-Alt-i` keymap registration and image paste/drop handler behavior. | 0 |

---

## 4. Code Review Notes
- **Security & Authorization**:
  - `MediaService::store()` validates uploaded files via `$file->isValid()`, size bounds, magic-byte MIME (`$file->getMimeType()`), and `getimagesize()` dimensions against the strict raster allow-list (`image/png`, `image/jpeg`, `image/gif`, `image/webp`), rejecting SVG/HTML/polyglot uploads.
  - `MediaService` uses no raw `fopen`/`unlink`/`mkdir`/`rename` calls; all writes stream exclusively (`'x'` mode) through `FileStorageService::createFileFromPath()` -> `createFileFromStream()` in 1 MiB chunks.
  - `MediaController::show()` and `routes/media.php` enforce `[A-Za-z0-9][A-Za-z0-9._-]*` on `{filename}`, and `MediaService::resolveForServing()` rejects symlinks, dotfiles, `..`, non-contained canonical paths, and non-raster `finfo` types, serving with `Content-Disposition: inline` and `X-Content-Type-Options: nosniff`.
  - Encrypted vaults (`$vault->is_encrypted`) are blocked server-side with HTTP 422 (`MediaOperationException::encryptedVaultUnsupported()`) and client-side in `ImageDialog.vue` and `MarkdownEditor.vue`.
  - No absolute OS paths or internal DB IDs appear in JSON responses or `MediaOperationException` messages.
- **Validation & Data Integrity**:
  - `StoreVaultMediaRequest` enforces `required`, `file`, `mimes:png,jpg,jpeg,gif,webp`, `max:10240`; `NativeAppServiceProvider::phpIni()` sets `upload_max_filesize => '12M'` and `post_max_size => '16M'`.
  - `VaultService::assertNotMediaDirectory()` blocks creating, renaming into, or registering `<rootPath>/Media` (including case-insensitive `media` directly under `rootPath()`) or any subfolder of it, and `MediaService` checks `VaultService::overlappingVault($mediaDir)` before creating/writing to `Media/`.
- **Performance (N+1, indexes)**:
  - No DB schema changes; uploads stream in 1 MiB chunks and downloads stream via `response()->file()`. Object URLs in `ImageDialog.vue` are revoked on replacement, close, and unmount.
- **Conventions (AGENTS.md, `.ai/rules/`)**:
  - Backend PHP follows PHP 8.4 explicit return types, constructor property promotion, and Pint formatting.
  - Wayfinder routes (`@/routes/vaults/media`, `@/routes/media`) are generated and used via `store.url(vaultUuid)` with `useHttp`.
- **Frontend (Inertia/Vue/Wayfinder)**:
  - `ImageDialog.vue` uses a single-root Reka UI `Dialog` with accessible `DialogTitle`, `DialogDescription`, `Label`, `Input`, `Button`, and `Alert` primitives.
  - `MarkdownEditor.vue` guards direct paste/drop async uploads against unmount, `noteUuid` changes, and `isInteractive` changes; see `QA-01` for applying the same mount/open guard inside `ImageDialog.vue::apply()`.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/` (with `QA-01` and `QA-02` documented as low-risk follow-ups)
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: —
- [ ] **FAIL — MAJOR** → System Analyst: —

---

**QA Summary**:
- **Verdict**: PASS
- **Issue Counts**: Critical: 0, High: 0, Medium: 1 (`QA-01`), Low: 1 (`QA-02`)
- **Routing**: Zero open Critical or High issues; ready to complete (or optionally address `QA-01` / `QA-02` with Senior Developer if desired). None routed to System Analyst.
