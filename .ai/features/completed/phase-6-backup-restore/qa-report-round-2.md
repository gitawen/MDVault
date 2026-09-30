# QA Report: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Author**: Senior QA Engineer
- **QA Round**: 2
- **Date**: 2026-09-30
- **Verdict**: **PASS** (zero open Critical/High issues; 1 Medium open carry-over, 3 issues from round 1 now closed)

---

## 1. Re-test of Round 1 Issues

### QA-01 (Medium/MODERATE) — STILL OPEN, Fix Attempts: 1/3
**Fix as implemented**: `BackupService::MAX_DECLARED_FILE_BYTES` (10 GiB) is checked in `parseRegistryEntry()` right after the existing `is_int`/`< 0` size check, i.e. before any streaming. `MAX_DECLARED_TOTAL_BYTES` (100 GiB) is checked in `validateArchive()` alongside the other `total_bytes` checks, before Stage 7 (entry-set matching) and therefore before Stage 8 (integrity hashing). Both are confirmed correctly placed by reading the code (`app/Services/BackupService.php:763`, `:876`) — a hostile archive above either cap is genuinely rejected before any streaming/hashing occurs. This is a real improvement over round 1 (truly unbounded → now bounded).

**Why it's still classified open**: I judged whether the caps *actually mitigate* the inspect-path DoS on a single-threaded desktop server, weighed against legitimate large-attachment use, per your instruction — and they don't, in a way that matters:

- There is no cap anywhere on the *physical/compressed* size of the archive or on the compression ratio (`compressed_size` is already available from `ArchiveService::listEntries()`'s `statIndex()` call but is never read in `BackupService`). Nothing stops an attacker from building a small, well-compressed deflate archive (tens of MB physical) whose declared `file_size`/`total_bytes` sit anywhere *under* the two new caps — including right up to just under 100 GiB total — while still passing Stages 2–7 (the size is self-consistent between manifest and the physical zip's own central-directory `size` field, which is exactly the scenario round 1 described).
- `FileHashService::hashStream()` (`app/Services/FileHashService.php:40`) and `ArchiveService::eachEntryStream()` (`app/Services/ArchiveService.php:157`) stream via `ZipArchive::getStream()` — pure CPU-bound zlib inflate + SHA-256, no disk buffering. At a realistic single-core combined inflate+hash throughput in the low-hundreds-of-MB/s, hashing up to 100 GiB in one synchronous `inspect()` HTTP request is a multi-minute (plausibly 5–10 minute) hang of a single-threaded local server, triggered by a file the user merely pointed the "inspect backup" dialog at, not one they've committed to restoring.
- The amended ADR text (`.ai/decisions/backup-restore-semantics.md:91`, `.ai/decisions/backup-archive-format.md:168`) says the caps make a hostile archive "fail fast" — that's only true for archives that declare *more* than 100 GiB. An archive engineered to land just under the cap still reaches and fully executes Stage 8. The documentation overstates what was actually fixed.

**Net judgment**: this is a genuine, if bounded, improvement — not a non-fix — but 10 GiB/100 GiB are values sized for "don't reject legitimate real-world backups," not "fail fast against a hostile one," and the round 1 report specifically asked for the latter. Given MDVault's own scope (markdown notes are the primary content per Master Plan §Non-Negotiable Rule 1; masterplan text nowhere anticipates single attachments in the multi-GB range), the legitimate-use case for values anywhere near 10 GiB/100 GiB looks thin.

**Recommendation (concrete)**: lower the absolute caps substantially — e.g. `MAX_DECLARED_FILE_BYTES` ≈ 2 GiB and `MAX_DECLARED_TOTAL_BYTES` ≈ 16 GiB — which still comfortably covers a personal vault with a handful of large attachments (PDFs, a few videos) while bounding worst-case Stage 8 hashing to roughly a minute rather than several. As a stronger, complementary fix that targets the actual mechanism rather than just raising the ceiling, consider also reading each entry's already-available `compressed_size` (from `ArchiveService::listEntries()`) and rejecting any entry whose declared/compressed ratio exceeds a generous but bomb-detecting threshold (e.g. 300:1 — well above real-world text/markdown/image compression ratios, far below the ~1032:1 theoretical deflate maximum) as a Stage-2/3-time check, independent of absolute size. Either change, or both, should go back to the developer; this does not need to go to the analyst — it's a parameter/hardening tweak within the existing design, not an architecture change.

**Verdict impact**: Does not block PASS (Medium, desktop/local-file threat model, no data loss or corruption — "hangs the app for minutes on a file the user chose to inspect," not a remote-service outage). Flagging for one more fix round: at 1/3 attempts, well short of the circuit breaker, but worth closing before this drifts.

### QA-02 (Medium/MODERATE) — CLOSED, verified
`tests/Feature/Backups/BackupRestoreTest.php:317` — `'a rename-back failure during rollback names the leftover folder and still leaves nothing registered'`. Verified by reading both the test and the `restore()` commit/rollback code together:
- `failFolderRenames([2, 3, 4, 5])`: call 1 (Personal's forward `moveWithRetry` → single `renameDirectory` → single `moveDirectory` call) succeeds; calls 2–4 are Work's 3 `moveWithRetry` attempts (all fail, exhausting retries and throwing, entering the `catch` block); call 5 is the rollback's `renameDirectory($to, $from)` for Personal (also a single `moveDirectory` call), which also fails.
- Traced `renameDirectory()` → `attemptMove()` → exactly one `Filesystem::moveDirectory()` call per invocation (no case-only-rename branch taken here), confirming the call numbering in the test's comment is accurate against the real code path, not just plausible.
- Assertions match what round 1 asked for: `BackupException` thrown, message contains the leftover folder's absolute path, `Vault::count()` is 0, the leftover folder still exists on disk, no `.mdvault-restore-*` remains.
This exercises `BackupException::restoreRollbackFailed()` for the first time. Test passes (see §2).

### QA-03 (Low/MINOR) — CLOSED, verified
All 8 previously-missing literal dataset rows are present in `tests/Feature/Backups/BackupInspectTest.php:267–342`: `/abs.md`, `C:/x.md`, a bare backslash path, `.hidden/x.md`, an extra undeclared physical entry (via the new `makeZipFixture()` helper), a manifest-declared entry missing from the physical zip, a duplicate note UUID, and the Windows/Darwin case-duplicate row (correctly `markTestSkipped` on other platforms). Each asserts `$inspection->valid` is false via the existing `inspectInvalid()`/`manifestOnlyEntries()` helpers or the new `makeZipFixture()`. Matches the plan's T6 enumeration and the round 1 report's ask.

### QA-04 (Low/MINOR) — CLOSED, verified
The fresh-restore test in `tests/Feature/Backups/BackupRestoreTest.php` now asserts the filesystem mtime of the restored note (`Projects/HRMIS.md`) is within 2 seconds of the manifest's `modified_at`, in addition to the pre-existing DB `file_mtime IS NULL` assertion, per implementation.md §6.

---

## 2. Test Execution (this round)
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups` | 88 tests, 88 passed, 324 assertions, 0 failed |
| `php artisan test --compact` (full suite, run 1) | 728 tests, 717 passed, 2109 assertions, 11 skipped, 0 failed |
| `php artisan test --compact` (full suite, run 2) | 728 tests, 717 passed, 2109 assertions, 11 skipped, 0 failed |
| `php artisan test --compact` (full suite, run 3) | 728 tests, 717 passed, 2109 assertions, 11 skipped, 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |
| `NoteServiceTest.php --filter="saving unchanged content is a no-op"` × 3 in isolation | passed every time |

No failures to paste, across three full-suite runs and three isolated runs of the flaky test.

**On the reported flaky test** (`tests/Feature/Services/NoteServiceTest.php`, `'saving unchanged content is a no-op that leaves the modification time untouched'`): did not reproduce the flake in three full-suite runs or three isolated `--filter` runs. The test asserts `updated_at->equalTo($before)` after a save the code should treat as a no-op (`failFileMoves([1,2,3])` ensures zero writes reach the filesystem layer; `$fake->calls === 0` is asserted directly). It exercises `NoteService::save()`, not anything in `BackupService`/`ArchiveService`/`FileStorageService`'s new methods, and the specific helper it uses (`failFileMoves`) is a pre-existing Pest helper, not one introduced or modified by this feature. Given it didn't reproduce across 6 total runs here either, and the diff for this feature touches none of the code paths this test exercises, I agree with the developer's assessment: **unrelated to this feature, not a regression it introduced**. Worth tracking independently if it recurs, but out of scope for phase-6 sign-off.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Medium | MODERATE | `app/Services/BackupService.php::parseRegistryEntry()` / `validateArchive()` | Caps are correctly positioned before Stage 8 but are set too high (10 GiB/100 GiB) to actually achieve "fail fast" against a hostile archive engineered to land just under them — worst case is still a multi-minute synchronous hang of the single-threaded local server on `inspect()`. No compression-ratio or physical-archive-size check exists as an alternative/complementary gate. | Lower `MAX_DECLARED_FILE_BYTES` to ~2 GiB and `MAX_DECLARED_TOTAL_BYTES` to ~16 GiB (or similar, generous but far tighter), and/or add a compression-ratio sanity check using the already-available `compressed_size` stat, independent of absolute declared size. Also soften the ADR amendments' "fails fast" claim to match whatever is actually shipped. | 1 |
| QA-02 | — | — | `tests/Feature/Backups/BackupRestoreTest.php` | Fixed and verified in round 2. | — | Closed |
| QA-03 | — | — | `tests/Feature/Backups/BackupInspectTest.php` | Fixed and verified in round 2. | — | Closed |
| QA-04 | — | — | `tests/Feature/Backups/BackupRestoreTest.php` | Fixed and verified in round 2. | — | Closed |

**No Critical or High issues found. No new issues (QA-05+) found in the regression pass.**

---

## 4. Routing Recommendation
- [x] **PASS** — zero open Critical/High issues.
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: QA-01 (Medium/MODERATE, Fix Attempts 1/3 — recommend one more focused pass on the concrete cap values / compression-ratio check above before this is closed; well short of the 3-attempt circuit breaker, no need to escalate to System Analyst).
- [ ] **FAIL — MAJOR** → System Analyst: none.

This is Level 4: per `.claude/CLAUDE.md`, route to `system-analyst` for final sign-off before moving the folder to `.ai/features/completed/`. Given QA-01 is still open (Medium), consider one more developer pass on it in parallel with or before the analyst's sign-off — it doesn't block PASS as written, but it's the one item standing between this feature and a clean close.
