# ADR: The open note and external changes (notification, conflict UX)

- **Status**: Accepted; delivered in Phase 5 (G4, G5, G7 approved 2026-09-30; signed off 2026-09-30)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 5, Filesystem Intelligence (§24 "Reload from Disk / Keep Current Editor / Compare", §42, §54 "does not silently overwrite external changes"; Rules 5, 8)
- **Extends**: ADR `note-save-atomic-replace` (`noteSaver` conflict states `changed` | `missing`, Keep mine = re-send with the disk hash)

## Context
- The editor's `noteSaver` already handles save-time conflicts:
  - a 409 `changed` gives Reload / Keep my version / Copy my text;
  - a 409 `missing` gives Copy / Re-index.

  Before Phase 5, the editor only learned about an external change when it tried to save.
- Autosave runs 1.5 s after the last keystroke (at most 10 s), so an open note is "dirty" only briefly, but a conflict can still be reached from either side.
- The Workspace re-renders on Inertia visits. `useUnsavedChangesGuard` flushes and then freezes the editor before **every** visit, including tree-only partial reloads. The Workspace keys `NoteEditor` on `uuid:base_hash`, so a changed `base_hash` remounts the editor.
- Check results can race saves: a check that started before a save completed reports the pre-save hash.

## Options Considered
1. **How the UI learns about changes**:
   - (a) Inertia `usePoll` on the page (it reloads props, remounts the editor, and runs through the guard);
   - (b) NativePHP event broadcast (desktop-only);
   - (c) **a JSON check (`useHttp`), followed by targeted partial reloads only when needed (chosen)**.
2. **Clean note changed externally**:
   - (a) always ask;
   - (b) **reload silently with a toast (chosen)**. Nothing can be lost, and asking would nag users who edit in two tools.
3. **Dirty note changed externally**:
   - (a) keep autosaving (the next save 409s anyway);
   - (b) **enter the existing `changed` conflict proactively and pause autosave (chosen)**.
4. **Additional resolutions**:
   - (a) none;
   - (b) **"Save mine as a new note" plus "Compare" (chosen)**;
   - (c) a three-way merge (a version store would be needed: out of scope, §45).
5. **Deleted open note**:
   - (a) keep the record while open (the registry lies);
   - (b) **the record follows the disk; the editor closes if clean, or shows the `missing` banner with "Save as a new note" if dirty (chosen)**.

## Decision
- **Transport**:
  - `useExternalChanges` (Workspace) posts `{open_note}` to `vaults.changes.check`.
  - If `tree_signature` differs from the prop, `router.reload({ only: ['tree', 'folders', 'treeSignature'] })`.
  - `unavailable` or a returning vault → a full `router.reload()`.
  - The open-note part goes to `NoteEditor.applyExternalStatus(token, remote)`.
- **Guard exemption**: `isEditorSafeVisit` means a same-URL GET partial reload whose `only` list is non-empty and excludes `note` is not intercepted (no flush, no freeze). Any visit touching `note` is still guarded.
- **Race protection**:
  - `NoteEditor` keeps a save epoch; a check is skipped while a save is in flight, and a result whose token no longer matches is ignored.
  - The local base hash is the saver's chained `baseHash` (falling back to the props' `base_hash` or `file_hash` for read-only states).
- **Decision table** (`classifyOpenNote` → `decideOpenNoteAction`):
  - unchanged → none (or `resume` if a conflict was pending: the file is back or the change was undone);
  - moved (same hash) → `refresh` (partial reload of note, tree, folders and signature; same key, no remount; a pending conflict → `resume`);
  - changed (± moved):
    - clean → `reload` (discard, toast "was changed outside MDVault and has been reloaded", remount through the new `base_hash`);
    - dirty or in conflict → `externalConflict('changed', diskHash)`;
  - deleted:
    - clean → `leave` (toast, visit the Workspace root);
    - dirty → `externalConflict('missing')`;
    - already `missing` → none.
- **`noteSaver`**:
  - `externalConflict(reason, hash, message)` pauses autosave and keeps the edit pending. It is ignored while a save is in flight.
  - `resume()` clears the conflict and flushes.
  - `overwrite()` (Keep mine) is unchanged: it re-sends with the disk hash, so a further change still 409s.
- **Banner actions**:
  - `changed`:
    - Reload from disk (confirm);
    - Keep my version (confirm: "The changes made outside MDVault will be lost.");
    - **Save mine as a new note**;
    - **Compare**;
    - Copy my text.
  - `missing`:
    - **Save as a new note**;
    - Copy my text;
    - **Check again** (or Re-index when checks are off);
    - **Discard my edits** (confirm).
- **Save mine as a new note**:
  - `POST /vaults/{vault}/notes/copy` with `{source_path, content, mode, has_frontmatter, frontmatter}` → `NoteService::createCopy`.
  - Naming:
    - the original name if its path is free (the deleted case);
    - otherwise `<stem> (my version)`, then `(my version 2)` … `(my version 20)` (stem truncated to 100 characters);
    - the vault root when the folder is gone.
  - Exclusive create only; the original's EOL/BOM are kept when it is readable; exact-bytes compensation on a DB failure.
  - The client then opens the copy.
- **Compare**:
  - `GET /notes/{note}/disk` returns the current disk text through `preview()`.
  - `NoteCompareDialog` shows `diffLines(disk, mine)` (jsdiff, G5) as interpolated text lines (no `v-html`).
  - "Mine" in Rich mode is the full-file preview text (`richContentAsText`), so reformatting differences may appear.
- **Orphan notice**: a dismissible Workspace notice lists `orphan_temp_files` (G7). No actions; the files are never touched.
- **Vue remains presentation**: path strings are only passed back as received. Classification and scheduling live in framework-free modules with tests.

## Consequences
- **Positive**:
  - External changes are visible within seconds, and clean notes simply follow the disk.
  - External changes are never overwritten without a confirmed choice.
  - Both versions can always be kept, via a copy.
  - Moves are seamless for the editor.
  - The existing Phase 4 conflict machinery is reused rather than duplicated.
- **Negative / trade-offs**:
  - An auto-reload resets the selection and scroll of a clean note.
  - The basename identity heuristic may present an unrelated same-name file as "moved and changed"; the banner shows the new path, and Keep mine still needs confirmation.
  - Copies of Rich-mode edits may re-serialise formatting (the same consent as the original save).
  - There is no three-way merge.
- **Follow-ups**:
  - An orphan-recovery action (restore as note / trash) if requested.
  - A merge UI only with a future version store (v2, explicitly out of scope now).
  - AR-01 (delivered): a partial reload of `notes.show` for a uuid whose record no longer exists renders the Workspace with `note = null` (full visits still 404), so tree refreshes while the `missing` banner is shown never error. Background (async) reloads do not pause or re-trigger the checker.
  - The editor remounts on a "moved" refresh when a save happened since mount (key `uuid:base_hash`), resetting cursor/scroll (F2).
