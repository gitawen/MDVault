# Requirements: Image Attachment & Shared Media Storage

## Metadata
- **Feature Name**: Image Attachment & Shared Media Storage
- **Feature ID**: feat-media-attachments
- **Author**: System Analyst
- **Created Date**: 2026-11-17
- **Task Complexity**: Level 3 — Complex Development
- **Status**: APPROVED

---

## 1. Problem Statement
MDVault currently supports Markdown link and Mermaid diagram insertion in its CodeMirror 6 editor (`MarkdownEditor.vue` / `EditorToolbar.vue`) and renders `<img ...>` tags in the Markdown preview (`markdownPreview.ts`), yet users have no built-in way to attach local image files or paste screenshots into their notes. Because vaults live in user-designated folders outside `public/` (and notes may be nested in folders or stored in vaults added from external locations via "Add existing folder"), MDVault needs a unified, secure, portable mechanism to store attached images in a single media folder inside the MDVault root folder, name them cleanly with the vault name, serve them safely into the editor preview, and expose rich-text toolbar, dialog, paste, and drag-and-drop workflows.

---

## 2. Goals & Non-Goals
### In Scope (Goals)
- Support attaching local raster images (`PNG`, `JPEG`, `GIF`, `WEBP`) via a new **Image** rich-text toolbar button (`EditorToolbar.vue`, `Ctrl+Alt+I`), an **Image dialog** (`ImageDialog.vue` supporting file upload, clipboard paste, drag-and-drop, external image URLs, and editing an existing Markdown image under the cursor), direct **clipboard screenshot paste** (`Ctrl+V` / `Cmd+V`), and **drag-and-drop** onto the Markdown editor.
- Save every attached image inside a dedicated shared media folder (`Media`) located directly inside the effective MDVault root folder (`StoragePathService::rootPath()`).
- Ensure every saved image filename contains the sanitized vault name, a sanitized stem/label, a timestamp, and a collision-proof random suffix with a server-verified image extension.
- Serve stored media files over a dedicated HTTP route (`/media/{filename}`) with strict path-traversal, symlink, and MIME-type protections so images render reliably in Preview and Split panes regardless of note folder depth.
- Protect the shared `Media` folder from colliding with vault creation, rename, or registration, and prevent unencrypted media leakage for encrypted vaults.
- Raise NativePHP desktop PHP upload INI limits (`upload_max_filesize`, `post_max_size`) so high-DPI screenshots and photos up to the application limit succeed in the desktop app.

### Out of Scope (Non-Goals)
- Storing encrypted image attachments for encrypted vaults (deferred to a dedicated follow-up per ADR `encrypted-vault-storage-layout`; encrypted vaults must explicitly block local media uploads rather than silently writing plaintext images to disk).
- Including the shared root `Media` folder inside vault ZIP backups/restores (requires `BackupService::FORMAT_VERSION = 3` and cross-vault manifest redesign).
- Automatic garbage collection / deletion of unreferenced files in `Media/`, or renaming existing media files when a vault is renamed (existing Markdown image links must remain intact).
- Allowing SVG (`image/svg+xml`) or other active XML/HTML/script uploads (excluded intentionally due to stored XSS risk).
- Rendering inline block image previews inside the CodeMirror 6 source editor pane itself (visual rendering remains in the Preview and Split panes, consistent with Mermaid diagrams, plus live preview inside `ImageDialog.vue`).

---

## 3. User Personas & Stories
- **As a** note author writing in MDVault,
  **I want to** click an Image button in the rich-text editor toolbar (or use `Ctrl+Alt+I`) to upload an image file, paste a screenshot, or insert an image URL,
  **So that** I can visually document ideas without manually copying files or hand-typing Markdown image syntax.
- **As a** note author capturing screenshots,
  **I want to** paste a screenshot from my clipboard (`Ctrl+V` / `Cmd+V`) or drag and drop an image file directly into the editor,
  **So that** the image is uploaded immediately and inserted as `![alt](/media/...)` at my cursor.
- **As a** user organizing multiple vaults under MDVault,
  **I want** all attached images stored in one shared `Media` folder inside my MDVault root folder with each filename containing the vault name,
  **So that** I can easily locate and identify which vault each media file belongs to on disk.
- **As a** security-conscious MDVault user,
  **I want** uploaded media strictly validated as safe raster images and encrypted vaults prevented from writing unencrypted screenshots to disk,
  **So that** my application stays safe from XSS/path-traversal attacks and encrypted vaults never leak plaintext data.

---

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Shared Media Folder in MDVault Root | Attached images are stored in `<rootPath>/Media` where `<rootPath>` is `StoragePathService::rootPath()`. The folder is not created until the first upload (lazy creation via `ensureMediaDirectoryReady()`). | Given an active unencrypted vault and a configured MDVault root folder, When an image is attached, Then `<rootPath>/Media` is created if missing and the image file is written inside it. |
| **FR-02** | Vault-Named Safe Filenames | Saved filenames contain the sanitized ASCII vault name prefix, a sanitized stem (`screenshot` or uploaded filename stem), a UTC timestamp (`Ymd-His`), an 8-hex random suffix, and a server-verified extension (`<vault>-<stem>-<Ymd-His>-<8hex>.<ext>`). Writes use exclusive file creation (`FileStorageService::createFileFromStream()`) and never overwrite existing files. | Given a vault named `"My Work"` and an uploaded PNG named `"Architecture Diagram.png"`, When stored, Then the saved file starts with `My-Work-`, ends with `.png`, uses only `[A-Za-z0-9._-]`, and never overwrites any existing file. |
| **FR-03** | Vault & Media Folder Collision Protection | `VaultService` must prevent creating a vault inside `<rootPath>` named `Media` (case-insensitive), renaming a vault to `<rootPath>/Media`, or registering `<rootPath>/Media` (or any subfolder of it) as a vault. `MediaService` must also refuse uploads if an existing registered vault overlaps `mediaDirectory()`. | Given `<rootPath>/Media` as the reserved media path, When a user attempts to create, rename into, or register `<rootPath>/Media` as a vault, Then validation fails with a user-facing error and nothing is modified on disk. |
| **FR-04** | Server-Side Upload Validation & NativePHP Limits | `POST /vaults/{vault:uuid}/media` (`vaults.media.store`) accepts multipart field `image`, validates max size `10 MB` (`10240` KB), checks magic-byte MIME type against `['image/png', 'image/jpeg', 'image/gif', 'image/webp']`, verifies valid image dimensions via `getimagesize()`, and blocks SVG/HTML/non-image files. `NativeAppServiceProvider::phpIni()` sets `upload_max_filesize = 12M` and `post_max_size = 16M`. | Given an uploaded SVG, HTML renamed to `.png`, empty/corrupt image, or file > 10 MB, When posted to `vaults.media.store`, Then HTTP 422 is returned and no file is written to `Media/`. |
| **FR-05** | Encrypted Vault Guard | Encrypted vaults (`$vault->is_encrypted === true`) cannot upload media into the unencrypted shared `Media` folder. Backend returns HTTP 422 (`MediaOperationException::encryptedVaultUnsupported()`); frontend disables file upload/paste/drop for encrypted vaults with a clear explanation while still allowing external `http(s)://` URL insertion in `ImageDialog`. | Given an open encrypted vault (locked or unlocked), When a user attempts to upload/paste/drop an image file or hits `vaults.media.store`, Then the upload is rejected, nothing is written to `Media/`, and the user sees a clear explanation. |
| **FR-06** | Secure Media Serving Endpoint | `GET /media/{filename}` (`media.show`) serves files strictly from `<rootPath>/Media/{filename}` after validating filename format (`[A-Za-z0-9][A-Za-z0-9._-]*`), rejecting symlinks/dotfiles/traversal, confirming canonical containment inside `Media/`, and verifying detected MIME type is in the safe raster allow-list. Responds with `Content-Type`, `Content-Disposition: inline`, and `X-Content-Type-Options: nosniff`. | Given a valid image in `Media/`, When requested via `/media/{filename}`, Then HTTP 200 streams the image inline with `nosniff`. Given `../`, `.env`, a symlink, a missing file, or an `.svg`/`.html` file manually placed in `Media/`, Then HTTP 404 is returned. |
| **FR-07** | Rich-Text Toolbar Image Button & Shortcut | `EditorToolbar.vue` displays an **Image** button (`ImageIcon`, tooltip `"Insert image (Ctrl+Alt+I)"`) next to `Link` across all viewport sizes. It is highlighted when the cursor is inside Markdown image syntax (`imageAt()`), disabled when editor is read-only/frozen, and bound to `Mod-Alt-i` in `codemirrorKeymap.ts`. | Given an editable note open in `MarkdownEditor.vue`, When the user clicks the Image toolbar button or presses `Ctrl+Alt+I` (`Cmd+Alt+I` on macOS), Then `ImageDialog.vue` opens (pre-filled if the cursor is on an existing `![alt](src)` image). |
| **FR-08** | Image Dialog (Upload, Paste, Drop, External URL & Edit) | `ImageDialog.vue` provides: (a) **Upload / Paste** mode with a file picker (`accept="image/png,image/jpeg,image/gif,image/webp"`), dropzone, dialog `@paste` listener, optional "Paste from clipboard" button, alt-text field, preview thumbnail (with proper `URL.revokeObjectURL` cleanup), and upload error display; (b) **Image URL** mode validated with `isAllowedHref()`; (c) replacing an existing image range when editing an image under the cursor. | Given `ImageDialog.vue`, When a user selects/pastes a valid image and clicks Insert Image, Then it uploads to `vaults.media.store`, inserts `![alt](/media/<filename>)` into CodeMirror, triggers `change`/autosave, and closes cleanly. |
| **FR-09** | Direct Editor Screenshot Paste & Drag-and-Drop | Pasting an image/screenshot (`Ctrl+V`/`Cmd+V` when clipboard contains an image file and no non-empty plain text) or dragging & dropping image file(s) onto CodeMirror uploads the image(s) via `mediaUpload.ts`, shows upload toast feedback, guards against unmount/note-switch races, and inserts `![alt](/media/<filename>)` at the selection or drop coordinates. | Given an editable unencrypted note, When the user pastes a screenshot or drops a PNG onto the editor, Then default browser paste/drop is prevented, the image uploads to `Media/`, and `![screenshot](/media/...)` is inserted and autosaved. |
| **FR-10** | Safe Preview Rendering | Both `/media/...` URLs and external `http(s)://` image URLs render as `<img>` in Preview and Split panes via `markdownPreview.ts`, while `javascript:`, `data:`, and `vbscript:` image `src` values are rejected and `alt`/`title`/`src` attributes are HTML-escaped. | Given `![pic](/media/Work-pic.png "Title")` and `![xss](javascript:alert(1))` in Markdown, When `renderMarkdownPreview()` runs, Then the `/media/...` image renders with escaped attributes and the `javascript:` image emits no `<img>` tag. |

---

## 5. Non-Functional Requirements
- **Security & Authorization**:
  - No raw filesystem functions (`fopen`, `unlink`, `mkdir`, `rename`) in controllers or `MediaService`—all filesystem creation/containment checks route through `FileStorageService` and `StoragePathService`.
  - No absolute OS paths (`C:\Users\...`, `/home/...`) returned in JSON upload responses or error messages.
  - Strict raster MIME allow-list (`image/png`, `image/jpeg`, `image/gif`, `image/webp`) enforced on both upload (content inspection + `getimagesize()`) and serve (`finfo` + `X-Content-Type-Options: nosniff` + `inline` disposition); SVG and active formats explicitly rejected.
  - Same-origin CSRF & `useHttp` integration preserved (including `ProvideVaultKeys` / `X-MDVault-Unlock` header handling on Inertia `http` requests).
- **Performance**:
  - Streamed file writes via `FileStorageService::createFileFromStream()` in 1 MiB chunks (`MAX_UPLOAD_BYTES = 10 MiB`) avoid loading large uploads entirely into PHP strings.
  - `response()->file()` streams stored images directly without reading files into PHP memory.
  - Object URLs created for `ImageDialog.vue` previews are revoked immediately on file replacement, dialog close, and component unmount.
- **Accessibility & UX**:
  - `ImageDialog.vue` uses accessible Reka UI `Dialog` primitives (`DialogTitle`, `DialogDescription`, `Label`, `Input`, `Button`) with a single root element per Vue SFC.
  - Toolbar button includes `title`, `aria-label`, and `:aria-pressed` matching `EditorToolbar.vue` conventions.
  - Keyboard shortcut `Ctrl+Alt+I` (`Mod-Alt-i`) avoids Chromium/Electron's `Ctrl+Shift+I` DevTools collision.
- **Reliability & Data Integrity**:
  - Exclusive file creation (`'x'` mode) prevents overwriting existing attachments even on concurrent uploads.
  - Async paste/drop upload callbacks verify component mount state, `noteUuid` match, and `isInteractive` state before dispatching CodeMirror transactions so navigating away mid-upload cannot mutate another note.
  - Vault renames leave existing media filenames and existing Markdown links intact (future attachments use the new vault name).

---

## 6. Technical Constraints & Context
- Framework: Laravel 13 (`laravel/framework ^13.17`, PHP 8.4)
- Desktop Host: NativePHP Desktop (`nativephp/desktop ^2.3`)
- Frontend: Inertia.js v3 (`@inertiajs/vue3 ^3.0`) + Vue 3 (`^3.5`) + CodeMirror 6 + Tailwind CSS v4 (`@tailwindcss/vite`)
- Routing: Laravel Wayfinder (`laravel/wayfinder ^0.1.14`, `@/routes/`, `@/actions/`)
- Testing: Pest 5 (`pestphp/pest ^5.2`) + Vite+ Test (`vp test run` / `npm run test:js`)
- Static Analysis & Code Style: Larastan/PHPStan Level 7 (`vendor/bin/phpstan analyse`), Laravel Pint (`vendor/bin/pint --dirty --format agent`), `vue-tsc` (`npm run types:check`)
- Database: SQLite (no DB schema changes required; filesystem is source of truth)

---

## 7. Risks & Assumptions
- **Assumption 1**: Users running MDVault in desktop or browser dev have PHP `fileinfo` and standard `getimagesize()` available (standard in PHP 8.4 and `nativephp/php-bin`).
- **Assumption 2**: Image rendering uses the active effective `StoragePathService::mediaDirectory()`. If a user manually changes Storage Root in Settings → Storage without copying their old `Media` folder into the new root, `/media/<filename>` resolves against the new root's `Media` folder (consistent with `StoragePathService` storage-root-resolution semantics).
- **Risk 1 & Mitigation**: *Risk*: A user could have previously created a vault literally named `Media` inside `<rootPath>`. *Mitigation*: `VaultService` blocks creating, renaming into, or registering `<rootPath>/Media` going forward, and `MediaService` checks `VaultService::overlappingVault($mediaDir)` before writing so it never writes inside an existing registered vault.
- **Risk 2 & Mitigation**: *Risk*: Clipboard API (`navigator.clipboard.read()`) requires permissions in some browsers when clicked via a button. *Mitigation*: Clicking "Paste" (`Ctrl+V`) inside either CodeMirror or `ImageDialog.vue` handles standard browser `paste` events directly (`ClipboardEvent.clipboardData`) with zero extra permission prompts, while an optional "Paste from clipboard" button handles permission denials gracefully with a helpful fallback hint.
- **Risk 3 & Mitigation**: *Risk*: Large high-DPI screenshots (>2 MB) fail silently against PHP's default `upload_max_filesize=2M` in NativePHP desktop. *Mitigation*: Configure `upload_max_filesize = 12M` and `post_max_size = 16M` in `NativeAppServiceProvider::phpIni()` and map HTTP `413` cleanly in `mediaUpload.ts`.

---

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`)
