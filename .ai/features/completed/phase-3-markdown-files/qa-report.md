# QA Report: Phase 3: Markdown Filesystem

## Metadata
- **Feature**: Phase 3: Markdown Filesystem (mdv-p3)
- **Round**: 1
- **Reviewer**: Senior QA Engineer
- **Branch**: `phase-3-markdown-files` (uncommitted, baseline `2500c0e`)
- **Verdict**: **PASS**

---

## 1. Verification Performed

| Command | Result |
|---|---|
| `php artisan test --compact` (targeted scope: FileHashService, FileStorageService, VaultIndexService, NoteService, Notes/, Models/NoteTest, DatabaseSchemaTest, ArchitectureTest) | 171 tests, 167 passed, 404 assertions, 4 skipped (Windows session) |
| `php artisan test --compact` (full suite) | 421 tests, 415 passed, 1163 assertions, 6 skipped — matches `implementation.md` §3 exactly |
| `php vendor/bin/phpstan analyse` (level 7) | 0 errors |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean |
| `npm run check` | "All 61 files are correctly formatted"; "Found no warnings or lint errors in 54 files" |
| `npm run build` | Succeeds |
| `php vendor/bin/pint --dirty --format agent` | Passed |
| `php artisan route:list --path=notes` | 5 routes (4 `/notes/...` + `vaults.notes.store`, which also matches `--path=vaults`) |
| `php artisan route:list --path=vaults` | 12 routes: 4 Phase 2 + 8 new, matching plan §2 |
| `rg "content" database/migrations/*notes*` | No matches — no content column |
| `rg "hash_file\|file_get_contents\|fopen\|unlink\|rmdir\|->move(\|moveDirectory" app` | Only `FileHashService` and `FileStorageService`, plus the expected `NoteController.php:45` false positive (`NoteService::move()`, not filesystem) |
| `rg "unlink" app/Services` | Only `FileStorageService::deleteNewEmptyFile` |
| `rg "v-html" resources/js/components/notes resources/js/pages/Workspace.vue` | No matches |
| `rg "moveDirectory\([^)]*true" app` | No matches |

All commands and grep results match what `implementation.md` §3 claims — no discrepancies found.

---

## 2. Requirements Coverage (FR-01 → FR-22)

Traced every FR against code and tests. All 22 functional requirements are implemented and tested to the acceptance criteria in `requirements.md`. Highlights of deep verification:

- **FR-01/02** (`notes` schema, `Note` model): migration is exactly the §2 schema, no `content` column, `HasUuids` on `uuid` only, `id`/`vault_id` hidden, cascade FK. `DatabaseSchemaTest.php` and `NoteTest.php` cover this exactly.
- **FR-05/06/07/09** (re-index): `VaultIndexService::reindex()` (`app/Services/VaultIndexService.php:42`) implements the exact-path → unique-case-insensitive-path → unique-hash matching order, one transaction (deletes → updates/moves → inserts), unreadable-file/-directory handling, and idempotence — verified against `VaultIndexServiceTest.php`'s 16 scenarios (initial index, ignore rules, idempotent, external edit/delete/create/rename/case-rename/rename+edit, ambiguous-hash non-pairing, rebuild, missing vault, symlink, Unicode, browse, summary text). All ran green.
- **FR-10 to FR-16** (note/folder operations): `NoteService` (`app/Services/NoteService.php`) follows the exact order specified in plan T5 for `create`, `relocate` (rename/move), `delete`, `createFolder`, `deleteFolder`, including compensation (delete-new-empty-file on DB-insert failure; rename-back on DB-update failure, with `moveRollbackFailed` + `report()` on double failure). `NoteServiceTest.php`'s 41 scenarios (including DB-failure and rollback-failure cases using `Note::saving`/`failFileMoves`/`Exceptions::fake()`) all pass.
- **FR-17/18** (tree/viewer): `VaultIndexService::browse()` merges a live directory scan with registry notes, folders-first natural sort, `open` flag computed server-side; `WorkspaceController` exposes `tree`/`folders`/`note` as lazy closures with `browse()` memoised per-request so a `notes.show` partial reload (`only:['note']`) never rescans (verified: `NoteTreeItem.vue`'s note `<Link>` uses `:only="['note']"`).
- **FR-19** (path safety): `NoteService::resolveFolder()` rejects `..`, `.`, empty segments, backslashes, drive letters, leading `/`, NUL, and walks every segment checking `isSymlink()`. Tested with a full invalid-folder dataset plus a symlink-escape test (`->skipOnWindows()`).
- **FR-21** (identity exposure): routes use `whereUuid` on every parameter; `NoteManagementTest.php` asserts `/notes/1` and an unknown UUID both 404, and `note.id`/`note.vault_id` are `->missing()` from Inertia props.
- **FR-22** (boundaries): the two new arch rules (`hash_file` only in `FileHashService`; no raw filesystem functions in `VaultIndexService`/`NoteService`) are in place and pass, matching plan T4/T9 exactly.

No FR is missing a test. No FR is implemented in a way that contradicts its acceptance criteria.

---

## 3. Data Safety and Architecture Review (priority items)

- **No note content in SQLite**: confirmed — no `content` column, and `NoteService::preview()`/`present()` never write content anywhere but the returned array; `WorkspaceController` never stores content elsewhere.
- **No overwrite / no permanent delete**:
  - `createFile()` uses `fopen($path, 'x')` (exclusive) + `is_file()` check afterwards.
  - `renameFile()` pre-checks the target (`exists && !isSameFile`) and checks afterwards; case-only renames go through a `.mdvault-rename-*` hidden sibling, restored on step-2 failure.
  - `NoteService::delete()` uses `FileStorageService::moveToTrash()` (OS Recycle Bin via the `Trash` contract), checks `!file_exists` afterwards, only then deletes the DB record; on failure the record is kept.
  - The only `unlink` call in `app/Services` is `deleteNewEmptyFile()`, gated on `is_file && filesize === 0`.
  - `deleteFolder()` refuses non-empty directories (`isEmptyDirectory` checks all entries including hidden ones) via `FilesystemIterator` with no filter.
  - All confirmed by code reading and by the grep results above and the corresponding passing tests.
- **Path safety**: `resolveFolder()` rejects `..`, absolute paths, drive letters, backslashes, NUL, and walks every path segment for symlinks; `scan()` calls `isLink()` before any other check and never recurses into a symlinked directory; dotfiles and `node_modules` are skipped in both `reindex()` and `browse()`.
- **Re-index correctness**: verified the transaction applies deletes → updates/moves → inserts in that literal order (`app/Services/VaultIndexService.php:193-205`), matching the ADR's proof that the unique `(vault_id, relative_path)` index can't be violated (a move target never has an exact-match row left after deletes). Hashes/sizes are correctly updated on exact-match mismatches; missing files are removed unless under an unreadable directory (kept as `skipped`). Idempotence is tested (`updated_at` implicitly unchanged since no update is queued when unchanged).
  - **Dev-flagged subtlety** (case-insensitive-path step pairs on path alone, so an external case-rename + edit keeps the UUID and silently adopts the new hash): I traced this against both the ADR (`note-registry-and-indexing.md` §"Decision" step 4: "Match: exact path → unique case-insensitive path → unique identical hash... Only 1:1 matches are paired") and the plan's algorithm (T4 step 6, which requires only "exactly one row, exactly one file" at the case-insensitive stage, with no hash comparison). This is a literal, faithful implementation of the approved design, not a coding defect. It is a narrower version of the already-documented and user-approved trade-off ("Rule 8... an external rename plus an edit gets a new UUID" is explicitly scoped to a *different* path in E6, not a case-only one). **Assessment: acceptable per the ADR — not a defect.** Recommend flagging it for a future ADR addendum/note (informational only, not blocking).
- **DB/filesystem ordering and compensation**: every operation (`create`, `relocate`, `delete`, `createFolder`, `deleteFolder`) follows file-op → check → DB-write, with the correct single-direction compensation, matching the ADR exactly.
- **Error field routing**: `NoteOperationException::field()` + each controller's private `attempt()` maps to `ValidationException::withMessages([$field => message])`, consistent with the Phase 2 `VaultController` pattern.
- **Form Request validation**: present on every endpoint (`StoreNoteRequest`, `UpdateNoteRequest`, `MoveNoteRequest`, `StoreFolderRequest`, `DestroyFolderRequest`), all `authorize(): true` (no auth, per ADR `local-app-without-authentication`), shared `NoteNameRules` trait delegates portable-name checks to `NoteService` (single source of truth).
- **UUIDs only to the frontend**: confirmed — `$hidden = ['id','vault_id']` on `Note`, `whereUuid` on every route parameter, `note.id`/`note.vault_id` explicitly asserted absent in `NoteManagementTest.php`.
- **Trash endpoints outside desktop runtime**: `NoteService::delete()` correctly follows the plan's literal step order (already-missing check happens *before* `canTrash()`), so a browser-runtime delete of an already-missing file succeeds (record removed), while deleting an *existing* file without trash correctly throws `trashUnavailable` on field `note`. This matches the plan's T5 delete steps verbatim — **not a defect**, despite the developer's own flag in `implementation.md` §5 questioning it.

---

## 4. Vue / Frontend Review

- All components reviewed (`NoteTree.vue`, `NoteTreeItem.vue`, `NoteViewer.vue`, all 6 dialogs, `Workspace.vue`) are single-root and use text interpolation only — `rg "v-html"` confirms no matches.
- No path splitting/joining logic in any Vue component; relative paths (`node.path`, `note.relative_path`, `note.folder`) are always opaque strings passed through or displayed verbatim.
- `TiptapEditor.vue`: `git diff --stat` confirms zero changes.
- Wayfinder imports (`@/routes/notes`, `@/routes/vaults/notes`, `@/routes/vaults/folders`, `@/routes/vaults`) are used throughout; no hardcoded URLs found.
- **MoveNoteDialog does not preselect the current folder** (assessed per instructions): the plan's own prop signature for `MoveNoteDialog` is `(note, folders)` only — unlike `CreateNoteDialog`/`CreateFolderDialog`, which take an explicit `defaultFolder`/`defaultParent`. `NoteTreeNote` (the type passed to `MoveNoteDialog`) only carries `path` (the full relative path including filename), and deriving "current folder" from it client-side would require path-splitting in Vue, which the architecture explicitly forbids. Given the current server-side node shape, not preselecting is the architecturally correct choice, not an oversight. **Recommendation (Low, non-blocking)**: a follow-up could add a `folder` field to `NoteTreeNote` server-side (mirroring `NoteDetail.folder`) so `MoveNoteDialog` can preselect without any client-side path logic — a UX polish item, not a defect.

---

## 5. Issues Found

| ID | Severity | Classification | Description | Fix Attempts |
|---|---|---|---|---|
| QA-P3-01 | LOW | Follow-up (System Analyst / future phase note) | `MoveNoteDialog.vue` doesn't preselect the note's current folder, because `NoteTreeNote` lacks a `folder` field and deriving it client-side would violate the "no path splitting in Vue" rule. Recommend adding `folder` to the tree-node shape in a later pass so the dialog can preselect without client path logic. Not a defect against the approved plan (plan's own prop list omits `defaultFolder` for this dialog). | 0 |
| QA-P3-02 | INFORMATIONAL | No action required | `VaultIndexService::reindex()`'s case-insensitive matching step pairs on path alone (no hash check), so an external case-only rename combined with a content edit keeps the UUID and silently adopts the new content's hash/size. This is a faithful, literal implementation of ADR `note-registry-and-indexing` (§Decision step 4) and the plan's T4 algorithm — not a coding defect. Recommend the analyst add one sentence to the ADR's "Consequences" section making this narrower case explicit (currently the ADR only calls out "rename **plus a different path** plus an edit" as losing the UUID). | 0 |

No MEDIUM, HIGH, MAJOR, or CRITICAL issues found. Zero issues require Developer fixes before this can move to `completed/`.

---

## 6. Manual Desktop Checks (not defects — user-verified)

Per plan T9, the following require `composer native:dev` and were correctly **not** attempted in this sandboxed session (no native shell available), same constraint as prior phases:
- **M1**: Add existing folder with nested `.md`, `.git`, `.obsidian` → tree/toast correctness, dot-folders hidden.
- **M2**: New note "Meeting" → appears in Explorer; viewer shows "This note is empty."
- **M3**: External edit in VS Code → new text and hash on reopen.
- **M4**: New folder + move note → Explorer reflects it; URL (UUID) unchanged.
- **M5**: Rename, then case-only rename → Explorer reflects both.
- **M6**: External rename (content unchanged) + Re-index → same UUID under new name.
- **M7**: Delete → appears in Recycle Bin; restore + re-index → new UUID (expected).
- **M8**: Delete empty folder succeeds; non-empty folder delete refused.
- **M9**: Vault rename with a note open → note still opens, tree intact (no held handles).
- **M10**: ~2,000-file vault → measure open time (target: a few seconds); note clicks don't rescan.

---

## 7. Verdict

**PASS.** Zero open Critical/High/Major issues. Two Low/Informational follow-ups noted (QA-P3-01, QA-P3-02), neither blocking. All requirements FR-01–FR-22 are implemented per spec and covered by passing tests; all quality gates (Pest full suite, PHPStan level 7, types:check, build, check, Pint, route:list, greps) pass cleanly and match the developer's own reported results exactly, so no discrepancies between `implementation.md`'s claims and the actual repository state were found.

**Routing**:
- No issues require the Developer.
- QA-P3-01 and QA-P3-02 are informational/follow-up items for the System Analyst to consider noting in a future ADR revision or Phase 4/5 backlog — they do not block completion of this feature.
- Recommend: move `.ai/features/active/phase-3-markdown-files/` to `.ai/features/completed/`, then ask the user to run `php artisan test --compact` and, when convenient, the manual desktop checks M1–M10.
