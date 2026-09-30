# Analyst Review: Phase 6, Backup and Restore (Level 4 final sign-off)

## Metadata
- **Feature**: Phase 6: Backup and Restore (`mdv-p6`), Master Plan Phase 6 (§55)
- **Reviewer**: System Analyst
- **Date**: 2026-09-30
- **Inputs**: `plan.md` (Revision 1), `requirements.md`, `implementation.md` (fix rounds 1–3), `qa-report.md` through `qa-report-round-4.md`, ADRs `backup-archive-format` and `backup-restore-semantics` (with amendments), the 7 amended ADRs from T0, and the working-tree code (`BackupService`, `ArchiveService`, `FileStorageService`, both controllers, `RestoreBackupDialog.vue`, and the Backups/Archive tests).
- **Verdict**: **CHANGES REQUIRED.** These are minor fixes within the existing design: no replan and no change to any ADR decision. When AR-01 and AR-02 are fixed and a targeted QA round 5 passes, treat this review as **APPROVED** without another analyst pass, unless the fix changes the design.

---

## 1. Summary
The architecture was delivered as designed and the core guarantees hold:
- Backups are verified before they are published.
- Restores are validated, staged and committed in one transaction, with compensation if anything fails.
- UUIDs survive the round trip.
- No absolute paths appear in the manifest.
- Zip-slip, symlink, encrypted-entry and zip-bomb inputs are refused.
- The §55 round trip is genuinely automated.

I found two unexplained deviations from the plan and ADR in the error-handling paths. Neither can damage data or partially overwrite anything, but both break the "every failure is a user-readable message" contract (§43; ADR `backup-restore-semantics`, validation pipeline). One of them sits directly under manual check D13. They are small and should be fixed before the phase is closed.

---

## 2. Conformance

### 2.1 ADR `backup-archive-format`
| Decision | Status | Evidence |
|---|---|---|
| `ZipArchive` only in `ArchiveService`; `"ext-zip": "*"` | Met | Arch rule (`ignoring('Tests')` is justified in impl §4.2); `composer.json` |
| Layout: `manifest.json` first, then `vaults/<Name>/…`, explicit directory entries, no `mdvault.sqlite` (H2) | Met | `ArchiveService::write` ordering; `create()` directory entries |
| Manifest format 1: keys, counts, SHA-256 of the bytes on disk, no absolute paths, no `file_mtime` | Met | `create()` lines 319–333; BackupCreateTest manifest test |
| Inclusion rules: dot entries, `node_modules` and symlinks skipped; `.md` files are notes and everything else is a file | Met | `scan()` with `isIgnoredName`, `isIndexableFileName` |
| All-or-nothing: unreadable file, non-UTF-8 path, `.md` without a row → fail | Met | `create()` lines 236–266 |
| Write procedure: temp sibling, full re-validation with hashes, never-overwrite rename, record, "kept but unrecorded" on DB failure | Met, with one gap | See **AR-01**: the plan's `catch (Throwable)` around `write()` was narrowed to `catch (BackupException)` |
| Destination rules (H7) | Met | `assertDestination()` |
| `backups` table (H8), with no FK and `recent()` returning 20 with a live `exists` | Met | Migration, model, `recent()` |
| Settings not backed up (H3), no `backup.*` keys | Met | |
| Amendments (QA rounds 1–3) | Coherent and accurate | See §3 |

### 2.2 ADR `backup-restore-semantics`
| Decision | Status | Evidence |
|---|---|---|
| One flow with Restore / Copy / Skip; registered UUID + Restore → refused | Met | `restore()` lines 1284–1300 |
| Identity: original UUIDs; a colliding note UUID is re-identified with a warning; timestamps and description preserved; `file_mtime` null | Met in code; preserved timestamps not tested | See **AR-03** |
| Destination: storage root plus the `(restored)` / `(restored N)` scheme, 100-character truncation, never merge | Met | `resolveName()`, `nameCandidateAvailable()` |
| Validation stages 1–8 plus 7.5 | Mostly met | See **AR-02**: stage 5 does not check "timestamps are ISO-8601 or null", and some manifest keys the code reads are never validated as present |
| Restore: stages 1–7.5 → `ensureRootReady` → stale cleanup → plan → space check → stage with per-file size and hash checks → one transaction with ordered renames and rename-back → remove staging → Full reconcile → open the first vault | Met | `restore()`, `stagePlan()`, `moveWithRetry()` |
| Staging exception (H9): `deleteStagingDirectory` is the only recursive delete, guarded by prefix, directory and non-symlink checks | Met | `FileStorageService` lines 488–499 |
| Archive safety: no `extractTo`, exclusive create, byte caps while streaming, CHECKCONS | Met | `createFileFromStream` caps at the declared size; all reads use `RDONLY \| CHECKCONS` |
| Transport: inspect is JSON 200/422; restore is an Inertia POST that maps `field()`; path-based selection with no upload | Met | Controllers |

### 2.3 Master Plan non-negotiable rules
| Rule | Status |
|---|---|
| 1. Markdown is the source of truth; no `notes.content` | Holds: bytes are copied verbatim, the registry holds only identity metadata, and no content column exists |
| 2. SQLite is metadata only; the only new table is `backups` | Holds |
| 3. Relative paths | Holds: the manifest is relative-only; `relative_path` = the restored folder name. `backups.path` is absolute, which the ADR accepts as machine-local metadata |
| 4. Stable UUIDs | Holds: Restore keeps them, Copy issues new UUIDv7s |
| 5. Filesystem logic in services | Holds: arch rules cover this; Vue only passes paths through |
| 8. SHA-256 | Holds: the manifest, publish-time verification, staging verification and the ZIP's own hash all use it |
| 9. External edits | Holds: Quick reconcile before backup, Full reconcile after restore, and a file changed during backup fails verification |
| 10. DB and filesystem consistency | Holds: staging, a single transaction and rename-back compensation; a leftover folder is reported by name |
| 12. Validate first, import second, temp dirs, never partially overwrite | Holds. Note that restore re-validates with `verifyHashes: false` and instead checks each file's hash while staging. This matches the ADR |
| 14. Offline | Holds: no network use except the local NativePHP dialog bridge |
| 15. No scope creep | Holds: no sync, device or settings tables; no upload |

### 2.4 §55 acceptance round trip
**Genuinely met.** `BackupRoundTripTest` (service level) does the following:
1. Builds two vaults through the real services, with a saved note, nested folders, an empty folder, a unicode name, CRLF + BOM content and a binary attachment.
2. Writes the backup outside the storage root.
3. Wipes every vault, note, setting and backup row, deletes the old root and repoints Documents.
4. Runs inspect (both vaults `new`), then restore.
5. Asserts that vault uuid, name, description and `relative_path` are equal, that paths sit under the new root, that note uuid, `relative_path`, hash and size are equal, that every byte (notes and attachment) is equal, that the empty folder exists, that a Full reconcile reports no changes, and that a current vault is set.

The HTTP-level variant runs the same flow through the real routes and ends by rendering `notes.show` with the original content.

### 2.5 Deviations from plan Revision 1
| # | Deviation | Explained? | Needs rework? |
|---|---|---|---|
| 1 | R2 fallback not implemented (the non-ASCII test passes on Windows) | Yes (impl §4.1) | No |
| 2 | Arch `ignoring('Tests')` | Yes (impl §4.2) | No |
| 3 | T6 dataset initially partial | Yes; closed by QA-03 | No |
| 4 | Rename-failure test uses `[2,3,4]` because of the retry loop | Yes (impl §4.4) | No |
| 5 | T5 test 12 (`chmod 000`) omitted | Yes (impl §4.5) | No; accepted Windows limitation |
| 6 | Restored vault `updated_at` = `created_at` | Yes (impl §4.6) | No; consistent with the manifest shape |
| 7 | Absolute caps, the ratio heuristic, the Stage 7.5 gate and the constructor seam | Yes (QA rounds 1–3; ADR amendments) | No |
| 8 | `create()` catches only `BackupException` around `archives->write()`; the plan says `catch (Throwable)` | **No** | **Yes (AR-01)** |
| 9 | Stage 5 does not check that timestamps are ISO-8601 or null, and some required keys are never checked for presence | **No** | **Yes (AR-02)** |
| 10 | T7 test 1 does not assert that vault and note `created_at` are preserved | **No** | Yes, small (AR-03) |

---

## 3. ADR amendment accuracy
- **Coherent and accurate** as amended through QA-06:
  - the absolute caps are described as a backstop;
  - the per-entry ratio check is described as a heuristic whose real bypass is sub-floor chunking;
  - the unconditional bound is the Stage 7.5 aggregate gate (declared total ≤ physical size × 250 + 64 MiB, using `FileStorageService::size()`) plus `hashStream`'s `size + 1` cap;
  - CHECKCONS is credited with rejecting central-directory forgeries.

  I confirmed in `validateArchive()` that `$totalBytes` is the exact sum of the `$expectedFiles` sizes that Stage 8 streams, and that Stage 7.5 runs in both `inspect()` and `restore()`. For restore, `createFileFromStream` caps each entry at its declared size, so the same bound covers staging.
- **Optional precision edits** (documentation only; the orchestrator may apply them directly):
  1. `backup-restore-semantics.md` → Restore procedure, step 1: change "Run validation stages 1–7" to "Run validation stages 1–7.5 (Stage 8's archive hashing is replaced by per-file size and hash verification during staging)".
  2. The same ADR → "Archive safety summary", sizes bullet: add "and an aggregate declared-size versus physical-archive-size gate (Stage 7.5)".
  3. `BackupService::MAX_COMPRESSION_RATIO` docblock and ADR amendment paragraph: the wording "refuses … more compressed bytes than the file physically contains" is true but incomplete. QA round 4 showed CHECKCONS rejects **any** mismatch between the central directory and the local header in either direction. This is optional, because the current text no longer claims anything false.

---

## 4. Findings

| ID | Severity | Classification | Location | Finding | Required change |
|---|---|---|---|---|---|
| **AR-01** | Moderate | MODERATE (within design) | `app/Services/BackupService.php` `create()` ~L341–347; `app/Services/ArchiveService.php` `write()` L43–73 | The plan (T5 step 8) says `try { write } catch (Throwable) { discardTempFile; throw archiveWriteFailed }`. The code catches only `BackupException`. There is also a latent problem in `ArchiveService::write()`: if `$zip->close()` fails (for example, a source file is deleted, locked or unreadable between the scan and `close()`, which is when `addFile` reads lazily), PHP sets the internal handle to null. The `finally` block then calls `@$zip->close()` again, which in PHP 8 throws `ValueError("Invalid or uninitialized Zip object")`. The `@` operator does not suppress exceptions. In addition, the close warning itself becomes an `ErrorException` under Laravel's error handler. Either way a non-`BackupException` escapes `create()`: the temp file isn't explicitly discarded, and `BackupController::store` returns a 500 instead of the "couldn't write / changed during backup" toast. No broken ZIP is ever published, because the rename is never reached, so data safety holds. However, this is exactly the D13 scenario (files changing during backup) and breaks §43 user-readable errors. The diagnosis comes from reading the PHP `ext/zip` source and has not been executed; the developer should reproduce it first. | (a) In `ArchiveService::write()`, track whether `close()` was attempted and never call it a second time; on an add failure, discard the pending changes (`unchangeAll()`) before the cleanup close, or let the caller discard the file. (b) In `BackupService::create()`, catch `\Throwable` around `write()` as the plan specifies: `report()` anything that isn't a `BackupException`, call `discardTempFile($tmp)`, then throw `archiveWriteFailed($dir)`. (c) Add a test to `ArchiveServiceTest` that makes `close()` fail (for example, a `source` that is a directory, or a source deleted after being added through a test-only path; the developer chooses whatever reproduces reliably on Windows) and asserts that `write()` throws `BackupException` and no file remains at `$path`. Add a `BackupCreateTest` case asserting `create()` then throws `BackupException` and leaves no `.mdvault-backup-*` file behind. |
| **AR-02** | Low | MINOR (within design) | `BackupService::validateArchive()` (stage 5), `parseRegistryEntry()`, `inspect()` L1104–1146, `restore()` L1313–1357 | Stage 5 in the ADR requires "timestamps are ISO-8601 or null". The code checks only `is_string`. Several keys that downstream code reads directly are never checked as present or correctly typed: header `created_at`, `app_version` and `scope`; each vault's `description`, `created_at` and `directories`; and each entry's `modified_at`, `created_at` and `updated_at`. `parseRegistryEntry` treats a missing key as null, but `restore()` and `inspect()` then read the raw array and hit undefined keys. Consequences for a hand-crafted or third-party manifest: (i) a missing key produces an `ErrorException` and a 500. In `restore()` this happens while planning, after `ensureRootReady()` but before staging, so nothing visible changes. (ii) A non-ISO timestamp passes `inspect` as `valid: true`, and restore then fails inside the transaction with "Nothing was restored." (it is compensated correctly). So inspect's verdict and restore's outcome disagree. There is no data-safety impact, and MDVault-generated manifests are unaffected. | Validate in stage 5 and return a **normalized** manifest from `validateArchive()` (every key present, correctly typed, with defaults applied), so `inspect()` and `restore()` never read raw manifest keys. Specifically: header `created_at` must be an ISO-8601 string, `app_version` a string, and `scope` one of `all` or `vault`. Vault `description` must be a string or null, vault `created_at` ISO-8601 or null, and `directories` a list (present or defaulted). Every entry timestamp must be ISO-8601 or null, checked strictly (for example, `DateTimeImmutable::createFromFormat` against the Zulu and ATOM forms, or `Carbon::parse` inside a try that reports a problem). Add BackupInspectTest dataset rows for: an invalid note `created_at` string, a missing vault `created_at`, a missing note `modified_at` key, and a non-string description. Each row expects `valid: false` with a specific problem, and for one of them `restore()` throws `BackupException` with field `path` (not a 500). |
| **AR-03** | Low | MINOR (test gap) | `tests/Feature/Backups/BackupRestoreTest.php` test 1 | Plan T7 test 1 requires the vault UUIDs, names, descriptions **and `created_at`** to equal the originals, and FR-10 requires timestamps to be preserved. Neither the vault `created_at` nor the note `created_at`/`updated_at` is asserted anywhere. The code path (`forceFill` with `timestamps = false`, `Carbon::parse(...)->format`) is plausible, and app tz is UTC, but it isn't proven. | Assert that the restored vault's `created_at` and at least one note's `created_at`/`updated_at` equal the pre-backup values, compared at second precision. |
| AR-04 | Cosmetic | MINOR (optional) | `resources/js/components/backups/RestoreBackupDialog.vue` `load()` | `actions` is never cleared when a different backup is loaded, so `canRestore` can be true from the previous backup's entries while every vault in the current one is Skip. The server then returns "Choose at least one vault to restore.", so the effect is harmless but confusing. | Clear `actions` at the start of `load()`. |
| AR-05 | Cosmetic | MINOR (optional) | `tests/Pest.php` `simulateFreshInstall()` | It creates `sys_get_temp_dir()/mdvault-fresh-*` directories that no `afterEach` removes, so test runs leak temp dirs. | Create the new Documents directory under the test's own `$this->tmp`, or return it and delete it in `afterEach`. |

No Critical or High issues. No MAJOR issue; no replan needed.

**Routing**: `senior-developer` fixes AR-01, AR-02 and AR-03 (AR-04 and AR-05 are optional but cheap) → `senior-qa` round 5 runs the targeted scope `php artisan test --compact tests/Feature/Backups tests/Feature/Services/ArchiveServiceTest.php tests/Unit/ArchitectureTest.php`, then the full suite, PHPStan and Pint, plus `npm run types:check` if AR-04 is done → on PASS, this review stands as APPROVED.

---

## 5. Artifact status edits (for the orchestrator)
Apply these once the AR fixes pass QA:
1. `requirements.md` → Metadata → **Status**: "DRAFT, awaiting user approval of H1–H11 (see `plan.md` §6)" → "**APPROVED** (H1–H11 approved 2026-09-30); **DELIVERED**, QA PASS, analyst sign-off in `analyst-review.md`".
2. `plan.md` → Metadata → **Status**: "**BLOCKED**, awaiting user approval…" → "**COMPLETE**: H1–H11 approved 2026-09-30; implemented as Revision 1; QA PASS (rounds 1–4, plus round 5 for AR-01 to AR-03); analyst APPROVED".
3. `plan.md` §3: tick `[x]` on **T0 through T12**. In T12, add "(D1–D14 pending user verification)" after "Manual desktop checks".
4. `plan.md` §2 Backend Components, `BackupService` row: add the constants `MAX_DECLARED_FILE_BYTES`, `MAX_DECLARED_TOTAL_BYTES`, `MAX_COMPRESSION_RATIO`, `COMPRESSION_RATIO_MIN_BYTES`, `AGGREGATE_RATIO_ALLOWANCE_BYTES`, the Stage 7.5 aggregate gate, and the test-only constructor seam `aggregateRatioAllowanceBytes`.
5. `plan.md` §7 Revision Log: add a row. `| 1.1 | 2026-09-30 | QA rounds 1–4 and analyst review | Zip-bomb hardening (absolute caps QA-01, ratio heuristic, Stage 7.5 aggregate gate QA-05) with ADR amendments; documentation correction QA-06; analyst findings AR-01 to AR-03 (write-failure handling, stage-5 normalization, timestamp test). No task or design changes. |`
6. `implementation.md` → Metadata → **Status**: "READY FOR QA (fix round 3 applied — see §8)" → "**COMPLETE**, QA PASS; analyst approved". Append a "Fix Round 4 (analyst AR-01 to AR-03)" section when the fix is made.
7. Optional ADR precision edits: §3 items 1–3 above.
8. Then move `.ai/features/active/phase-6-backup-restore/` to `.ai/features/completed/`.

---

## 6. Manual desktop checks (user; not defects)
Setup: run `php artisan native:migrate` (applies the `backups` migration to the desktop DB), then `composer native:dev`.

- **D1**: Settings → Backup → "Back up all vaults". The Save dialog opens in `Documents\MDVault Backups` (or in Documents if that folder doesn't exist yet). Save, then open the ZIP in Explorer. It should contain `manifest.json` plus `vaults\<Name>\…`, with no `.git`, `.obsidian` or `.mdvault-*` entries, and empty folders present.
- **D2**: Back up a single vault from its card on the Vaults page. The file name contains the vault name.
- **D3**: Cancel the Save dialog. No file is written and nothing is added to Recent backups.
- **D4**: Choose an existing `.zip` and confirm the OS "Replace" prompt. An error toast says MDVault never overwrites, and the existing file is unchanged.
- **D5**: Choose a save location inside a vault folder. An error toast appears and no file is written.
- **D6 (§55 acceptance)**: Create a vault with nested folders, an empty folder, a unicode-named note and an image, and back up. Quit MDVault and delete the vault folders. Run `php artisan native:migrate:fresh` (this **wipes the dev desktop DB**), then relaunch. Go to Settings → Backup → "Choose backup file…"; the preview should show the vaults as new. Restore. The vaults, tree, notes and content should be identical, and the note UUIDs in the URLs should equal those in `manifest.json`.
- **D7**: Restore the same backup again. The preview shows "Already in MDVault" with Skip selected. Choose "Restore as a copy". `Work (restored)` appears with new identities, and the original is unchanged.
- **D8**: Copy the ZIP, edit one note inside the copy with 7-Zip, then run "Check backup". That entry is reported as damaged, Restore isn't offered, and nothing changes on disk.
- **D9**: Rename a `.txt` file to `.zip` and check it. A clear "isn't a valid ZIP file / MDVault backup" message appears.
- **D10**: While restoring a large backup, watch the storage root. Only a hidden `.mdvault-restore-*` folder should appear, then the final vault folders; the staging folder then disappears.
- **D11**: With Wi-Fi off, repeat D1–D7. Everything should still work.
- **D12**: With a vault of about 5,000 notes, backup and restore should each finish in well under a minute. The UI should show a busy state and recover afterwards.
- **D13**: Save a note repeatedly in VS Code (and, after the AR-01 fix, also delete a note in Explorer) while a backup runs. The result must be either success or a clear error toast ("a file changed while it was being backed up" / "couldn't write the backup"). There must never be a broken ZIP (re-check with "Check backup") and never a server-error page.
- **D14**: In Recent backups, delete one backup file in Explorer and reload the page. That entry shows "Missing" and its "Restore…" button is disabled.

Observation, not a defect: backup file names use the app timezone, which is `UTC` in `config/app.php`, as the ADR specifies. Users outside UTC will see a timestamp that differs from their local clock. If that matters, consider local time as a Phase 8 polish item.

---

## 7. Final Response
- **Feature**: `phase-6-backup-restore` (Master Plan Phase 6, §55)
- **Level**: 4, Architectural
- **Verdict**: **CHANGES REQUIRED** (minor, within design). It becomes **APPROVED** once AR-01 to AR-03 pass QA round 5; no further analyst pass is needed unless the fix changes the design.
- **Artifacts**: `C:\Users\cherw\Herd\MDVault\.ai\features\active\phase-6-backup-restore\analyst-review.md` (this document)
- **Open questions**: none for the user. The D1–D14 manual checks are pending user verification.
- **Recommended next step**: `senior-developer` fixes AR-01, AR-02 and AR-03 (plus optional AR-04 and AR-05) → `senior-qa` round 5 on the targeted scope and the full suite → the orchestrator applies the §5 status edits, moves the folder to `completed/` and asks the user to run D1–D14.
