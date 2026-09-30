# ADR: Note registry, indexing and stable note identity

- **Status**: Proposed (Phase 3 plan; pending user approvals E3, E4, E5, E6, E8)
- **Date**: 2026-09-28
- **Phase**: Master Plan Phase 3, Markdown Filesystem (§13–18, §38, §39, §52; Rules 1–4, 8, 9)

## Context
- The filesystem is the source of truth for note content (§16, §18). SQLite is a rebuildable index (§39).
- §13 defines `notes` with `relative_path` (§14) and a SHA-256 `file_hash` (§17). §15 forbids a `folders` table.
- External tools create, edit, rename and delete files at any time (Rule 8). Phase 3 has no watcher (Phase 5).
- UUIDs must be stable (§12, Rule 4) for Phase 6 backups and future sync. The only way to recognise a file across an external rename, without writing into it, is its path or its content.
- Vaults live on one volume. There is one user and one instance (§44).

## Options Considered
1. **Identity across re-index**:
   - (a) path only;
   - (b) path, then case-insensitive path, then unique content hash (chosen);
   - (c) an `id:` in YAML frontmatter;
   - (d) a sidecar file `.mdvault/ids.json`.

   (c) and (d) modify or pollute the user's portable folders and create a second source of truth. (a) loses identity on every external rename.
2. **Folder representation**:
   - (a) registry-derived only;
   - (b) a live directory scan unioned with registry-derived parents (chosen);
   - (c) a folders table (forbidden by §15).
3. **When to index**:
   - (a) on every Workspace render;
   - (b) on vault open and create/add plus a manual action, with per-operation updates (chosen);
   - (c) a background job (deferred to Phase 5).
4. **Which files**: `.md` only, compared case-insensitively. Dot entries and `node_modules` are ignored, and symlinks are never followed (chosen). Alternatives: include `.markdown`, or follow symlinks (risk of cycles and escaping the vault).
5. **Title**: the filename stem (chosen), or the first heading (it would require parsing, and it diverges from what the user sees in Explorer).

## Decision
- **Schema**:
  - `notes(id, uuid unique, vault_id FK cascade, title, filename, relative_path text, extension, mime_type default text/markdown, file_size, file_hash char-64, is_encrypted default false, timestamps)`;
  - `unique(vault_id, relative_path)`, `index(vault_id, file_hash)`;
  - **no content column, ever**.
  - `relative_path` is `/`-separated on every OS. The absolute path is always `vault.path + relative_path`, computed at runtime and never stored.
- **Indexable**: a regular file with extension `md` (any case). Skipped:
  - any entry whose name starts with `.` (this also hides MDVault's own `.mdvault-*` temp names and write probes);
  - directories named `node_modules`;
  - every symlink or junction (not followed, not indexed).
- **Re-index algorithm** (`VaultIndexService::reindex`):
  1. The vault root must be a readable directory; otherwise abort with nothing changed.
  2. Scan. Record unreadable directories.
  3. Hash every file (streamed). An unreadable file is skipped and its record kept.
  4. Match: exact path → unique case-insensitive path → unique identical hash. Only 1:1 matches are paired, so an ambiguity becomes a delete plus an insert.
  5. Leftover records under an unreadable directory are kept. Other leftovers are deleted. Leftover files are inserted with new UUIDv7s.
  6. Apply everything in one DB transaction in the order deletes → updates/moves → inserts.

  *Why the unique index can't be violated*: a move target is a scanned file path that had no exact-match record. A leftover record never holds a scanned file path (it would have matched exactly). So after the deletes, no row holds a target path.
- **Triggers**:
  - after vault open, create and add-existing (controllers call `reindex`);
  - the manual "Re-index" route;
  - NoteService operations update their own record;
  - opening a note re-hashes that one file and updates size and hash if they differ (the filesystem wins).
  - GET requests never run a full re-index.
- **Tree**: `VaultIndexService::browse()` merges a directory-only scan (same ignore rules; empty folders visible) with registry notes. Sorting is folders first, then natural case-insensitive order. It is exposed as lazy closure props, so partial reloads skip it.
- **Title**: the filename without its last extension.

## Consequences
- **Positive**:
  - The registry is fully rebuildable from disk (Rule 9).
  - UUIDs survive in-app operations always, and external renames/moves when the content is unchanged.
  - User files are never annotated.
  - Empty folders are visible without a table.
- **Negative / trade-offs**:
  - An external rename plus an edit, or a duplicated-content ambiguity, gives a new UUID.
  - The narrower case of an external **case-only** rename combined with an edit keeps the UUID: the case-insensitive path step pairs on the path alone (no hash check) and adopts the new hash and size.
  - A vault open re-hashes every file (cost grows linearly with vault size, and it is synchronous).
  - Every Workspace full render walks the directory tree.
  - Stale records (files deleted externally) remain until the next open or re-index; the viewer shows a "missing" state.
  - macOS NFD/NFC name differences rely on hash matching.
- **Follow-ups**:
  - Phase 5: a watcher-driven incremental index, rename events for identity when the content has changed, an optional mtime/size shortcut, and background indexing with progress.
  - Phase 5 (delivered, ADR `external-change-reconciliation`): `reconcile()` with Quick/Full modes and the `notes.file_mtime` shortcut, a fourth pairing step (unique file name), a stale-registry guard, and a tree signature. Vault open/create/add now reconcile in Quick mode; manual Re-index stays Full.
  - Phase 6 (delivered, ADRs `backup-archive-format`, `backup-restore-semantics`): backups export uuid + relative_path + file_hash; restore re-creates records with their original UUIDs (or new ones for a copy), then runs a Full reconcile.
