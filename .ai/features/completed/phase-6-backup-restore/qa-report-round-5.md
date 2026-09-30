# QA Report: Phase 6, Backup and Restore — Round 5 (targeted re-test of analyst findings AR-01 to AR-05)

## Metadata
- **Feature Name**: Phase 6: Backup and Restore (`mdv-p6`)
- **Feature ID**: phase-6-backup-restore
- **Author**: Senior QA Engineer
- **QA Round**: 5
- **Date**: 2026-09-30
- **Verdict**: **PASS**

> Zero open Critical or High issues. Two new Low/MINOR test-coverage follow-ups (QA-07, QA-08), neither blocking.

---

## 1. Analyst Findings Coverage

| ID | Required change | Verified | Evidence |
|---|---|---|---|
| AR-01(a) | `ArchiveService::write()` calls `close()` at most once on every path | Yes | `app/Services/ArchiveService.php:35-95`. Add-phase failures hit a dedicated `catch(\Throwable)` that calls `unchangeAll()` then a single cleanup `close()` (never previously attempted on that path). Add success leads to exactly one `close()` call, itself wrapped in its own `try`/`catch(\Throwable)`, never retried. Confirmed structurally and by two standalone `ext-zip` reproductions I ran directly (see below) that match the developer's diagnosis exactly. |
| AR-01(b) | `BackupService::create()` catches `\Throwable` around `write()`, `report()`s non-`BackupException`, discards the temp file, throws `archiveWriteFailed` | Yes | `app/Services/BackupService.php:342-352`, matches the plan literally. |
| AR-01(c) | Tests prove the contract | Yes, with one caveat — see **QA-07** | `ArchiveServiceTest.php:128-146` reliably reproduces the close-time failure (a directory as `source`) and asserts `BackupException` + no file left. `BackupCreateTest.php:261-293` reproduces an EXCL open-time collision through a real `create()` call and asserts the same. See judgment call below. |
| AR-02 | Stage 5 validates required keys/types and strict ISO-8601-or-null timestamps; `validateArchive()` returns a normalized manifest; `inspect()`/`restore()` read no raw keys | Yes | `BackupService.php:618-1046` (header + per-vault + `isValidTimestamp()` at 1142-1160); `parseRegistryEntry()` at 1052-1131 requires the `modified_at` key to be present. `grep` of every `$manifest[...]` read in `inspect()` (1239-1246, 1254) and `restore()` (1400, 1418) confirmed they all read the **normalized return value** of `validateArchive()`, never the raw JSON-decoded array. |
| AR-02 judgment call | Vault `created_at` and entry `modified_at` keys must be present (missing ⇒ invalid); note `created_at`/`updated_at` stay on `?? null` default | Reasonable | Matches this file's own pre-existing `encryption`/`is_encrypted` "missing vs. explicit null" idiom, and matches exactly the four dataset rows the analyst's own finding text enumerated (which did *not* ask for a "missing note `created_at`" row). I traced downstream use: a `null` vault or note timestamp already falls back to `now()` at `BackupService.php:1545-1546` and `1557-1558`, so unifying "missing key" with "explicit null" for notes is functionally safe, not just textually defensible. |
| AR-02 tests | Dataset covers malformed value, missing vault key, missing note key, bad type, plus one restore-path 500→clean-error test | Yes | `BackupInspectTest.php:347-386`, all five pass. |
| Genuine backups still validate | Round trips pass | Yes | `BackupRoundTripTest`, `BackupCreateTest`, `BackupInspectTest`, `BackupRestoreTest` all pass in the full run (below); `validBackupFixture()` builds a manifest through a real `create()` call before each AR-02 mutation test, so the normalized-manifest path is exercised against genuine MDVault output, not just hand-crafted fixtures. |
| AR-03 | Timestamp preservation assertions exist and pass | Yes | `BackupRestoreTest.php:52-54, 108-117` — asserts restored vault `created_at` and note `created_at`/`updated_at` equal pre-backup values at second precision; passes. |
| AR-04 | `actions` cleared on backup switch | Yes | `RestoreBackupDialog.vue:45-49`, `load()` now clears every key before dispatching the new inspection request. |
| AR-05 | `simulateFreshInstall()` no longer leaks temp dirs | Yes | `tests/Pest.php:252-272`, now nests under the calling test's own `$this->tmp`. |
| ADR precision edits | Accurate | Yes | Confirmed the three requested edits verbatim in `backup-restore-semantics.md` (restore step 1 → "stages 1–7.5…"; sizes bullet; CHECKCONS bidirectional wording) and the matching correction in `backup-archive-format.md`. |

### Independent reproduction (beyond code reading)
I ran two standalone `ext-zip` scripts against the same PHP the app uses, to verify the mechanism the fix relies on rather than take the diagnosis on faith:
1. `addFile()` with a non-existent source → returns `false` immediately (add-phase failure), and `unchangeAll()` + a single `close()` afterward succeeds and leaves **no file on disk**. Confirms the add-phase cleanup branch is correct.
2. `addFile()` with a directory as source → registers successfully, first `close()` returns `false` (no file on disk), and a **second** `close()` call throws `ValueError("Invalid or uninitialized Zip object")`. Confirms the exact defect the analyst diagnosed and that the fix's "close at most once" discipline is the correct and necessary fix.

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups tests/Feature/Services/ArchiveServiceTest.php tests/Unit/ArchitectureTest.php` | 126 passed, 409 assertions |
| `php artisan test --compact` (full suite) | 744 tests, 733 passed, 11 skipped, 2145 assertions — 0 failed |
| `vendor/bin/phpstan analyse` | 0 errors |
| `npm run types:check` | clean (`vue-tsc --noEmit`, no output) |
| `npm run test:js` | 142 passed (12 files) |
| `vendor/bin/pint --dirty --format agent` | passed |

No failures to paste.

---

## 3. Issues

| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-07 | Low | MINOR | `tests/Feature/Backups/BackupCreateTest.php:261-293` | The new test forces an EXCL open-time collision, which `ArchiveService::write()` throws as a `BackupException` directly (line 39-41 of `ArchiveService.php`) — this is unchanged behavior that the *pre-AR-01* code (`catch (BackupException)`) would already have handled identically. It therefore doesn't exercise the actual amendment in `create()`'s catch block: broadening to `catch (\Throwable $e)` and calling `report($e)` when `$e` isn't a `BackupException`. That `report()` branch remains untested by any automated test (though, per code review, it is currently unreachable in practice since `ArchiveService::write()` now converts every internal failure to `BackupException` itself — confirmed by reading `write()` and by my own reproductions above). The developer's own implementation notes flag this same limitation. | Optional: a test that forces a genuine non-`BackupException` out of `$this->archives` (e.g. a partial mock/stub of `ArchiveService` bound in the container for one test) to prove `report()` fires and the exception is still converted to `archiveWriteFailed`. Not required before closing the phase — the belt-and-suspenders code path is correct by inspection and the plan-literal wording is satisfied by the current test. | 0 |
| QA-08 | Low | MINOR | `tests/Feature/Services/ArchiveServiceTest.php` | Only the close-phase failure branch of `write()` is covered by a test (directory-as-source, close-time failure). The add-phase failure branch (`addFromString`/`addEmptyDir`/`addFile` returning `false` immediately — e.g. a non-existent `source`) is not exercised by any test. I verified manually (see §1 reproduction #1) that this branch behaves correctly (single cleanup close, no file left on disk), so this is a coverage gap, not a functional defect. | Optional: add an `ArchiveServiceTest` case with a non-existent `source` path in `$files`, asserting `BackupException` and no file left at `$path`, to lock in this branch against future regressions. | 0 |

No Critical, High or Medium issues found in this round. Both new issues are Low/MINOR test-coverage follow-ups only; the underlying code for AR-01 through AR-05 is correct.

---

## 4. Code Review Notes
- **Security & Authorization**: unchanged this round; controllers still route through form requests and `BackupService`; no new attack surface introduced by these fixes.
- **Validation & Data Integrity**: AR-02's stage-5 hardening closes a real gap (undefined-array-key `ErrorException` on a hand-crafted manifest) without weakening any existing check; the normalized-manifest return value is a clean way to guarantee `inspect()`/`restore()` never read unvalidated keys, and I confirmed by `grep` that no raw `$manifest[...]` read remains outside `validateArchive()` itself.
- **Performance**: no change in this round; Stage 7.5's aggregate gate (verified in QA round 4) is untouched.
- **Conventions**: `ArchiveService` remains the only class touching `ZipArchive` (arch rule + `entries()`/`readEntry()`/`eachEntryStream()` all still close exactly once via `finally`, which was never the buggy path). `BackupService::create()`'s catch clause now matches the plan's literal wording.
- **Frontend**: `RestoreBackupDialog.vue`'s `load()` fix is a minimal, correct state-reset; `npm run test:js` and `types:check` both pass with no target-specific test added, consistent with the analyst marking this optional/cosmetic.
- **Test hygiene**: `simulateFreshInstall()` no longer leaks `mdvault-fresh-*` directories under the system temp dir.

---

## 5. Routing Recommendation
- [x] **PASS** → per `analyst-review.md` §4/§7, this makes the Level 4 review **APPROVED** without a further analyst pass (no finding here changes the design). Orchestrator should apply the `analyst-review.md` §5 artifact status edits and move `.ai/features/active/phase-6-backup-restore/` to `.ai/features/completed/`.
- Optional, non-blocking follow-ups for the Developer (may be deferred or skipped): **QA-07**, **QA-08** — both are Low/MINOR test-coverage gaps with no functional defect found on manual verification.
- Nothing routes to the System Analyst this round.
