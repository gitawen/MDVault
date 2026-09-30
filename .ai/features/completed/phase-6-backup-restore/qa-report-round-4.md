# QA Report: Phase 6: Backup and Restore — Round 4

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Author**: Senior QA Engineer
- **QA Round**: 4
- **Date**: 2026-09-30
- **Verdict**: **PASS** (zero open Critical/High issues; QA-05 closed; 1 new Low/MINOR issue, QA-06, does not block)

---

## 1. Re-test of QA-05 (Fix Attempt 1)

### 1.1 Factual dispute: does the round-3 central-directory forgery survive `ArchiveService`'s actual open path?

**The developer is right.** I built an independent PoC (not reusing test fixtures) that forges a ZIP central-directory `compressed_size` field exactly as round 3's PoC did, then opens the tampered file through `ArchiveService`'s real code path (`ZipArchive::RDONLY | ZipArchive::CHECKCONS`, used identically by `entries()`, `readEntry()` and `eachEntryStream()`).

Findings, using a genuine 5,000,000-byte compressible entry (true `comp_size` = 4,863) and, separately, matching the plan's 8 MiB fixture forged to 40,000 (as `forgedRatioArchiveFixture()` does):
- Forging **upward** (any value > true `comp_size`, including the fixture's 40,000) → `ArchiveService::entries()` throws `BackupException` ("This isn't a valid ZIP file") — confirmed via `ZipArchive::open()` returning `ZipArchive::ER_TRUNCATED_ZIP` (code 35).
- Forging to the **exact true value** → opens fine (no forgery has actually occurred).
- Forging **downward** (any value < true `comp_size`, swept from `true‑1` down to `1`) → **also rejected**, via `ZipArchive::ER_INCONS` (code 21), not `ER_TRUNCATED_ZIP`.

So: round 3's PoC bypass — inflate `compressed_size` to disguise a huge, highly-compressible entry as an innocuous ratio — does **not** survive `ArchiveService`'s actual read path. **I confirm round 3's PoC opened without `CHECKCONS`** (its own report text says "`ZipArchive::open()` succeeded with no integrity complaint" — a bare, flagless open), so it never exercised the code path this codebase actually uses. The developer's central claim is correct and empirically settled.

**One inaccuracy found in the correction itself**, not in the substantive fix: both `app/Services/BackupService.php:83-86` (the `MAX_COMPRESSION_RATIO` docblock) and `.ai/decisions/backup-restore-semantics.md:94` state that a forged *decrease* "opens fine but only makes the reported ratio worse." My sweep shows this is false — a downward forgery does **not** open at all; it's rejected with `ER_INCONS`, just like an upward one (with a different error code). This doesn't weaken the security conclusion (the real protection is *stronger* than documented — no forged value of any kind, in either direction, opens except the untampered true value) — but it's a factual error in security-relevant documentation, and this is the third round running with a documentation-accuracy finding in this exact paragraph. Opened as **QA-06** below (Low/MINOR — doesn't overclaim protection, actually undersells it, so no live security gap).

### 1.2 Stage 7.5 aggregate gate — correct and airtight

Read `validateArchive()` end to end (`BackupService.php:483-946`). The gate (`:881-906`) sits after Stage 7's problems-check and before Stage 8's hashing block (`:912-939`) in both call paths (`inspect()` and `restore()`, both via the single shared `validateArchive()`).

- **The bound holds**: `$totalBytes` is accumulated once, during the Stage-6 registry loop (`:769`, `:802`), as the sum of every `parseRegistryEntry()`'s declared `file_size` across notes and files. Stage 8 then hashes exactly `array_keys($expectedFiles)`, each capped at `expected['size'] + 1` (`:929`) — and `$expectedFiles`'s sizes are the exact same values summed into `$totalBytes`. So the gate's `$totalBytes` figure is provably identical to what Stage 8 is about to stream, not a separate or looser estimate.
- **Physical size source is unforgeable**: `FileStorageService::size()` reads the archive's real on-disk footprint via `filesize()`/`stat()`, entirely independent of any ZIP-internal metadata (central directory, local headers, or `ArchiveService`'s own reported figures). A `null` result (file vanished mid-inspection) is treated as a failure, correctly.
- **Sub-floor chunking is caught**: I confirmed the per-entry ratio check (`:858`, `$actual['size'] > self::COMPRESSION_RATIO_MIN_BYTES`) is a strict `>`, so entries at exactly 1 MiB (the floor) never trip it — matching `subFloorChunkedArchiveFixture()`'s 5×1,048,576-byte entries. The aggregate gate is what catches this: declared total 5 MiB against a physically tiny (highly-compressed) archive comfortably exceeds `physicalBytes × 250 + 1 MiB` (the test's tightened allowance). Ran the test — passes, and fails cleanly with the gate's own message ("plausibly contain"), not a generic one.
- **No regression on legitimate backups**: the mixed-content "passes" test (2 MiB incompressible + 512 KiB compressible, under production's 64 MiB allowance) passes.

### 1.3 Constructor seam — confirmed test-only, not a hazard

`aggregateRatioAllowanceBytes` (`BackupService.php:130-148`) is the constructor's last parameter, defaulting to `self::AGGREGATE_RATIO_ALLOWANCE_BYTES` (64 MiB). Grepped the whole `app/` tree: the only reference outside the constructor itself is the one read at `:904`. No service provider, config file, or controller binds or overrides it — both `BackupController` and `BackupRestoreController` type-hint plain `BackupService $backups` for normal DI, which resolves the default with zero wiring. Only the test suite calls `app(BackupService::class, ['aggregateRatioAllowanceBytes' => ...])`. Production behavior is unchanged. Not a hazard.

### 1.4 ADR / code-comment accuracy — accurate except for the one item in §1.1

Re-read both ADR amendments (`backup-restore-semantics.md` ~lines 91-95, `backup-archive-format.md` ~lines 168-169) and the `BackupService` docblocks (`MAX_COMPRESSION_RATIO`, `COMPRESSION_RATIO_MIN_BYTES`, `AGGREGATE_RATIO_ALLOWANCE_BYTES`, and the Stage 7.5 inline comment). All correctly:
- describe the per-entry ratio check as a heuristic, not a guarantee;
- correctly attribute the real bypass to sub-floor chunking, not central-directory forgery;
- correctly state that an *inflated* central-directory forgery is rejected by `CHECKCONS` before the manifest is read (verified above);
- correctly identify the Stage 7.5 aggregate gate + `hashStream()`'s per-entry cap as the actual unconditional bound, and state the residual cost (≈250× physical size, CPU-bound, accepted for a local desktop app) without new overclaiming.

The single inaccuracy is the "deflating opens fine" line addressed in §1.1/QA-06 — everything else is precise and matches the code.

### 1.5 New tests — assert what they claim; no regressions

`tests/Feature/Backups/BackupInspectTest.php`:
- `forgeCentralDirectoryCompressedSize()` correctly implements the ZIP central-directory-record patch (offset +20 from `PK\x01\x02`, matching ZIP spec 4.3.12) — I independently re-derived and ran the same patch in my own PoC and got matching results.
- `'a central-directory compressed_size forged above the true value is rejected before the manifest is even read'` and its restore counterpart — assert rejection via the "isn't a valid ZIP file" message, which is exactly what `validateArchive()` returns when `ArchiveService::entries()` throws (`:531`); confirmed this is the actual path taken (not the aggregate or ratio gates), matching the test's own docblock claim.
- `subFloorChunkedArchiveFixture()` and `tightenedAggregateGateService()` — genuine, unforged ZIP/manifest metadata; the constructor seam is used correctly and only in tests.
- `'sub-floor chunking evades the per-entry ratio heuristic but is still rejected by the aggregate physical-size gate'` and its restore counterpart — assert the aggregate gate's own message ("plausibly contain"), field/rollback assertions match `restore()`'s actual failure contract (`field() === 'path'`, unchanged `Vault::count()`, byte-identical storage-root listing).
- `'a legitimate backup mixing an ordinary and a highly compressible file stays under the aggregate allowance and passes'` — real backup via `BackupService::create()` at the production 64 MiB allowance; correctly proves no false positive.

No regressions: `tests/Feature/Backups` is 97/97 (was 92 before this round, +5 exactly matching the round's additions), and the full suite is clean on two consecutive runs with no flakes reproduced this round.

---

## 2. Test Execution (this round)
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups` | 97 tests, 97 passed, 342 assertions, 0 failed |
| `php artisan test --compact` (full suite, run 1) | 737 tests, 726 passed, 2127 assertions, 11 skipped, 0 failed |
| `php artisan test --compact` (full suite, run 2) | 737 tests, 726 passed, 2127 assertions, 11 skipped, 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |
| `php vendor/bin/pint --dirty --format agent` | passed |

No failures to paste. No repeat of the prior rounds' intermittent `VaultReconcileTest`/`NoteServiceTest` mtime flakes on either full-suite run.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-05 | — | — | `app/Services/BackupService.php` (Stage 7.5); ADRs | Fixed as directed (fix attempt 1/3): unforgeable Stage 7.5 aggregate gate added, correctly bounds Stage 8 work regardless of ZIP metadata or entry chunking; central-directory-forgery framing corrected. Closed — see §1.1 for the residual, separately-tracked inaccuracy (QA-06). | — | Closed |
| QA-06 | Low | MINOR | `app/Services/BackupService.php:83-86`; `.ai/decisions/backup-restore-semantics.md:94` | Both state that forging a ZIP central directory's `compressed_size` *downward* "opens fine but only makes the reported ratio worse." Empirically false (PoC swept every value below the true `comp_size`, in a fresh archive with no prior tampering): `ZipArchive::open(..., RDONLY \| CHECKCONS)` rejects **any** deviation from the true value, up or down, failing with `ZipArchive::ER_INCONS` (21) for decreases and `ZipArchive::ER_TRUNCATED_ZIP` (35) for increases — only the exact untampered value opens. This doesn't create a security gap (the real protection is broader than documented: no forgery direction survives `CHECKCONS`, not just the upward one), but it's a factual error in security documentation, and the third round running with an accuracy issue in this same paragraph. | Correct both passages (and, optionally, the same sentence's echo in `implementation.md:248`) to state that `CHECKCONS` requires an exact match between the central directory and local file header's `compressed_size`, so **any** forged value — not just an inflated one — causes rejection; there is no "opens fine but understates the ratio" case in either direction. | 0 |

**No Critical or High issues found.**

---

## 4. Code Review Notes
- **Security & Authorization**: no change to auth/ownership scoping this round; scope was the zip-bomb validation pipeline only.
- **Validation & Data Integrity**: Stage 7.5 sits correctly between Stage 7 and Stage 8 in the single shared `validateArchive()`, so `inspect()` and `restore()` get identical protection. The bound it enforces is exactly the figure Stage 8 streams — verified by tracing `$totalBytes`'s single accumulation point through to `$expectedFiles`'s sizes.
- **Performance**: no new I/O beyond one additional `FileStorageService::size()` call per validation pass (already used elsewhere in the same method for `archive_size`).
- **Conventions**: all disk/archive access still routes through `FileStorageService`/`ArchiveService`/`FileHashService`; no raw filesystem calls introduced. Constructor seam is a plain optional scalar parameter, not a config entry — appropriately scoped to tests only.
- **Documentation accuracy**: flagged above (QA-06) — a leftover, disprovable factual claim in one code docblock and one ADR paragraph, from the same corrective pass that fixed round 3's overclaim. Worth a one-line correction; does not affect the security posture since the actual behavior is stricter than what's currently documented.

---

## 5. Routing Recommendation
- [x] **PASS** — zero open Critical/High issues. Per `.claude/CLAUDE.md`, this is Level 4: route to `system-analyst` for final sign-off before moving the folder to `.ai/features/completed/`.
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: QA-06 (Low/MINOR, fix attempts 0/3) — documentation-only correction to `app/Services/BackupService.php:83-86` and `.ai/decisions/backup-restore-semantics.md:94` (and optionally `implementation.md:248`); no code behavior change needed. Does not block PASS; can be folded into the analyst's sign-off pass or a quick developer pass at the orchestrator's discretion.
- [ ] **FAIL — MAJOR** → System Analyst: none.
