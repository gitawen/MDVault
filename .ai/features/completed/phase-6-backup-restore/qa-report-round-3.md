# QA Report: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Author**: Senior QA Engineer
- **QA Round**: 3
- **Date**: 2026-09-30
- **Verdict**: **PASS** (zero open Critical/High issues; 1 new Medium/MODERATE issue, QA-05, does not block; QA-01 resolved as directed)

---

## 1. Re-test of QA-01 (Fix Attempt 2)

**Scope of this round, per the orchestrator's instruction**: keep the 10 GiB/100 GiB absolute caps unchanged, and verify the new `MAX_COMPRESSION_RATIO = 250` compression-ratio gate (`app/Services/BackupService.php`) — placement, `compressed_size` 0 handling, bypass resistance, ADR accuracy, new test correctness, and no regressions.

### Placement — correct
Read `validateArchive()` end to end (`app/Services/BackupService.php:483-869`). The gate lives at lines 813-821, inside the Stage 7 loop that already cross-checks each expected entry's physical `size` against the manifest's declared `file_size`. It only evaluates the ratio once `$actual['size'] === $expected['size']` and `$actual['size'] > COMPRESSION_RATIO_MIN_BYTES` (1 MiB). A ratio violation adds to `$problems`, and the existing `if ($problems !== []) return […]` at line 834 — before Stage 8's `eachEntryStream`/`hashStream` call at lines 838-862 — means a rejected entry (or any other Stage-7 problem) skips hashing entirely, for every entry, not just the offending one. `inspect()` and `restore()` both call this same `validateArchive()`, so the gate applies identically to both, confirmed by reading both call sites (`:1012` `inspect()`, `:1164` `restore()`).

### `compressed_size <= 0` handling — correct
Line 818: `$compressedSize <= 0 || ($actual['size'] / $compressedSize) > self::MAX_COMPRESSION_RATIO`. The `<= 0` short-circuits before any division, so a zero (or negative, impossible from libzip but defensively handled) `compressed_size` on an entry over the 1 MiB floor is rejected outright rather than causing a division-by-zero. Correct.

### Bypass resistance — NOT achieved; empirically confirmed bypassable at zero physical cost
This was the central question the orchestrator asked me to resolve, and I built a PoC rather than reasoning abstractly. Two PHP scripts (run against this project's PHP 8.4.25 / ext-zip 1.22.8):

1. Created a genuine 2,000,000-byte, highly-compressible entry via `ZipArchive` (true physical size 2,063 bytes, true `comp_size` 1,955 — a real ~1023:1 ratio). Located the **central directory file header** (not the local file header) and overwrote only its 4-byte `compressed_size` field with a fake value giving an apparent 10:1 ratio. Reopened the tampered file: `ZipArchive::open()` succeeded with no integrity complaint; `statIndex()` (what `ArchiveService::entries()` uses to populate `compressed_size`) reported the *fake* value; `getStream()` + `fread()` still produced the full, true 2,000,000 bytes, completely unaffected by the central-directory lie.
2. Repeated with a 5,000,000-byte payload, forging the central directory to report a perfect 1:1 ratio (`comp_size = size = 5,000,000`) on a physical file that stayed exactly 4,975 bytes (no padding needed at all). `getStream()` still streamed all 5,000,000 real bytes.

Conclusion: PHP's `ZipArchive`/libzip does not cross-validate the central directory's `compressed_size` against the local file header or against what streaming actually reads. `ArchiveService::entries()`'s `compressed_size` — the only input to the new gate — is therefore attacker-controlled metadata, not a measured value, and can be forged to defeat the ratio check with a single 4-byte edit and **zero increase in physical archive size**. This is a lower bar than crafting the rest of a hostile manifest this pipeline already defends against (valid UUIDs, matching hashes elsewhere, etc.), so it doesn't meaningfully raise the attacker's required sophistication.

**However — this does not reopen an unbounded DoS, and does not regress round 1's fix.** `FileHashService::hashStream()` (`app/Services/FileHashService.php:40`) is called with a hard cap of `$expected['size'] + 1` (`BackupService.php:852`), where `$expected['size']` comes from the **manifest's** declared `file_size` (`parseRegistryEntry()`, already capped at `MAX_DECLARED_FILE_BYTES` = 10 GiB per entry and `MAX_DECLARED_TOTAL_BYTES` = 100 GiB in aggregate — round 1's fix, unchanged this round, confirmed still in place at `:904` and `:785`). This cap is completely independent of the ZIP's own internal metadata (central directory, local header, or anything `ArchiveService` reports) — it's driven purely by what the attacker declared in the manifest, which they already fully control and which was already bounded before this round. So a fully adversarial attacker who defeats the ratio gate is left with exactly the round-1 worst case: up to ~100 GiB of streamed hashing in one synchronous `inspect()` call — the same ceiling the orchestrator reviewed this round and explicitly accepted as reasonable for a local desktop app where the user selects the file. **No new or regressed exposure; the ratio gate simply doesn't deliver the additional protection its own documentation claims against a deliberately crafted archive** — it only helps against a hostile archive built with ordinary compression tooling (the literal scenario round 2's report described: "a small, well-compressed deflate archive"), where the central directory naturally reflects the true physical footprint.

### ADR wording — NOT accurate as written
Both amendments (`.ai/decisions/backup-restore-semantics.md:91`, `.ai/decisions/backup-archive-format.md:168`) state: *"Together, the absolute caps and the ratio gate bound the worst-case Stage 8 work to roughly the archive's own physical (on-disk) size times 250, not to the 10 GiB/100 GiB declared ceilings alone"* / *"The ratio gate is the mechanism that actually bounds worst-case Stage 8 work to roughly the archive's physical size times 250; the absolute caps alone do not."* Both claims are demonstrably false against a deliberately crafted archive per the PoC above — the true, unconditional bound remains the round-1 declared-size ceilings; the ratio gate is a best-effort heuristic that helps only against non-adversarially-forged central directories. This is the same class of overclaim round 2 flagged in QA-01 (there: "fails fast" overstated; here: "bounded by physical size" is actively contradicted by a working exploit), so it's worth being precise about this time.

### New tests — assert what they claim, but don't cover the forgery bypass
Read `tests/Feature/Backups/BackupInspectTest.php:404-509`. All 4 tests pass and correctly validate the *intended* scenario:
- `'a hostile entry whose compression ratio exceeds the cap is rejected before any hashing'` (`:406`) — `hostileRatioArchiveFixture()` builds a genuinely highly-compressible 8 MiB entry via the project's normal `makeZip()` helper (real `ZipArchive`, no byte-level forgery); asserts `valid` is false and a problem message contains "compressed" — matches the gate's actual message text, not a generic check.
- `'a small highly compressible note under the 1 MiB ratio floor passes'` (`:415`) and `'a normal attachment over 1 MiB with an ordinary compression ratio passes'` (`:428`) — real backups via `BackupService::create()`; correctly prove the 1 MiB floor and legitimate-ratio entries aren't affected.
- `'restore refuses a hostile compression-ratio archive and leaves no staging behind'` (`:441`) — calls `restore()` directly on the same hostile fixture; asserts `BackupException` with `field() === 'path'`, unchanged `Vault::count()`, and a byte-identical `scandir()` of the storage root before/after (proving no staging directory was left behind, since validation throws before `ensureRootReady()`).

None of the 4 tests attempt the central-directory-forgery bypass this report demonstrates — understandably, since round 2's report didn't ask for it and it requires raw byte-level ZIP construction outside this suite's existing helpers. This is a coverage gap worth noting, not a defect in the tests as written: each test's own assertions are correct and specific.

### No regression — confirmed
`tests/Feature/Backups` (92 tests) and the full suite pass; see §2. The pre-existing round-trip tests (`BackupCreateTest`, `BackupRestoreTest`) and this round's two new "passes" tests together confirm no false positives were introduced for ordinary backups, including one with a real 2 MiB incompressible attachment.

### Net judgment on QA-01
The fix was implemented exactly as the orchestrator directed (caps kept, complementary ratio gate added, applied identically to `inspect()`/`restore()`, no lowering of the legitimate-large-attachment ceiling). That specific ask is fulfilled — I'm closing QA-01. The gate correctly, verifiably defeats the literal scenario round 2's report described (a small, well-compressed archive built with ordinary tooling). What it does **not** do is what its own documentation now claims (bound work to physical-size × 250 against *any* attacker) — that's a new, distinct finding, opened below as QA-05, about documentation accuracy and a heuristic-vs-guarantee distinction, not a reopening of QA-01's original ask.

**Accepting the residual risk**: per the orchestrator's explicit instruction, I accept the bounded residual risk (worst case unchanged from round 1's already-reviewed 10 GiB/100 GiB ceiling) as reasonable for a local desktop app where the user picks the file to inspect/restore. This is not a Critical/High finding — it does not block PASS.

---

## 2. Test Execution (this round)
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups` | 92 tests, 92 passed, 331 assertions, 0 failed |
| `php artisan test --compact` (full suite, run 1) | 732 tests — 1 failure: `VaultReconcileTest::'quick mode skips hashing an unchanged file and detects a same-size edit only when verified or full'` (`Failed asserting that 1790743294 is identical to 1790743295` — an off-by-one-second mtime comparison) |
| `tests/Feature/Services/VaultReconcileTest.php` in isolation | 23 tests, 22 passed, 1 skipped, 0 failed — did not reproduce |
| `php artisan test --compact` (full suite, run 2) | 732 tests, 721 passed, 2116 assertions, 11 skipped, 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |

The single full-suite failure is a one-second mtime-comparison flake in `VaultReconcileTest` (`app/Services/VaultIndexService.php`'s quick-mode reconciliation), not reproduced in isolation or on a second full-suite run, and unrelated to any file this feature's diff touches (`BackupService`, `ArchiveService`, `FileHashService`, the two ADRs, `BackupInspectTest.php`). Consistent with the pattern noted in rounds 1-2 (a different, also non-reproducing filesystem-timing flake each time) — worth investigating independently of Phase 6, out of scope for this sign-off.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | — | — | `app/Services/BackupService.php` | Fixed as directed in round 2 (caps kept, ratio gate added). Closed — see §1 for the residual, separately-tracked concern (QA-05). | — | Closed |
| QA-05 | Medium | MODERATE | `app/Services/BackupService.php:815-821`; `.ai/decisions/backup-restore-semantics.md:91`; `.ai/decisions/backup-archive-format.md:168` | The new `MAX_COMPRESSION_RATIO` gate reads `compressed_size` from `ArchiveService::entries()` (ZIP central-directory metadata via `statIndex()`), which PHP's `ZipArchive`/libzip does not cross-validate against the local file header or against bytes actually delivered by `getStream()`. Empirically confirmed (PoC): a single 4-byte edit to a ZIP's central-directory record can make a genuinely ~1023:1-compressed, physically-tiny archive report any ratio the attacker likes (down to a "safe" 1:1), with zero increase in physical file size, while `getStream()`/`fread()` still streams the full true (large) payload unaffected. The gate is therefore trivially bypassable and provides no protection against a deliberately crafted hostile archive — only against one built with ordinary compression tooling. Both amended ADRs currently claim the opposite ("bounds Stage 8 work to roughly the archive's own physical (on-disk) size times 250"), which this PoC disproves. | Correct both ADR passages to state plainly that the ratio gate is a best-effort heuristic that defeats naively-built hostile archives only, and that the actual, unconditional worst-case bound remains `hashStream()`'s `expected['size'] + 1` cap tied to the round-1 absolute caps (`MAX_DECLARED_FILE_BYTES`/`MAX_DECLARED_TOTAL_BYTES`) — not the ratio gate. No code change is required to stay safe (the true bound already holds and is unchanged/accepted this round), but the code comment on `MAX_COMPRESSION_RATIO` (`BackupService.php:59-72`) should get the same correction so a future reader doesn't over-trust it. Optional/Low: a regression test forging central-directory bytes to document the known limitation, if the team wants it captured in the suite. | 1 |

**No Critical or High issues found.**

---

## 4. Code Review Notes
- **Security & Authorization**: no change to auth/ownership scoping this round; out of scope for QA-01's re-test.
- **Validation & Data Integrity**: the Stage 7/8 ordering (`validateArchive()`) remains correct — no path reaches Stage 8 hashing without first passing every Stage 2-7 check, including the new ratio gate, in both `inspect()` and `restore()`. The true safety backstop (`hashStream()`'s `expected['size'] + 1` cap, itself bounded by round-1's absolute caps) is sound and unaffected by anything raised in this round.
- **Performance**: no change to query patterns; this round is entirely about the CPU/I-O bound of the ZIP-inspection pipeline, addressed above.
- **Conventions**: `BackupService` continues to route all disk/archive access through `ArchiveService`/`FileHashService`, per ADR `service-layer-architecture`. No raw filesystem or hashing calls introduced.
- **Documentation accuracy**: flagged above (QA-05) — the amended ADR text overclaims what the new gate guarantees against a deliberately adversarial input. This is the second round in a row an ADR amendment for this exact area has overstated its own protection (round 2: "fails fast"; round 3: "bounds work to physical size"), so I'd suggest the developer's next pass state the residual limitation explicitly rather than reach for the strongest-sounding phrasing.

---

## 5. Routing Recommendation
- [x] **PASS** — zero open Critical/High issues. Per `.claude/CLAUDE.md`, this is Level 4: route to `system-analyst` for final sign-off before moving the folder to `.ai/features/completed/`.
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: QA-05 (Medium/MODERATE, Fix Attempts 1/3) — documentation-only fix recommended (correct both ADR passages + the `MAX_COMPRESSION_RATIO` code comment); no code behavior change required since the true bound is already sound and was explicitly pre-accepted by the orchestrator this round. This does not block PASS; recommend folding into the analyst's sign-off pass or one more quick developer pass, at the orchestrator's discretion.
- [ ] **FAIL — MAJOR** → System Analyst: none.
