# ADR: Detecting external changes (mechanism, triggers, scope)

- **Status**: Accepted; delivered in Phase 5 (G1, G6, G8 approved 2026-09-30; signed off 2026-09-30)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 5, Filesystem Intelligence (§24, §44, §54; Rules 8, 9, 10; §64 out-of-scope list)

## Context
- §54 asks to "implement filesystem watching" and to detect create / modify / rename / move / delete. §24 sketches "Filesystem watcher → change detected → hash recalculated → SQLite updated → UI notified", and says the exact UX "can be refined during Phase 5". §4 prefers established libraries for filesystem watching.
- Runtime: `nativephp/desktop` 2.3.1 (Electron).
  - It has no file-watch API.
  - `ChildProcess::node()`/`php()` can run a long-lived script. Its stdout reaches Laravel as `ChildProcess\MessageReceived` over HTTP.
  - Laravel events on the `nativephp` channel are forwarded to the renderer (`window.Native.on`).
  - `chokidar@3.6` is present in the Electron bundle only as a dependency of the dev tool `nodemon`, so it isn't a production dependency.
- The PHP server is `php -S`, which is single-threaded. Browser dev (Herd) has no NativePHP runtime.
- Windows: a recursive directory watch (`ReadDirectoryChangesW`, used by `fs.watch`) keeps a handle on the watched directory. That prevents renaming or trashing the vault folder, which MDVault itself does (ADR `vault-removal-and-rename-semantics`). ADR `note-file-operations` explicitly asked Phase 5 to address this.
- Watcher events are hints, not truth. They can overflow, be dropped on network or removable drives, arrive duplicated, or split a rename into delete + create. A reconcile scan is needed regardless.
- Existing safety nets: opening a note re-hashes it (`NoteService::preview`); saving is guarded by a base hash (ADR `note-save-atomic-replace`); a manual full Re-index exists.

## Options Considered
1. **Native watcher**: a Node `fs.watch({ recursive: true })` or chokidar script run as a NativePHP child process, posting events to Laravel, which reconciles and broadcasts to the window.
   - Pros: near-instant.
   - Cons:
     - a new process lifecycle (start, stop, restart per vault);
     - it blocks vault rename/trash on Windows;
     - desktop-only;
     - untestable with Pest;
     - still needs a scan to be correct;
     - chokidar would have to be shipped inside the Electron bundle.
2. **Server-scheduled polling** (NativePHP runs the scheduler, or a queue loop). There's no direct channel to the UI except native events; it's desktop-only and hard to test.
3. **Renderer-driven polling plus event triggers (hybrid) (chosen)**:
   - the Workspace asks the server to run a quick reconcile on mount, on window focus/visibility and every 5 s while visible;
   - the safety nets above stay;
   - the manual Re-index hashes everything.
4. **(3) plus a native watcher as an additional trigger**: deferred. It is the upgrade path if (3) proves too slow in practice.

## Decision
- **Mechanism**: option 3. One server operation, `ExternalChangeService::check()` → `VaultIndexService::reconcile(Quick)`, exposed as `POST /vaults/{vault}/changes`. Detection latency is ≤ 5 s while the window is visible, and immediate on focus.
- **Triggers** (client, `changeChecker`):
  - Workspace mount;
  - `window` `focus`;
  - `visibilitychange` → visible;
  - every 5 s while visible;
  - an explicit request from the editor after a 409 `missing`.

  There is one check in flight at a time, with a 250 ms trigger debounce.
- **Load control**:
  - delay after a check = `clamp(max(5 s, 10 × duration), 5 s, 60 s)`;
  - errors back off exponentially up to 60 s;
  - checks pause while the document is hidden, during Inertia visits, and while a save is in flight.
- **Always-on safety nets**, independent of the setting: note-open verification, the save guard, manual Full Re-index, and a Quick reconcile on vault open.
- **Setting**: `app.check_external_changes` (default on) turns only the automatic checks on or off. The server also refuses (`disabled`).
- **Scope boundaries**:
  - Only the **current** vault is checked (`inactive` otherwise).
  - Nothing runs while MDVault is closed; vault open reconciles.
  - Nothing is written to disk by detection.
  - No sync, device, version or remote concepts, tables or names (§45, §64, Rule 10).
  - No native watcher, `ChildProcess`, chokidar or `fs.watch` code in this phase (grep-enforced).
  - Orphan `.mdvault-save-*` files are only reported (G7).
  - Encrypted vaults are Phase 7.
- **Future native trigger**: it must call the same `check()` (or `reconcile()`) and must be stopped before a vault rename or trash. Events remain hints; the reconcile remains the truth.

## Consequences
- **Positive**:
  - One code path in desktop and browser dev, fully testable with Pest and Vitest.
  - No directory handles are held between checks, so vault rename/trash is unaffected.
  - Detection works offline with no extra process.
  - The quick scan is O(number of files) in stats and O(changed files) in hashing.
- **Negative / trade-offs**:
  - Up to about 5 s latency while visible (more under back-off). Changes made while hidden are seen on focus.
  - A constant, small background load while visible.
  - A long check delays other requests on the single-threaded server; the adaptive delay bounds this.
  - Deviates from the literal "watcher" wording of §24; this needs approval (G1).
- **Follow-ups**:
  - An optional native trigger if D18 performance or user feedback demands it.
  - Phase 7: detection over encrypted names.
  - Phase 8: confirm packaged-app behaviour (focus and visibility events in Electron).
  - Vault disappearing while the editor is dirty re-prompts the unsaved-changes dialog on each check (F1 in the Phase 5 analyst review); skip the full reload while dirty in a later phase.

## Amendment (Phase 7, 2026-10-03)
- Follow-up resolved: detection on encrypted vaults works on ciphertext without the key.
