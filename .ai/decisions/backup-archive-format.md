# ADR: Backup archive format (ZIP, layout, manifest, what is included, where backups go)

- **Status**: Accepted (user approved H1, H2, H3, H4, H7, H8, H10 on 2026-09-30)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 6, Backup and Restore (§9, §12, §14, §17, §25, §26, §27, §28, §40–§43, §55, §62; Rules 1–5, 9, 10)

## Context
- §25 asks for Backup All, Backup Vault, Restore, Import and Validate, with ZIP first. §26 shows an example layout (`mdvault.sqlite`, `manifest.json`, `vaults/<Name>/…`) and requires the backup to be self-contained. §27 lists what the manifest must tell us. §28 says validate first and import second.
- Markdown files are the source of truth. SQLite is a rebuildable index (§18, §39). The only data that can't be rebuilt from disk is identity and metadata: vault UUID, name, description, note UUIDs and created dates.
- Runtime: `nativephp/php-bin` 1.2.0 builds for win/mac/linux all include `zip` (`build-meta/build-extensions-*.json`). `nativephp/desktop` requires `ext-zip`. Herd and CI PHP also ship it. `composer.json` doesn't declare it yet.
- `ZipArchive::addFile()` reads source files lazily at `close()`. A hash taken before `close()` can therefore disagree with the archived bytes if a file changes in between. `addFromString()` keeps everything in memory until `close()`.
- `Native\Desktop\Dialog::save()` exists and returns a string path (Electron `showSaveDialogSync`), or empty on cancel. It only works in the desktop runtime.
- Vault folders may contain `.git`, `.obsidian`, `node_modules`, images and other attachments, plus MDVault's own `.mdvault-*` temp files. The indexer ignores dot entries and `node_modules` and never follows symlinks.
- Phase 7 will add encrypted vaults (`vault_encryption`, encrypted names and contents). Its acceptance requires "Backup/restore works with encrypted Vaults".

## Options Considered
1. **ZIP implementation**:
   - (a) **PHP `ZipArchive` (ext-zip, libzip) behind a single `ArchiveService` boundary (chosen)**;
   - (b) a pure-PHP package (e.g. `maennchen/zipstream-php` for writing, plus something else for reading);
   - (c) shelling out to OS tools.

   (b) adds dependencies for no gain. (c) isn't portable or offline-safe.
2. **Registry inside the archive**:
   - (a) a raw copy of the app's SQLite file (`mdvault.sqlite`, as in §26's example);
   - (b) a purpose-built SQLite file containing only the backed-up vaults and notes;
   - (c) **a JSON registry inside `manifest.json` (chosen)**.

   Problems with (a):
   - it embeds machine-specific absolute paths, sessions, cache and jobs;
   - it ties the backup format to the migration history;
   - restoring it means replacing the whole installation, which conflicts with §28 ("must not partially overwrite") and with additive restore;
   - opening an untrusted SQLite file is extra attack surface.

   (b) only makes sense with a SQLite file, and needs PDO to open untrusted files. (c) is human-readable, versioned, validated by schema, and portable.
3. **Which files**:
   - (a) indexed `.md` files only;
   - (b) **every regular file and directory the indexer doesn't ignore (chosen)**;
   - (c) everything, including dot folders.

   (a) silently drops attachments. (c) backs up `.git`, sync metadata and MDVault's own temp files.
4. **Consistency of the written archive**:
   - (a) trust the hashes taken before writing;
   - (b) stage copies first, then zip them;
   - (c) **write with `addFile`, then re-open and stream-hash every entry against the manifest before publishing (chosen)**.

   (b) needs twice the disk space. (c) reuses the restore validator, so every backup is proven readable before it is published.
5. **Destination**:
   - (a) always a fixed backups folder;
   - (b) **the native Save dialog in the desktop runtime, and a default folder `<Documents>/MDVault Backups` in browser dev and tests (chosen)**;
   - (c) a new `backup.directory` setting.
6. **Settings in backups**:
   - (a) **not included in format 1 (chosen)**;
   - (b) portable preferences (theme, editor) included and restored on opt-in.

## Decision
- **Library**: `ZipArchive`. `App\Services\ArchiveService` is the only class that uses it (Pest arch rule). Add `"ext-zip": "*"` to `composer.json` `require` (a platform requirement, not a package; H1).
- **File name**: `MDVault-Backup-YYYY-MM-DD-HHMMSS.zip` (all vaults) or `MDVault-Backup-<Vault name>-YYYY-MM-DD-HHMMSS.zip` (one vault), in the app timezone.
- **Layout (format 1)**:
  ```text
  MDVault-Backup-2026-09-30-143012.zip
  ├── manifest.json            (first entry)
  └── vaults/
      ├── Work/                (vault name; unique case-insensitively, valid portable folder name)
      │   ├── HRMIS.md
      │   ├── Empty/           (every directory has an explicit entry)
      │   └── Projects/CrownTab.md
      └── Personal/Ideas.md
  ```
  There is no `mdvault.sqlite`. This is a deliberate deviation from §26's example (H2). §28 "validate database" becomes "validate the manifest's registry section".
- **Manifest (format 1)**. It is UTF-8 JSON, pretty-printed, with unescaped slashes and unicode. It contains **no absolute paths**. Keys:
  ```json
  {
    "application": "MDVault",
    "format": "mdvault-backup",
    "format_version": 1,
    "database_version": 1,
    "app_version": "0.1.0",
    "created_at": "2026-09-30T14:30:12Z",
    "scope": "all",
    "hash_algorithm": "sha256",
    "vaults": 2,
    "notes": 127,
    "files": 3,
    "total_bytes": 1234567,
    "contents": [
      {
        "uuid": "019…", "name": "Work", "description": null,
        "is_encrypted": false, "encryption": null,
        "archive_path": "vaults/Work",
        "created_at": "…",
        "directories": ["Empty", "Projects"],
        "notes": [{ "uuid": "019…", "relative_path": "Projects/CrownTab.md", "file_size": 18452, "file_hash": "<64 hex>", "modified_at": 1759240000, "created_at": "…", "updated_at": "…" }],
        "files": [{ "relative_path": "assets/logo.png", "file_size": 2048, "file_hash": "<64 hex>", "modified_at": 1759240000 }]
      }
    ]
  }
  ```
  - `vaults`, `notes`, `files` and `total_bytes` are counts and sums, as in §27.
  - `database_version` is the version of the registry schema in `contents`.
  - `format_version` is the version of the archive layout and manifest shape.
  - Hashes are SHA-256 of the **bytes as stored on disk** (the same meaning as `notes.file_hash`).
  - `file_mtime` is never exported (it's derived metadata; ADR `external-change-reconciliation`).
- **Versioning**:
  - A reader accepts `format_version` ≤ its maximum (1) and `database_version` ≤ its maximum (1). A higher value produces "This backup was made by a newer version of MDVault."
  - Unknown keys are ignored within the same version, so later additions can stay backward-compatible.
  - Format 1 requires `is_encrypted: false` and `encryption: null`; otherwise the backup is refused ("needs a newer MDVault").
  - Phase 7 decides whether encrypted vaults bump `format_version`. The design already carries them: stored (encrypted) bytes are copied verbatim, relative paths are the on-disk (encrypted) names, and `encryption` would carry only the non-secret, wrapped key material from `vault_encryption`. **Never a password or unwrapped key.**
- **What is included**: every regular file and directory under the vault folder that the indexer's rules don't ignore. These are skipped:
  - any entry whose name starts with `.` (`.git`, `.obsidian`, `.mdvault-save-*`, `.mdvault-rename-*`, …);
  - directories named `node_modules`;
  - symlinks and junctions (never followed).

  `.md` files (the indexer's rule) are listed under `notes` with their registry UUID. Everything else goes under `files` without a UUID. Directories are all listed and written as explicit entries, so empty folders survive.
- **Backups are all-or-nothing** (they never contain a subset of a vault's files). Any of these fails the backup with a message naming the paths, and nothing is written:
  - an unreadable file or directory;
  - a path that isn't valid UTF-8;
  - a `.md` file without a registry row after the pre-backup Quick reconcile ("the vault changed during backup").
- **Backup All with missing vaults**: vaults whose folder is missing are skipped and named in the result (H10). Backing up a single missing vault is an error.
- **Write procedure** (`BackupService::create`):
  1. validate the destination;
  2. Quick-reconcile each vault;
  3. scan, then hash and size each file;
  4. build the manifest;
  5. `ArchiveService::write()` to a temp sibling `.mdvault-backup-<12 random>.zip` in the destination folder;
  6. **re-open it and run the full validator** (manifest, entry set, sizes, streamed SHA-256 of every entry). Any mismatch discards the temp file and reports "a file changed while it was being backed up; try again";
  7. hash the ZIP;
  8. `FileStorageService::renameFile(temp, target)`, which never overwrites;
  9. insert the `backups` record.

  If the DB insert fails, the error is reported and the backup file is kept (it is valid). The result says it wasn't recorded (Rule 10).
- **Destination rules** (H7):
  - The path must be absolute; `.zip` is appended if missing.
  - The file name must pass `assertValidFolderName` and must not start with `.`.
  - The parent folder must exist and pass the write probe.
  - The target must not exist. MDVault never overwrites, even after the OS dialog's own replace confirmation; the message asks for another name.
  - The target must not be inside any registered vault folder.
  - Desktop: the native Save dialog, pre-filled with `<default backup folder>/<suggested name>`.
  - Browser dev and tests: written directly into `StoragePathService::defaultBackupDirectory()` = `(documentsPath() ?? storage_path('app'))/MDVault Backups`. It is created (non-recursively) when first used and never persisted as a setting.
  - Stale `.mdvault-backup-*` temp files older than 1 h in the destination folder are discarded before writing.
- **`backups` table** (H8) records only backups this installation created successfully:
  `backups(id, uuid unique, scope string(20) ['all'|'vault'], filename string(255), path text (absolute; machine-local metadata), file_size unsigned big int, file_hash char(64) (SHA-256 of the ZIP), format_version unsigned small int, vault_count, note_count, file_count unsigned int, contents json (list of {uuid, name, notes}), timestamps, index(created_at))`.
  - No FK to `vaults`: a record outlives vault removal.
  - Restores are not recorded.
  - Settings UI shows the 20 most recent records, with `exists` computed live.
- **Settings**: not backed up in format 1 (H3). Phase 6 adds no `backup.*` setting keys (`backup.default_format` isn't needed while ZIP is the only format).
- **Enforcement** (Pest arch):
  - `ZipArchive` is used only in `App\Services\ArchiveService`;
  - `App\Services\BackupService` uses no raw filesystem functions;
  - `App\Models\Backup` is used only in services, controllers, models and factories.

## Consequences
- **Positive**:
  - Backups are portable (no absolute paths), human-inspectable and versioned.
  - Every published backup has been read back and hash-verified.
  - Encrypted vaults fit later without a layout change.
  - No new composer packages.
  - Attachments and empty folders survive.
- **Negative / trade-offs**:
  - A deliberate deviation from §26's example layout (no SQLite file in the archive).
  - Every backup reads each file twice (hash, then verify) plus the ZIP once.
  - Synchronous work on the single-threaded desktop server blocks other requests while it runs.
  - Git history and tool config folders (`.git`, `.obsidian`) aren't backed up.
  - The OS Save dialog may offer "replace", which MDVault then refuses.
  - Non-ASCII file names depend on libzip's UTF-8 handling on Windows. This is covered by a test; see the plan's risk R2 for the fallback.
- **Follow-ups**:
  - Phase 7: encrypted vault backup and restore (format decision, `encryption` object, lock state).
  - Optional later: scheduled backups, retention, `tar.gz`, backing up preferences, "back up before removing a vault".
  - **Amendment (QA round 1, `mdv-p6`; revised round 2):** `BackupService::validateArchive()` enforces absolute sanity caps (`MAX_DECLARED_FILE_BYTES`, 10 GiB; `MAX_DECLARED_TOTAL_BYTES`, 100 GiB) and, in Stage 7, a compression-ratio check (`MAX_COMPRESSION_RATIO`, 250:1, applied only to entries over 1 MiB, using `ArchiveService`'s `compressed_size`), independent of free space and before the Stage 8 integrity hashing described in ADR `backup-restore-semantics`.
  - **Amendment (QA round 3, `mdv-p6`, QA-05):** the Stage 7 ratio check above is a best-effort heuristic, not a bound. Its real, verified bypass is sub-floor chunking — splitting a payload into entries each at or under the check's 1 MiB floor evades it with entirely genuine ZIP metadata, no forgery needed. (Directly forging the central directory's `compressed_size` — also raised this round — was tested against this codebase's actual read path and does not work: `ArchiveService` always opens with `ZipArchive::CHECKCONS`, and libzip's consistency check rejects *any* mismatch it finds between the central directory's record for an entry and that entry's own local file header — in either direction, not only an inflated one — so an inflated or deflated forgery is both rejected as "not a valid ZIP" before the manifest is even read; QA round 4 confirmed this empirically for both directions, the deflated case failing with `ER_INCONS`.) The real, unconditional bound is a separate aggregate gate (Stage 7.5) that compares the manifest's declared total against the archive's real on-disk size (via `FileStorageService::size()`, not the ZIP's own metadata), plus Stage 8's existing per-entry `hashStream()` cap. See ADR `backup-restore-semantics`'s round 3 amendment for the full rationale; don't rely on this ADR's earlier wording that the ratio check itself "bounds worst-case Stage 8 work."

## Amendment (Phase 7, 2026-10-03)
- Follow-up: format 2, see ADR `encrypted-vault-backups`.
