# ADR: Shared Media Folder Inside MDVault Root & Unencrypted-Vault-Only Policy

- **Status**: Accepted
- **Date**: 2026-11-17
- **Phase**: Image Attachment & Shared Media Storage (`feat-media-attachments`)

## Context
Users want to insert images and screenshots into Markdown notes via a rich-text toolbar button, paste, and drag-and-drop, with every attached file saved in a media folder inside the MDVault root folder (`StoragePathService::rootPath()`) and named with the vault name.

Key constraints from MDVault's architecture:
1. `StoragePathService::rootPath()` holds vaults created under MDVault root (default `Documents/MDVault`), while users can also register vaults located outside `rootPath()` via "Add existing folder".
2. Notes can live in nested subfolders (`Projects/Sub/Note.md`) and can be moved or renamed without rewriting Markdown body content.
3. `markdownPreview.ts` validates image `src` attributes with `isAllowedHref()` (`http:`, `https:`, `mailto:`, or scheme-less relative references) and runs inside an `http://127.0.0.1:<port>` (NativePHP) or `http://mdvault.test` (Herd) web context where `file://` URLs are blocked by Chromium.
4. Encrypted vaults follow ADR `encrypted-vault-key-custody` and ADR `encrypted-vault-storage-layout`: plaintext content must never reach disk or unauthenticated endpoints, unlock tokens are passed only via the `X-MDVault-Unlock` header on Inertia `http` calls (never sent by native HTML `<img src="...">` tags), and "Attachments in encrypted vaults" is an explicit future follow-up.
5. Vaults can be renamed (`VaultService::rename()`), which renames the vault folder on disk without editing note contents.

## Options Considered
1. **Media folder location and URL format**
   - **(a) Single `<rootPath>/Media` folder served via `/media/{filename}` (chosen)**: Every vault (whether inside `rootPath()` or added via "Add existing folder") writes to one deterministic media directory `<rootPath>/Media/`, using filenames prefixed with the sanitized vault name (`<vault-slug>-<stem>-<Ymd-His>-<8hex>.<ext>`). Markdown inserts `/media/<filename>`.
   - **(b) Per-vault `assets/` folders inside each vault**: Rejected because it contradicts the user requirement ("saved on a folder inside MDVault root folder that should serve as our media folder for all attached files, naming should contain the vault name") and breaks relative links whenever notes move between subfolders.
   - **(c) SQLite `media` registry table mapping UUIDs to file paths**: Rejected as unnecessary complexity; Markdown + filesystem are MDVault's source of truth, and database resets (`resetRegistry()`) must never orphan or break image links.

2. **Vault rename behavior for existing media files**
   - **(a) Keep existing media filenames intact on vault rename; new attachments use the new vault name (chosen)**: Zero risk of breaking existing Markdown image links or mutating note files during a vault rename.
   - **(b) Rename all matching media files and rewrite all `.md` files in the vault**: Rejected; mutating note bodies during `VaultService::rename()` violates atomic single-concern semantics and risks corrupting unsaved open notes.

3. **Encrypted vaults**
   - **(a) Disallow local media uploads for encrypted vaults with explicit server 422 + UI explanation (chosen)**: Prevents silently leaking sensitive screenshots as unencrypted PNGs inside `<rootPath>/Media/`. External `http(s)://` URLs remain insertable via `ImageDialog`.
   - **(b) Store plaintext images for encrypted vaults**: Rejected — violates `encrypted-vault-storage-layout` confidentiality guarantees.
   - **(c) Encrypt media files in `Media/` and intercept `<img>` loads via JS `fetch` + `X-MDVault-Unlock` + `blob:` URLs**: Deferred to the dedicated encrypted-attachments follow-up; stored ciphertext files would not be viewable image files in the user's `Media` folder and require dedicated encryption format design.

4. **Allowed image formats**
   - **(a) Raster allow-list only (`png`, `jpg`, `gif`, `webp`) verified via magic-byte MIME + `getimagesize()` (chosen)**: Blocks SVG (`image/svg+xml`) and HTML/XML active content from stored XSS when served inline under MDVault's origin.
   - **(b) Allow any `image/*` including SVG**: Rejected due to script execution risk in inline SVG documents.

## Decision
- Define `StoragePathService::MEDIA_FOLDER_NAME = 'Media'` and `StoragePathService::mediaDirectory()`, created lazily on first upload.
- Guard `<rootPath>/Media` in `VaultService` (`create`, `register`, `rename`, `remove`) so no vault can overlap or be registered on the `Media` directory, and guard `MediaService::store()` against any pre-existing overlapping vault.
- Store uploaded images exclusively (`FileStorageService::createFileFromStream()`) using `<vault-slug>-<stem>-<Ymd-His>-<8hex>.<ext>` and serve them from `GET /media/{filename}` with strict canonical containment, symlink rejection, MIME re-verification, `Content-Disposition: inline`, and `X-Content-Type-Options: nosniff`.
- Reject local image uploads for encrypted vaults (`is_encrypted === true`) with HTTP 422 and disabled upload/paste/drop UI with a clear notice.

## Consequences
- **Positive**:
  - All attached images across all vaults live cleanly in `<rootPath>/Media/` and are clearly identifiable by their vault-name prefix.
  - Notes remain portable across subfolder moves, note renames, vault renames, and SQLite database resets.
  - Zero XSS exposure from SVG/HTML uploads and zero plaintext leakage from encrypted vaults.
  - No database migrations or new dependencies required.
- **Negative / trade-offs**:
  - Deleting or renaming a note does not automatically delete unused files in `Media/` (orphaned media cleanup can be added later).
  - Existing vault backups (`BackupService`) archive individual vault directories only and do not yet archive the shared root `Media/` folder (`FORMAT_VERSION = 3` follow-up).
  - Encrypted vaults cannot attach local images until the encrypted-attachments follow-up is implemented.
