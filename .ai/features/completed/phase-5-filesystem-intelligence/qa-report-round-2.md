# QA Report: Phase 5: Filesystem Intelligence

## Metadata
- **Feature Name**: Phase 5: Filesystem Intelligence
- **Feature ID**: mdv-p5
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-09-30
- **Verdict**: PASS

> **PASS** requires zero open Critical or High issues. Medium/Low issues may remain as documented follow-ups.

---

## 1. Re-test of Round 1 Issues

### QA-01 (was Critical/MODERATE) — `createCopy()` 500 on a stale, unreconciled registry row — **RESOLVED**

I independently reviewed the full diff (`git diff -- app/Services/NoteService.php`) rather than trusting the report, and traced the safety argument end to end instead of accepting it at face value.

**Is deleting the row at the target path provably safe?**

`registerNewFile()`'s new `clearStaleRow` delete (`Note::query()->where('vault_id', $vault->id)->where('relative_path', $relative)->delete()`) is only ever reached for a `$relative` that has already passed `assertNoConflict($vault, $relative, $absolute, 'content', $sourceNote, null)` for that candidate. I checked `assertNoConflict`'s actual implementation (`app/Services/NoteService.php:671-688`):
- It first checks `$this->files->exists($absolute)` — a live file on disk at that exact path always throws, regardless of `$except`. Combined with `createFile()` using `fopen($path, 'x')` (exclusive create, confirmed in `FileStorageService.php:236-251`), a real file can never be silently clobbered even under a race between the check and the write.
- It then scans **every** note in the vault (`$vault->notes()->pluck('relative_path', 'uuid')`), excluding only `$except` (`$sourceNote`), and throws if any of them matches the candidate **case-insensitively**. Case-insensitive equality is a strict superset of the DB's case-sensitive exact match the unique index and the subsequent `delete()` use — so any other note's row (including a case-variant one) that would collide with the delete's exact-match `WHERE` has already been caught and skipped (`continue`) before `registerNewFile()` is ever called for that candidate.
- Consequently, the delete can only ever remove `$sourceNote`'s own row, and only when the candidate equals `$sourceNote->relative_path` exactly (candidate 0, the original path) — never a different note's row, and never a case-only variant. I verified this isn't just theoretical: the new test "a different note's stale, unreconciled registry row at a `(my version)` candidate path is skipped, not crashed on" (`NoteCopyTest.php:137-171`) exercises exactly this and asserts the unrelated stale row is left untouched (`Note::query()->where('relative_path', 'X (my version).md')->where('uuid', $earlierCopyUuid)->exists()` → true).
- `Note::query()->delete()` is a mass-delete via the query builder, which Eloquent does **not** fire `deleting`/`deleted` model events for; I confirmed `app/Models/Note.php` has no `booted()`/static event hooks and there are no Observers in the app (`find app -iname "*Observer*"` → none), so this delete has no side effects beyond the row itself.
- **Race window**: there is a TOCTOU gap between `assertNoConflict()`'s live `pluck()` (a fresh query per candidate) and the transaction that runs the delete+insert — a genuinely concurrent writer could theoretically insert a colliding row in between. This is pre-existing in every candidate-loop call site in this file (not introduced by this fix), and MDVault is a single-user, local-first desktop app (NativePHP) with no concurrent writers by design, so I'm not blocking on it — noted below as a documented, low-risk follow-up.

**`create()`'s default path**: `create()` (`NoteService.php:88-109`) now delegates to `registerNewFile($vault, $relative, $filename, $stem, $absolute, $source)` with `$clearStaleRow` omitted (defaults to `false`), so no delete ever runs for it. The only change to `create()`'s runtime behavior is that its single insert is now wrapped in a DB transaction it wasn't in before — a transaction around one statement has no observable behavioral difference (same exception propagates on a unique violation, same file-compensation `catch` block runs afterward). `NoteManagementTest` (unchanged, untouched this round) still passes in full, confirming this.

**DB-failure compensation**: the delete and the insert both run inside `$this->database->connection()->transaction(...)`, so a failure inside the closure (e.g. the `Note::creating` throw used in the existing "DB failure" test) rolls back **both** statements together — the stale row is restored, not left deleted, by ordinary transaction semantics — and the outer `catch` still calls `deleteNewFileWithContents()` on the just-created file afterward, exactly mirroring the pre-existing `create()`/`relocate()` compensation pattern. I verified this is logically sound (single Laravel/SQLite transaction, no partial commits possible), but I'll flag as a **minor coverage gap**: the existing "a DB failure on insert removes the new file and leaves the original untouched" test (`NoteCopyTest.php:259-275`) exercises `create()`'s general compensation, but not with a pre-existing stale row at the exact recreated path — so the specific "delete rolls back too, stale row survives" combination relies on transaction semantics rather than a dedicated assertion. Not a functional defect (see QA-03 below, Low/non-blocking).

**Do the new tests genuinely exercise the unreconciled path, including HTTP level?** Yes, confirmed by reading `tests/Feature/Notes/NoteCopyTest.php` directly (it's untracked/new, so not visible in `git diff`, but I read the full file):
- `NoteCopyTest.php:94-113` — service level: unlinks the file, does **not** reconcile, calls `NoteService::createCopy()` directly, asserts success, a fresh UUID, exactly one `Note` row.
- `NoteCopyTest.php:115-135` — the identical scenario through `POST vaults.notes.copy`, asserting 201 and the same invariants.
- `NoteCopyTest.php:137-171` — the "different note's stale row" case described above.
- I confirmed the original "a deleted source is recreated..." test (`:70-92`) still deliberately reconciles first (as QA-01 originally noted), so the three new tests are additive, genuinely new coverage of the previously-uncovered path, not a rename/edit of the old one.

**Verdict: QA-01 is resolved.** No crash is reachable in the reviewed scenarios; the fix is provably confined to the source's own stale row.

### QA-02 (was Medium/MINOR) — `saveAsNewNote()` swallowed 500s/network failures silently — **RESOLVED**

Reviewed `resources/js/lib/editor/copyTransport.ts` and the diff to `NoteEditor.vue`'s `saveAsNewNote()` directly:
- `mapCopyResult()` mirrors the existing `mapSaveResult`/`saveTransport.ts` pattern: `httpException` → a generic message that explicitly says "Your text is still in the editor."; `network` → a generic message that says "MDVault couldn't reach its local server. Your text is still in the editor."
- `saveAsNewNote()`'s `useHttp(...).post(...)` now wires `onHttpException` and `onNetworkError` alongside the existing `onSuccess`/`onError`, each routed through `mapCopyResult` and `toast.error(...)`.
- **Text and banner intact on failure**: I checked that `discard()` (which clears the editor's pending edit) is only called from `onSuccess`, unchanged from before — none of the three failure handlers call `discard()`, `resume()`, or any banner-dismissal function. The conflict banner (if one was open, since this action is only reachable from a conflict state per the ADR) is untouched on any failure path, and the user's typed content is never cleared.
- `tests/js/editor/copyTransport.test.ts` — read the full file: 6 genuine cases (success, a 422 message, a 422 array-valued field taking the first message, an empty error bag falling back to the generic message, an HTTP exception, a network error), all asserting the exact reassuring copy.

**Verdict: QA-02 is resolved.**

---

## 2. Test Execution (this round)

| Command | Result |
|---|---|
| `vendor/bin/phpstan analyse` (developer could not run this; I ran it) | `{"tool":"phpstan","result":"passed","errors":0}` |
| `php artisan test --compact` (full suite) | `602 tests, 592 passed, 1697 assertions, 10 skipped` (pre-existing `skipOnWindows()` symlink/chmod skips, unrelated to this change) |
| `npm run test:js` | `11 files, 138 tests, all passed` |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean, no output |
| `php vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |

**Note on flakiness encountered mid-round**: the first full-suite run via `php artisan test --compact` produced 90 failures, all `419` CSRF-mismatch / cascading "No query results" errors across unrelated test files (`VaultManagementTest`, `Settings/*`, `NoteContentTest`, etc.) — not specific to `NoteCopyTest` or the QA-01/QA-02 changes. This reproduced identically when I re-ran a single unrelated file (`VaultManagementTest`) in isolation, but **disappeared entirely** after `php artisan config:clear && php artisan cache:clear`, and did not recur on any subsequent run (isolated file or full suite). I'm treating this as a stale local config/route cache artifact in the sandbox rather than a code regression — none of this round's changes touch CSRF, sessions, or middleware, and the failure pattern (blanket 419s across the entire suite, including files untouched by this feature) is inconsistent with a targeted code defect. Recording it here per the instruction to report skeptically; if it recurs for the user, `php artisan config:clear` is the fix, not a code change.

No test failures remain in this round.

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Critical | MODERATE | `app/Services/NoteService.php` (`registerNewFile`, `createCopy`) | **RESOLVED.** See re-test above. | — | 1 (resolved) |
| QA-02 | Medium | MINOR | `resources/js/components/editor/NoteEditor.vue` (`saveAsNewNote`) | **RESOLVED.** See re-test above. | — | 1 (resolved) |
| QA-03 | Low | MINOR (optional follow-up) | `app/Services/NoteService.php` (`registerNewFile`) | No test explicitly exercises "a DB failure occurs *while* a stale row exists at the exact recreate path" — the existing DB-failure test (`NoteCopyTest.php:259-275`) uses a fresh vault with no stale row, so it validates the file-compensation half but not that the transaction rollback specifically restores the deleted stale row. The current behavior is already correct by ordinary DB-transaction semantics (delete+insert share one transaction), so this is a coverage gap, not a functional defect. | Optional: add a variant of the DB-failure test that first deletes a note's file (leaving a stale row) before triggering the `Note::creating` throw, and assert the stale row still exists afterward. | 0 |
| QA-04 | Low | informational (no fix required) | `app/Services/NoteService.php` (`createCopy` candidate loop) | A TOCTOU race exists between `assertNoConflict()`'s live `pluck()` conflict scan and the later transaction's delete+insert — a genuinely concurrent writer could insert a colliding row in between. Pre-existing pattern in this file (not introduced by the QA-01 fix), and MDVault is a single-user local-first app with no concurrent writers by design, so this is not blocking. | No action required unless multi-process/concurrent write scenarios are introduced later. | 0 |

---

## 4. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/` (per CLAUDE.md, this is Level 4: `system-analyst` should give final sign-off before the move, per the workflow's Level 4 routing)
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: none open.
- [ ] **FAIL — MAJOR** → System Analyst: none.

QA-03 and QA-04 are optional, non-blocking follow-ups (Low severity) and may be left as documented follow-ups or handed to the Senior Developer at the team's discretion; neither affects the PASS verdict.
