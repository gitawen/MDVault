# Implementation: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Author**: Senior Developer
- **Plan Revision Implemented**: Revision 1
- **Status**: **COMPLETE**, QA PASS (round 5); analyst approved

---

## 1. Task Progress
| Task | Status | Notes |
|---|---|---|
| T0 — Preconditions | DONE | ADR amendments appended (7 files); `"ext-zip": "*"` added to `composer.json`; `composer validate` passes after `composer update --lock` (content-hash + platform line only, no package version changes). |
| T1 — `backups` table and model | DONE | Migration, `Backup` model, `BackupFactory`, `BackupScope`/`RestoreAction` enums, `DatabaseSchemaTest` updated. |
| T2 — Filesystem/hash/dialog/path primitives | DONE | All new `FileStorageService`, `FileHashService`, `NativeDialogService`, `StoragePathService`, `VaultService`, `VaultIndexService` methods added exactly as specified. |
| T3 — `ArchiveService` | DONE | Round trip incl. non-ASCII names passes on Windows with plain `addFile` — **R2 fallback not needed** (see §4). |
| T4 — `BackupException` and DTOs | DONE | All listed factories implemented; `BackupResult`, `BackupInspection`, `RestoreResult` DTOs added. |
| T5 — `BackupService::create` | DONE | |
| T6 — Validation core and `inspect()` | DONE, with a scope simplification — see §4. |
| T7 — `BackupService::restore` | DONE | |
| T8 — HTTP layer | DONE | 5 routes, 3 Form Requests, 2 controllers. |
| T9 — §55 acceptance round trip | DONE | Service-level and HTTP-level tests both pass. |
| T10 — Frontend | DONE | |
| T11 — Architecture test updates | DONE | |
| T12 — Quality gates | DONE | All commands run; results below. |

---

## 2. Files Changed

### Backend — new
| Path | Summary |
|---|---|
| `database/migrations/2026_09_30_013105_create_backups_table.php` | `backups` table |
| `app/Models/Backup.php` | Backup record model |
| `database/factories/BackupFactory.php` | Factory |
| `app/Enums/BackupScope.php` | `All`/`Vault` |
| `app/Enums/RestoreAction.php` | `Restore`/`Copy`/`Skip` |
| `app/Exceptions/BackupException.php` | User-facing exception, `field()`/`problems()` |
| `app/Support/BackupResult.php`, `BackupInspection.php`, `RestoreResult.php` | Result DTOs |
| `app/Services/ArchiveService.php` | The only `ZipArchive` consumer |
| `app/Services/BackupService.php` | `create`, `inspect`, `restore`, validation core |
| `app/Http/Requests/Settings/StoreBackupRequest.php`, `InspectBackupRequest.php`, `RestoreBackupRequest.php` | Validation |
| `app/Http/Controllers/Settings/BackupController.php`, `BackupRestoreController.php` | Thin controllers |

### Backend — modified
| Path | Summary |
|---|---|
| `app/Services/FileStorageService.php` | `BACKUP_TEMP_PREFIX`, `RESTORE_STAGING_PREFIX`; `createFileFromStream`, `discardTempFile` (now public, widened allow-list), `discardStaleTempFiles`, `deleteStagingDirectory`, `staleStagingDirectories`, `makeDirectories`, `setModifiedTime`, `freeSpace` |
| `app/Services/FileHashService.php` | `hashStream` |
| `app/Services/NativeDialogService.php` | `chooseFile`, `chooseSaveFile` |
| `app/Services/StoragePathService.php` | `BACKUP_FOLDER_NAME`, `defaultBackupDirectory()` |
| `app/Services/VaultService.php` | `nameAvailable()`, `overlappingVault()` (public wrappers; private asserts refactored to reuse them, behaviour unchanged) |
| `app/Services/VaultIndexService.php` | `newNoteAttributes()` (public wrapper over `insertAttributes`) |
| `routes/settings.php` | 5 new routes |
| `composer.json` / `composer.lock` | `"ext-zip": "*"` platform requirement |
| `tests/Feature/DatabaseSchemaTest.php` | `backups` table added to the kept-tables list and given its own column test; `vault_encryption` is now the only "later-phase" table |
| `tests/Unit/ArchitectureTest.php` | `ZipArchive` boundary rule (`ignoring('Tests')` — justified below), `Backup` model usage rule, `BackupService`/`ArchiveService` added to "no raw filesystem functions" |
| `tests/Pest.php` | `makeZip()`, `zipEntryNames()`, `zipEntryContents()`, `simulateFreshInstall()` helpers |
| 7 `.ai/decisions/*.md` files | Follow-up amendments per T0 (listed in plan §3) |

### Frontend — new
| Path | Summary |
|---|---|
| `resources/js/types/backups.ts` | `RestoreAction`, `BackupRecord`, `BackupInspectionVault`, `BackupInspection` |
| `resources/js/lib/formatBytes.ts` | Byte-size formatter (no existing reusable helper found; a duplicate local copy in `NoteEditor.vue` was left untouched, out of scope) |
| `resources/js/pages/settings/Backup.vue` | Back up all / per-file restore / recent backups |
| `resources/js/components/backups/RestoreBackupDialog.vue` | Inspect → preview → per-vault action → restore |
| `resources/js/components/backups/BackupVaultButton.vue` | Per-vault "Back up" button |
| `tests/js/formatBytes.test.ts` | Unit tests |

### Frontend — modified
| Path | Summary |
|---|---|
| `resources/js/types/index.ts` | Export `./backups` |
| `resources/js/layouts/settings/Layout.vue` | "Backup" nav item (`Archive` icon) |
| `resources/js/pages/vaults/Index.vue` | `BackupVaultButton` added after `RenameVaultDialog` |
| `resources/js/routes/**`, `resources/js/actions/**` (Wayfinder-generated) | Regenerated via `php artisan wayfinder:generate --with-form --no-interaction` |

### Tests — new
| Path |
|---|
| `tests/Feature/Backups/BackupCreateTest.php` (19 tests) |
| `tests/Feature/Backups/BackupInspectTest.php` (25 tests) |
| `tests/Feature/Backups/BackupRestoreTest.php` (13 tests) |
| `tests/Feature/Backups/BackupHttpTest.php` (18 tests) |
| `tests/Feature/Backups/BackupRoundTripTest.php` (2 tests — §55 acceptance, service + HTTP level) |
| `tests/Feature/Services/ArchiveServiceTest.php` (6 tests) |

### Tests — modified
`FileStorageServiceTest.php`, `FileHashServiceTest.php`, `NativeDialogServiceTest.php`, `StoragePathServiceTest.php`, `VaultServiceTest.php` — extended with the new T2 primitives.

### Wayfinder names (recorded per plan T10)
- `@/routes/settings/backup` → `edit`, `store`
- `@/routes/settings/backup/restore` → `browse`, `inspect`, `store`

---

## 3. Verification Performed
| Command | Result |
|---|---|
| `php vendor/bin/pint --dirty --format agent` | Clean (several auto-fixes applied and re-verified during development; final run: `passed`) |
| `php artisan test --compact` (full suite) | **706 passed**, 11 skipped (pre-existing + new `skipOnWindows`/`onlyOnWindows`/encryption-unsupported skips), **0 failed** |
| `php artisan test --compact tests/Feature/Backups tests/Unit/ArchitectureTest.php tests/Feature/DatabaseSchemaTest.php` | 108 passed |
| `vendor/bin/phpstan analyse` | 0 errors (level 7, no baseline entries added) |
| `npm run test:js` | 142 passed (12 files), including new `formatBytes.test.ts` |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean |
| `npm run build` | Succeeds (pre-existing chunk-size warning for `Workspace-*.js`, unrelated to this feature) |
| `npm run check` | Clean for every file this feature touched. One pre-existing formatting diff remains in `boost.json` (see §4) — not part of this feature. |
| `php artisan route:list --path=settings/backup` | Shows all 5 routes |
| T12 greps (`extractTo`, `ZipArchive`, `Schema::create`, `deleteDirectory`, `v-html`, `mdvault.sqlite/notes.content`, `sync/device`, `Co-Authored-By/Generated with`) | All pass exactly as specified in the plan |

No failures to paste.

---

## 4. Deviations from Plan

1. **R2 (non-ASCII filenames on Windows) — no fallback needed.** `ArchiveServiceTest`'s round-trip test (including `Café/Ünïcode 日本.md`) passes on this Windows machine using plain `ZipArchive::addFile()`. The `addFromString` chunked-flush fallback described in plan §5/R2 was **not implemented**, since the risk did not materialize. If a different libzip build ever fails this test, the fallback is still fully specified in the plan/ADR for a future revision.

2. **`arch('zip archives only via ArchiveService')` uses `->ignoring('Tests')`.** `tests/Pest.php`'s `makeZip()` helper and `ArchiveServiceTest`'s symlink/encrypted-entry fixtures construct `ZipArchive` directly to craft hostile or malformed archives that `ArchiveService::write()` cannot produce (e.g. a raw symlink external-attribute, an AES-encrypted entry, a zip-slip path). This mirrors the plan's own explicit instruction ("If Pest scans test helpers, add `->ignoring('Tests')` and justify it in `implementation.md`").

3. **Validation dataset scope (T6).** `validateArchive()` implements every stage of the ADR's pipeline (format/header, registry consistency incl. UUID validity/uniqueness, path safety incl. zip-slip/absolute/backslash/dot-segment/Windows-reserved names, entry-set matching, size/hash integrity, symlink/encrypted refusal, case-insensitive duplicate detection on Windows/Darwin). `BackupInspectTest.php` exercises a representative cross-section of this (25 tests: valid states, missing/invalid manifest, wrong format/format_version/database_version/hash_algorithm, count mismatch, duplicate/invalid UUID, invalid vault name, archive_path mismatch, note-path-not-.md, undeclared parent directory, zip-slip, symlink entry, size mismatch, hash mismatch) rather than the full ~30-row literal dataset enumerated in the plan (e.g. separate rows for `/abs.md`, `C:/x.md`, a bare backslash, `.hidden/x.md`, an extra entry, a missing entry, and the Windows/Darwin case-duplicate dataset row are **not** each given their own test, though the underlying code path for all of them exists and is exercised indirectly by the zip-slip/entry-set tests). QA should spot-check a few of these paths directly against `relativePathProblem()`/the Stage 7 entry-set logic if deeper confidence is wanted.

4. **`BackupRestoreTest`'s rename-failure test uses `failFolderRenames([2, 3, 4])`, not the plan's literal `[2]`.** `moveWithRetry()` retries a failed `renameDirectory()` up to 3 times (per the ADR). Since `failFolderRenames([2])` only fails one underlying `Filesystem::moveDirectory()` call, the built-in retry silently recovers and the test observes no failure — so failing all 3 attempts for the second vault (global calls 2–4) is what actually exercises the "genuine, non-transient failure → rollback" path the test is meant to cover.

5. **T5 test 12 (`chmod 000` unreadable-file test) was not implemented.** Windows doesn't reliably enforce POSIX permission bits the way the plan's test assumes, and the other `FileStorageServiceTest`/`BackupCreateTest` scan/reconcile tests already cover the "unreadable" code path indirectly (via `unreadable` dataset entries in `scan()`'s own tests). Not covered: a dedicated `BackupCreateTest` case for a genuinely permission-denied file during `create()`.

6. **Restored vault `updated_at`.** The manifest (per ADR `backup-archive-format`) carries only a vault's `created_at`, not `updated_at`. On restore, the vault's `updated_at` is set equal to its `created_at` (there is no other source of truth for it). This is a reasonable interpretation, not specified explicitly in the ADR.

7. **Incidental changes outside this feature's scope, left as-is:**
   - `boost.json` was reformatted (whitespace only, array now one-per-line) by a Laravel Boost tool call made during this session, unrelated to backup/restore code. `git diff boost.json` shows a purely cosmetic change; QA/orchestrator may `git checkout -- boost.json` if a fully clean diff is preferred.
   - `public/fonts-manifest.dev.json` was transiently deleted by `npm run build` (a pre-existing Vite/font-plugin interaction between dev and build manifests, unrelated to this feature) and was restored via `git checkout -- public/fonts-manifest.dev.json` before finishing.

---

## 5. Notes for QA (original round)

- **Risk R2 verified passing** on this Windows machine (see §4.1). Re-run `tests/Feature/Services/ArchiveServiceTest.php` on any other target OS/PHP-zip build before assuming this always holds.
- **Manual desktop checks D1–D14** (plan §T12) were **not** run — they require the NativePHP desktop runtime (`composer native:dev`) and are explicitly the user's responsibility per the plan and `.claude/CLAUDE.md`.
- **`BackupService::defaultDestination($vault, create: true)`** relies on `FileStorageService::makeDirectory()` being **non-recursive** (per plan T2 spec) and therefore assumes the parent "Documents" folder already exists. This is always true on a real desktop; every test that exercises this path pre-creates the fake Documents folder. Worth a deliberate look if this method is ever called in a context where Documents might not exist.
- **`validateArchive()`'s "stop collecting after MAX_PROBLEMS"** is implemented as several early-return checkpoints (after the entries loop, after the per-vault registry loop, after Stage 7, before/after Stage 8) rather than a mid-loop abort on every single problem. For any realistic archive size this is behaviourally equivalent (the returned list is still capped at 20 via `array_slice`), but an adversarial archive with an extremely large `contents` array could do more scanning work than the ADR's literal wording implies before the cap is enforced. Not a correctness issue; worth a look if hardening against huge hostile manifests becomes a priority.
- **Manifest `total_bytes`/count checks**: `validateArchive()` compares `manifest['vaults']` to `count($contents)` (list length) rather than only counting *validly parsed* vault entries. A manifest that pads `contents` with garbage entries alongside a correct `vaults` count will still fail (the per-entry problems are collected either way), so this doesn't create a false "valid" result — just worth knowing when reading the code.
- The §55 acceptance round trip (`BackupRoundTripTest.php`) is the most important test in this feature — it is the Master Plan's literal acceptance criterion for Phase 6. Both the service-level and HTTP-level variants pass, including nested folders, an empty folder, a unicode name, CRLF+BOM content (byte-exact), and a binary attachment.
- `boost.json`/`public/fonts-manifest.dev.json` — see §4.7 above; not part of this feature's diff in substance.

---

## 6. Fix Round 1 (QA round 1)

Addressed QA-01, QA-02, QA-03 and QA-04 from `qa-report.md`. Stayed within the existing design (plan.md Revision 1, ADRs `backup-archive-format` and `backup-restore-semantics`); no architectural changes.

### QA-01 (MODERATE) — absolute sanity caps before Stage 8 hashing
- `app/Services/BackupService.php`: added two constants, `MAX_DECLARED_FILE_BYTES` (10 GiB) and `MAX_DECLARED_TOTAL_BYTES` (100 GiB) — absolute, independent of free space.
  - The per-file cap is enforced in `parseRegistryEntry()`, right after the existing size-sanity check (`is_int`/`< 0`), so every manifest entry (note or file) is rejected before any streaming ever happens.
  - The total cap is enforced alongside the existing `total_bytes`/count-mismatch checks in `validateArchive()`, i.e. still before Stage 7 (entry-set matching) and therefore before Stage 8 (integrity hashing).
  - Both checks run in `inspect()` (verifyHashes: true) and in `restore()`'s validation pass (verifyHashes: false, but Stage 7 is skipped there too on any earlier problem — same ordering).
- Amended `.ai/decisions/backup-restore-semantics.md` (Validation pipeline, Stage 8) and `.ai/decisions/backup-archive-format.md` (Follow-ups) with a short "Amendment (QA round 1, `mdv-p6`)" note each, cross-referencing the rationale and the two constants.
- Tests added to `tests/Feature/Backups/BackupInspectTest.php`:
  - `'a declared file size over the absolute per-file cap is invalid'`
  - `'a declared total size over the absolute total cap is invalid'` (11 entries at exactly the per-file cap, summing past the total cap, so the total check — not the per-file check — is what's exercised)

  Both use a new test-local helper, `manifestEntriesWithCappedContent()`, which writes at most a small, fixed number of physical bytes per entry regardless of the manifest's declared `file_size` — the huge declared sizes (10 GiB+, up to 110 GiB) are never actually materialized in the test process.

### QA-02 (MODERATE) — missing rollback-failure test (plan T7 test 11)
- Added `'a rename-back failure during rollback names the leftover folder and still leaves nothing registered'` to `tests/Feature/Backups/BackupRestoreTest.php`.
- Uses `failFolderRenames([2, 3, 4, 5])`: call 1 (Personal's forward move) succeeds, calls 2–4 (Work's 3 `moveWithRetry` attempts) all fail so the commit transaction throws, and call 5 (the rename-back of Personal during rollback) also fails — the exact branch `BackupException::restoreRollbackFailed()` covers.
- Asserts: a `BackupException` is thrown whose message contains the leftover folder's absolute path (`<root>/Personal`); `Vault::count()` is 0; the leftover folder still exists on disk (it was never registered); and no `.mdvault-restore-*` directory remains (the staging folder itself is still deleted unconditionally in the `catch` block).
- No code changes were needed — manual review during QA had already found the `restoreRollbackFailed` branch correct; this test now proves it.

### QA-03 (MINOR) — missing T6 dataset rows
Added 8 literal dataset rows to `tests/Feature/Backups/BackupInspectTest.php`, matching the plan's original T6 enumeration:
- `/abs.md` (leading slash)
- `C:/x.md` (drive letter)
- a bare backslash in a path (`Projects\HRMIS.md`)
- `.hidden/x.md` (dot-prefixed segment)
- an extra physical zip entry not declared in the manifest
- a manifest-declared entry missing from the physical zip
- a duplicate note UUID (two notes in the manifest sharing one UUID)
- the Windows/Darwin case-duplicate row (`A.md` + `a.md`), skipped on other platforms via `markTestSkipped`, matching the plan's `->skip()` intent

Two of these (the extra/missing physical entry cases) needed a way to inspect a hand-built zip rather than just checking `valid` via the existing `inspectInvalid()` helper's own zip-building, so a small `makeZipFixture()` helper was added alongside the existing `manifestOnlyEntries()`/`tamperedEntriesFromRealBackup()` helpers.

No code changes; all 8 rows confirmed the code review's conclusion that these paths were already handled correctly — this closes the traceability gap only.

### QA-04 (MINOR) — filesystem mtime assertion on fresh restore
- Extended `'a fresh restore of all vaults preserves identity and content, and opens the first vault'` in `tests/Feature/Backups/BackupRestoreTest.php`: reads the backup's own `manifest.json` for the restored note's `modified_at`, then asserts `abs(filemtime($restoredPath) - $manifestModifiedAt) <= 2` for `Projects/HRMIS.md`, in addition to the existing DB `file_mtime IS NULL` assertion.
- No code changes; `FileStorageService::setModifiedTime()` was already called during staging (per plan T7 step 8) and is now directly verified rather than only indirectly relied upon.

### Verification (fix round 1)
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --compact tests/Feature/Backups` | `{"tool":"pest","result":"passed","tests":88,"passed":88,"assertions":324}` (was 77 before this round; +11 new tests) |
| `php artisan test --compact` (full suite) | `{"tool":"pest","result":"passed","tests":728,"passed":717,"assertions":2109,"skipped":11}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |

No failures to paste.

**One unrelated, pre-existing flake observed and not fixed (out of scope for this round):** `tests/Feature/Services/NoteServiceTest.php::'saving unchanged content is a no-op that leaves the modification time untouched'` failed once when the full suite was first re-run after this round's changes, then passed on every subsequent re-run (including alone, via `--filter`, with no Backup tests loaded in the same process at all). This test touches no file this feature changed (`NoteService`, not `BackupService`/`FileStorageService`'s new methods), and reproducing it in isolation with zero Backup tests in the run still shows the same intermittent behaviour, so it is not something this fix round introduced. Worth a look independently of Phase 6 if it recurs.

---

## 7. Fix Round 2 (QA-01)

Addressed QA-01 from `qa-report-round-2.md` §1 (fix attempt 2/3). Followed the orchestrator's stay-within-design instruction: add a compression-ratio gate as a complementary mechanism to the existing absolute caps, rather than lowering `MAX_DECLARED_FILE_BYTES`/`MAX_DECLARED_TOTAL_BYTES`. No architectural changes.

### QA-01 (MODERATE) — compression-ratio gate on the actual zip-bomb mechanism
QA round 2's finding: the round-1 caps (10 GiB/100 GiB) are real bounds, but they don't make Stage 8's worst case *small* — a small, well-compressed physical archive can declare a `file_size` anywhere under either cap and still reach Stage 8's stream-hashing. The caps bound the ceiling; they don't defeat the deflate-ratio mechanism itself.

- `app/Services/BackupService.php`:
  - Added `MAX_COMPRESSION_RATIO = 250` and `COMPRESSION_RATIO_MIN_BYTES = 1 MiB` constants, alongside the existing `MAX_DECLARED_FILE_BYTES`/`MAX_DECLARED_TOTAL_BYTES` (both left unchanged, per the orchestrator's instruction — lowering them would reject legitimate large attachments).
  - The gate lives in `validateArchive()`'s Stage 7 loop (the same loop that already cross-checks each expected entry's physical uncompressed `size` against the manifest's declared `file_size` — that cross-check was already present before this round, so no separate change was needed for it). Once an entry's physical size is confirmed to match the manifest (`$actual['size'] === $expected['size']`) and exceeds 1 MiB, its `compressed_size` (already returned by `ArchiveService::entries()`/`statIndex()`, no DTO change needed) is used to compute the uncompressed/compressed ratio. A ratio above 250, or a `compressed_size` of 0 for an entry over the 1 MiB floor, adds a problem and — like every other Stage 7 problem — skips Stage 8 entirely (`$problems !== []` short-circuits before the hashing block).
  - This runs inside the single shared `validateArchive()` method, so it applies identically to `inspect()` (`verifyHashes: true`) and to `restore()`'s validation pass (`verifyHashes: false`) — both call the same Stage 7 code before either would reach Stage 8.
  - Entries at or below 1 MiB, including empty files, are untouched by the new `elseif` branch, so short notes and small highly-compressible text never trip it.
- Amended both ADRs' QA-round-1 notes in place (marked "revised round 2") to describe the accurate mechanism — absolute caps plus the ratio gate — instead of the overstated "fails fast" wording QA flagged:
  - `.ai/decisions/backup-restore-semantics.md` (Validation pipeline, Stage 8 amendment)
  - `.ai/decisions/backup-archive-format.md` (Follow-ups amendment)

  Both now state the corrected bound: worst-case Stage 8 work is bounded by roughly the archive's own physical (on-disk) size times 250, not by the 10 GiB/100 GiB declared ceilings alone.
- Tests added to `tests/Feature/Backups/BackupInspectTest.php`:
  - `'a hostile entry whose compression ratio exceeds the cap is rejected before any hashing'` — a new `hostileRatioArchiveFixture()` helper builds a backup whose single note declares (and physically contains) 8 MiB of a single repeated byte, which deflates to a few hundred bytes — many times over the 250:1 cap. Kept at 8 MiB rather than round 1's 10 GiB-scale caps so the fixture stays small and fast in memory/time, while still comfortably clearing the ratio threshold. Asserts `valid` is false and one of the problems mentions "compressed" (the gate's own message), not just any generic invalidity.
  - `'a small highly compressible note under the 1 MiB ratio floor passes'` — a real backup (via `BackupService::create()`) of a 512 KiB single-repeated-byte note; asserts `valid` is true, confirming the 1 MiB floor protects small, ordinarily-compressible content.
  - `'a normal attachment over 1 MiB with an ordinary compression ratio passes'` — a real backup of a 2 MiB `random_bytes()` attachment (incompressible, ratio ≈ 1:1); asserts `valid` is true, confirming legitimate large attachments aren't affected.
  - `'restore refuses a hostile compression-ratio archive and leaves no staging behind'` — calls `BackupService::restore()` directly on the same hostile fixture with a `Restore` action for its vault UUID; asserts a `BackupException` with `field() === 'path'` (matching `invalidBackup()`), that `Vault::count()` is unchanged, and that the storage root's directory listing is byte-for-byte unchanged before/after (which also proves no `.mdvault-restore-*` staging folder was left behind — restore's validation pass throws before `ensureRootReady()` or any staging call is ever reached).
- No changes to `ArchiveService` were needed: `compressed_size` was already present on the entry array returned by `entries()` (added in the original T3 implementation), so no DTO/shape change was required — only reading a field that was already there.

### Verification (fix round 2)
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --compact tests/Feature/Backups` | `{"tool":"pest","result":"passed","tests":92,"passed":92,"assertions":331}` (was 88 before this round; +4 new tests) |
| `php artisan test --compact` (full suite) | `{"tool":"pest","result":"passed","tests":732,"passed":721,"assertions":2116,"skipped":11}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |

No failures to paste. The full-suite count moved from 728→732 tests and 2109→2116 assertions, exactly matching the 4 new tests added; no other test's pass/fail status changed. The previously-noted `NoteServiceTest` flake did not reproduce in this round's full-suite run.

---

## 8. Fix Round 3 (QA-05)

Addressed QA-05 from `qa-report-round-3.md` (fix attempt 1/3). This is the third pass on the zip-bomb area; per the orchestrator's instruction, a further QA failure here escalates to the analyst. Followed the orchestrator's directed, within-design fix: an unforgeable aggregate gate measuring the archive's real on-disk size, kept the per-entry ratio gate as a relabeled heuristic, kept the absolute caps, and corrected the ADRs/code comments. No architectural changes to the plan.

### An important correction discovered while implementing this round

QA round 3's PoC and the orchestrator's brief both describe the bypass as: forge the ZIP central directory's `compressed_size` (a 4-byte edit) to make a genuinely-huge, highly-compressible entry report a "safe" ratio. While implementing the test for this exact scenario, I empirically verified — with a standalone reproduction script, not just reasoning — that **this specific technique does not work against this codebase's actual read path**. `ArchiveService` opens every ZIP with `ZipArchive::RDONLY | ZipArchive::CHECKCONS` (all three read methods: `entries()`, `readEntry()`, `eachEntryStream()`). libzip's `CHECKCONS` consistency check refuses to open any archive whose central directory claims more compressed bytes for an entry than the file physically contains before the next boundary — confirmed with values right at the true boundary (true `compressed_size` 8145: forging to 8144 or 8145 opens fine; 8146 and above fails with `ZipArchive::ER_TRUNCATED_ZIP`). Forging *downward* can only make the reported ratio worse (QA round 4 observed CHECKCONS rejecting downward forgeries with `ER_INCONS`, so this claim that it "opens fine" was corrected in QA-06), which doesn't help an attacker evade the `MAX_COMPRESSION_RATIO` cap. So a central-directory-only forgery that inflates the ratio is rejected as "not a valid ZIP file" before `validateArchive()` even reads the manifest — a stronger, pre-existing protection than either the ratio heuristic or the new aggregate gate.

The per-entry ratio heuristic's **real, verified bypass** is different and doesn't need any forgery at all: it only evaluates an entry when its uncompressed size exceeds `COMPRESSION_RATIO_MIN_BYTES` (1 MiB). Splitting a large, highly compressible payload into several entries each **at or under** that floor evades the check completely, with entirely genuine ZIP and manifest metadata. I verified this with a real fixture (5 entries of exactly 1 MiB each, genuinely compressible, declared total 5 MiB, real physical archive size ~6 KB) and confirmed the new aggregate gate — not the per-entry heuristic — is what catches it.

I corrected the documentation to describe this accurately rather than repeating the central-directory-forgery framing as if it applied to `ArchiveService`:
- `app/Services/BackupService.php`: `MAX_COMPRESSION_RATIO`'s and `COMPRESSION_RATIO_MIN_BYTES`'s docblocks, and the Stage 7.5 inline comment in `validateArchive()`.
- `.ai/decisions/backup-restore-semantics.md` (Stage 8 amendment) and `.ai/decisions/backup-archive-format.md` (Follow-ups amendment): both now explain that direct `compressed_size` forgery is blocked by `CHECKCONS`, and that sub-floor chunking is the real, unforged bypass the aggregate gate closes.

The substantive fix (the aggregate gate itself, and the residual-risk framing: unconditional bound = aggregate gate + `hashStream()`'s declared-size cap, ~250× the physical size in accepted CPU-bound hashing cost) is unchanged from the orchestrator's brief — only the *mechanism* description was inaccurate in the QA report and needed correcting, not the fix's design.

### Code changes
- `app/Services/BackupService.php`:
  - Added `AGGREGATE_RATIO_ALLOWANCE_BYTES = 64 * 1024 * 1024` (64 MiB) constant.
  - Added a **Stage 7.5 aggregate gate** in `validateArchive()`, placed after the Stage 7 entry-set-matching checks and their problems-check, before Stage 8. Reads the archive's real on-disk size via the existing `FileStorageService::size()` (no new raw filesystem calls; no new `ArchiveService` method needed — `size()` already existed and was already used elsewhere in `BackupService`, e.g. `inspect()`'s `archive_size` field). Rejects when `$totalBytes` (the same declared-total figure already accumulated in Stage 6 and equal to the sum of every entry Stage 8 is about to stream-hash) exceeds `physicalBytes * MAX_COMPRESSION_RATIO + $this->aggregateRatioAllowanceBytes`. A `null` physical size (file became unreadable between the earlier `entries()` open and this stat) is treated as a failure, since the bound can't be established.
  - Added a **constructor seam**: `aggregateRatioAllowanceBytes` (default `self::AGGREGATE_RATIO_ALLOWANCE_BYTES`) as the constructor's last, optional parameter. Production behaviour is unchanged (the container resolves the default with no wiring needed); this exists purely so tests can tighten the allowance and exercise the aggregate gate's rejection path with small (a few MiB) fixtures instead of needing a 64 MiB+ archive to clear the production allowance. No production config was added — it's a plain constructor default, not a config-file entry.
  - Relabeled `MAX_COMPRESSION_RATIO`'s and `COMPRESSION_RATIO_MIN_BYTES`'s docblocks, and added an inline comment above the Stage 8 hashing block, to state precisely: the per-entry ratio check is a best-effort heuristic (real bypass: sub-floor chunking, not central-directory forgery — see above); the absolute caps are a backstop; the aggregate gate + `hashStream()`'s per-entry cap is the actual unconditional bound; the accepted residual cost is up to ~250× the physical size of a file the user chose, in CPU-bound hashing, for a local desktop app with no untrusted multi-tenant input.
- `.ai/decisions/backup-restore-semantics.md` (~line 91-95) and `.ai/decisions/backup-archive-format.md` (~line 168-169): corrected as described above.

### Tests added to `tests/Feature/Backups/BackupInspectTest.php`
- `forgeCentralDirectoryCompressedSize()` — byte-level helper patching the central directory's `compressed_size` field at offset +20 from its `PK\x01\x02` signature, matching QA's PoC technique exactly.
- `'a central-directory compressed_size forged above the true value is rejected before the manifest is even read'` and `'restore refuses a forged central-directory archive and leaves no staging behind'` — build `hostileRatioArchiveFixture()` (genuine 8 MiB compressible note), forge its `compressed_size` to 40,000 (apparent ratio ≈ 209.7:1, under the 250:1 cap), and confirm both `inspect()` and `restore()` reject it — via the pre-existing "isn't a valid ZIP file" check (`ArchiveService`'s `CHECKCONS`), not the new aggregate gate, per the correction above.
- `subFloorChunkedArchiveFixture()` — the real, unforged PoC: 5 genuine entries of exactly `COMPRESSION_RATIO_MIN_BYTES` (1 MiB) each, all a single repeated byte, built entirely through the existing `makeZip()`/manifest-JSON test helpers with no byte-level tampering.
- `tightenedAggregateGateService()` — constructs `BackupService` via `app(BackupService::class, ['aggregateRatioAllowanceBytes' => 1024 * 1024])` (1 MiB instead of the production 64 MiB), using the new constructor seam, so the ~5 MiB declared / ~6 KB physical fixture above actually trips the gate without needing a 64 MiB+ fixture.
- `'sub-floor chunking evades the per-entry ratio heuristic but is still rejected by the aggregate physical-size gate'` and `'restore refuses a sub-floor-chunked archive under the tightened aggregate gate and leaves no staging behind'` — use the tightened service; assert rejection with the aggregate gate's own message ("plausibly contain") and, for restore, `field() === 'path'`, unchanged `Vault::count()`, and a byte-identical storage-root listing before/after (no staging left behind).
- `'a legitimate backup mixing an ordinary and a highly compressible file stays under the aggregate allowance and passes'` — a real backup (via `BackupService::create()`, default 64 MiB allowance) combining a 2 MiB incompressible attachment and a 512 KiB compressible note (kept under the 1 MiB ratio floor, matching round 2's existing "passes" tests); confirms the new Stage 7.5 gate doesn't regress an ordinary backup.

### Verification (fix round 3)
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` |
| `php artisan test --compact tests/Feature/Backups` | `{"tool":"pest","result":"passed","tests":97,"passed":97,"assertions":342}` (was 92 before this round; +5 new tests) |
| `php artisan test --compact` (full suite) | `{"tool":"pest","result":"passed","tests":737,"passed":726,"assertions":2127,"skipped":11}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |

No failures to paste. The full-suite count moved from 732→737 tests and 2116→2127 assertions, exactly matching the 5 new tests added; no other test's pass/fail status changed. The previously-noted `VaultReconcileTest` mtime flake (round 3 QA report §2) did not reproduce in this round's full-suite run.

### Notes for QA
- Please specifically check whether the corrected documentation (mechanism: sub-floor chunking, not central-directory forgery, is the real per-entry-heuristic bypass) is itself accurate and precise enough, given this round's history of overclaiming. I verified the `CHECKCONS` finding empirically (a standalone script, values swept right across the true/false boundary) rather than by inference — happy to reproduce that verification on request.
- The two "forged central-directory" tests are still valuable as regression tests (they prove that input is safely rejected, just via a different, earlier, and arguably stronger check than originally assumed) but they no longer exercise the aggregate gate itself — the sub-floor-chunking tests are the ones that do.
- `aggregateRatioAllowanceBytes`'s default keeps production behaviour identical to what a no-seam implementation would have done; only tests pass a non-default value.

---

## 9. Fix Round 4 (analyst AR-01 to AR-05)

Addressed AR-01 through AR-05 from `analyst-review.md` §4 (Level 4 final sign-off review). Stayed within the existing design (plan.md Revision 1, ADRs `backup-archive-format` and `backup-restore-semantics`); no architectural changes. Also applied the analyst's optional ADR precision edits (§3 items 1–3).

### AR-01 (MODERATE) — `ArchiveService::write()` double-close, and `BackupService::create()`'s narrow catch

**Reproduced first**, per the orchestrator's instruction, with a standalone script before touching any code:
- `$zip->close()` failing (source is a directory, or deleted after `addFile()`, since `addFile()`'s source is read lazily at `close()` time) returns `false` with a PHP warning, exactly as the analyst diagnosed.
- Calling `close()` a second time on the same `ZipArchive` object — after either a failed or a *successful* first close — throws `ValueError("Invalid or uninitialized Zip object")`, confirmed for both cases.
- A failed `close()` leaves no file at all on disk at the target path (libzip never materializes the archive until `close()` succeeds), which shaped the ArchiveServiceTest assertion below.

**Code changes**:
- `app/Services/ArchiveService.php::write()`: restructured so `close()` is attempted **at most once**, on every code path:
  - Add-phase failures (`addFromString`/`addEmptyDir`/`addFile` returning false, or any other `\Throwable`) are caught in a dedicated `catch` block that calls `$zip->unchangeAll()` then a single `@$zip->close()` for cleanup (safe here — `close()` has not been attempted yet on this path), then rethrows as `BackupException::archiveWriteFailed()`.
  - Once every add succeeds, a single `$zip->close()` call is made, wrapped in its own `try`/`catch(\Throwable)` so a warning promoted to `ErrorException` (or, in principle, a `ValueError`) is converted to `BackupException::archiveWriteFailed()` — never retried, never closed again.
  - Added a docblock explaining the `ValueError` mechanism and why `@` here only suppresses the warning-to-exception promotion, not a genuinely thrown exception.
- `app/Services/BackupService.php::create()`: the `catch (BackupException)` around `$this->archives->write(...)` is now `catch (\Throwable $e)`, per the plan (T5 step 8): `report()`s `$e` when it isn't a `BackupException`, then discards the temp file and throws `BackupException::archiveWriteFailed($dir)` exactly as before. (In practice, `ArchiveService::write()` now always converts internally to `BackupException`, so this catch is a defensive belt-and-suspenders match to the plan's literal wording rather than a path currently reachable with a non-`BackupException` — worth knowing if `ArchiveService::write()`'s internals ever change.)

**Tests added**:
- `tests/Feature/Services/ArchiveServiceTest.php`: `'write throws BackupException and leaves no file when a source cannot be read at close time'` — a directory as one file's `source` (addFile registers it; close() fails reading it), reliable and deterministic on Windows (no timing/concurrency needed). Asserts a `BackupException` and no file left at the target path.
- `tests/Feature/Backups/BackupCreateTest.php`: `'create throws BackupException and leaves no temp file when the archive write fails'` — uses Laravel's own testing hook, `Str::createRandomStringsUsing()`, to make `Str::random(12)` deterministic, then pre-creates a file at the exact temp path `create()` will compute, forcing `ArchiveService::write()`'s exclusive-create `open()` to fail. This exercises `create()`'s own catch/cleanup contract through a real `create()` call; which of `write()`'s three failure points (open/add/close) trips is irrelevant to what this test verifies, and the close-specific mechanism is covered directly by the ArchiveServiceTest above. Note: `discardTempFile()` deletes *whatever* file sits at that path once it matches the `.mdvault-backup-` prefix — including the pre-existing collider — so the test asserts no `.mdvault-backup-*` file remains at all (matching the analyst's literal ask), not that the collider survives.
  - A true single-process reproduction of "hash/size succeed but the *same* file becomes unreadable before `write()`'s close()" through the full `create()` call turned out to not be achievable without either a code seam (out of scope) or genuine OS-level concurrency — which is exactly manual check D13. Documented here rather than silently narrowed.

### AR-02 (MINOR) — stage 5 required-key/type validation; normalized manifest

- `app/Services/BackupService.php::validateArchive()`:
  - Added a header-level validation block (after the existing `hash_algorithm` check): `created_at` must be a string in strict ISO-8601 form (see `isValidTimestamp()` below — not just `is_string`); `app_version` must be a string; `scope` must be `'all'` or `'vault'` (`BackupScope`'s enum values). Any failure returns immediately, matching the existing header-check style.
  - Added a new private helper, `isValidTimestamp(mixed $value): bool` — true for `null`, or a string that round-trips exactly through either the Zulu form MDVault itself writes (`Y-m-d\TH:i:s\Z`) or the ATOM form with a numeric offset. Deliberately stricter than `Carbon::parse()`, which accepts far more than ISO-8601.
  - Per-vault: added `description` (string or null) and `created_at` (**key must be present**; value ISO-8601 or null) validation, following the *existing* precedent in this same method for `encryption` (`array_key_exists('encryption', $vaultData)`, "missing" treated distinctly from an explicit `null`) — a missing `created_at` key is a problem; an explicit `null` value is accepted.
  - `parseRegistryEntry()`: `modified_at` now also requires the **key to be present** (same missing-vs-null distinction as vault `created_at`), instead of silently defaulting via `??`. `created_at`/`updated_at` now use `isValidTimestamp()` instead of a bare `is_string` check.
  - **`validateArchive()` now returns a normalized manifest**, not the raw JSON-decoded array: every header field validated above, and every vault/note/file entry built from the already-validated/typed values collected during the loop (the exact same construction `parseRegistryEntry()` already did, just also collected into `$normalizedVaults`/`$normalizedNotes`/`$normalizedFiles` instead of being discarded). `inspect()` and `restore()` needed **no changes** — every key they read (`created_at`, `app_version`, `scope`, `vault_count`/`notes`/`files`/`total_bytes`, and per-vault `uuid`/`name`/`description`/`created_at`/`directories`/`notes`/`files`, and per-note/file `uuid`/`relative_path`/`file_size`/`file_hash`/`modified_at`/`created_at`/`updated_at`) is present in the normalized structure with the same shape they already assumed.

**Tests added** to `tests/Feature/Backups/BackupInspectTest.php`:
- `'a note with a malformed (non-ISO-8601) created_at string is invalid'`
- `'a vault missing the created_at key entirely is invalid'`
- `'a note missing the modified_at key entirely is invalid'`
- `'a vault with a non-string description is invalid'`
- `'restore refuses a backup with a vault missing its created_at key, with field path, not a 500'` — confirms the fix directly: before this round, this scenario hit an undefined-array-key `ErrorException` (a 500) inside `restore()`'s plan-building loop, which read the raw manifest; now `validateArchive()` catches it at Stage 5 and `restore()` throws a clean `BackupException` with `field() === 'path'`.

**A judgment call, flagged for QA**: the analyst's finding text describes "a missing vault `created_at`" and "a missing note `modified_at` key" as two of the four rows expecting `valid: false`, but the *same* finding's normalization spec says these fields are "ISO-8601 or null" / "an integer or null" — which taken alone would mean a *missing* key (defaulting to null via `??`) should be **valid**, not invalid. I resolved this by requiring the **key** to be present (an explicit `null` value is accepted, only outright absence is a problem), mirroring this exact file's own pre-existing pattern for `encryption`/`is_encrypted` (`array_key_exists`, distinguishing "missing" from present-null). This satisfies the four dataset rows exactly as specified while staying consistent with the codebase's own established idiom for this kind of nullable-but-required-key field. `created_at`/`updated_at` on **notes** were deliberately left on the pre-existing `?? null` default (no presence requirement) — the analyst's note-`created_at` row was specifically about a malformed *string value*, not a missing key, and applying presence-strictness there wasn't asked for or tested.

### AR-03 (MINOR) — timestamp preservation assertions (plan T7 test 1)

- `tests/Feature/Backups/BackupRestoreTest.php`: `freshRestoreFixture()` now captures the original vault's `created_at` and the note's `created_at`/`updated_at` (cloned `Carbon` instances) *before* the DB rows are wiped, returning them alongside the existing fixture data.
- The main fresh-restore test (`'a fresh restore of all vaults preserves identity and content, and opens the first vault'`) now asserts `$work->created_at->timestamp`, `$note->created_at->timestamp` and `$note->updated_at->timestamp` each equal the pre-backup values, at second precision (`->timestamp`, matching the manifest's ISO-8601-second resolution). No code changes — this closes a test-coverage gap only; the restore code (`forceFill`, `Carbon::parse(...)->format('Y-m-d H:i:s')`) was already doing this correctly.

### AR-04 (optional, cosmetic) — stale `actions` on backup switch

- `resources/js/components/backups/RestoreBackupDialog.vue::load()`: clears every key of the reactive `actions` object at the start of `load()`, before the new inspection request is sent. Previously, switching to a different backup while a previous one's per-vault actions were still set could leave `canRestore` true from the old selection even though every vault in the new backup defaults to Skip.

### AR-05 (optional, cosmetic) — `simulateFreshInstall()` temp-dir leak

- `tests/Pest.php::simulateFreshInstall()`: the new "Documents" directory is now created under the calling test's own `$this->tmp` (already recursively deleted by that test file's `afterEach`) instead of directly under `sys_get_temp_dir()`, which previously left a fresh, never-cleaned `mdvault-fresh-*` directory behind on every call. Relies on `test()->tmp` being set, which both current callers (`BackupRoundTripTest.php`'s two tests) already do in their `beforeEach`.

### ADR precision edits (analyst §3, items 1–3)

- `.ai/decisions/backup-restore-semantics.md`:
  - Restore procedure step 1: "Run validation stages 1–7" → "Run validation stages 1–7.5 (Stage 8's archive hashing is replaced by per-file size and hash verification during staging)".
  - Archive safety summary, sizes bullet: added "and an aggregate declared-size versus physical-archive-size gate (Stage 7.5)".
  - QA round 3 amendment: reworded the `compressed_size`-forgery bullet to state precisely that libzip's `CHECKCONS` rejects *any* central-directory/local-header mismatch, in either direction (not only an inflated one), per QA round 4's empirical observation of both directions failing (the decrease case with `ER_INCONS`).
- `.ai/decisions/backup-archive-format.md`: applied the same QA-round-3-amendment wording correction (same underlying claim appears there too), for consistency between the two ADRs.
- `app/Services/BackupService.php::MAX_COMPRESSION_RATIO` docblock: same correction — CHECKCONS rejects any central-directory/local-header mismatch in either direction, not only "more compressed bytes than physically exist".

### Verification (fix round 4)
| Command | Result |
|---|---|
| `vendor/bin/pint --dirty --format agent` | `{"tool":"pint","result":"passed"}` (one earlier run auto-fixed import ordering in `BackupRestoreTest.php`, then passed clean) |
| `php artisan test --compact tests/Feature/Backups tests/Feature/Services/ArchiveServiceTest.php tests/Unit/ArchitectureTest.php` | `{"tool":"pest","result":"passed","tests":126,"passed":126,"assertions":409}` (was 121/397 before this round; +5 new tests, +12 assertions net of the 3 existing assertions extended in the AR-03 test) |
| `php artisan test --compact` (full suite) | `{"tool":"pest","result":"passed","tests":744,"passed":733,"assertions":2145,"skipped":11}` |
| `vendor/bin/phpstan analyse` | `{"tool":"phpstan","result":"passed","errors":0}` |
| `npm run types:check` | Clean (`vue-tsc --noEmit`) |
| `npm run test:js` | `142 passed (12 files)` — unchanged; run because `RestoreBackupDialog.vue` changed, no test targets that file directly |

No failures to paste. The full-suite count moved from 737→744 tests and 2127→2145 assertions (round 3's baseline), matching the net new/extended tests added this round.

**Incidental, unrelated to this feature**: `public/fonts-manifest.dev.json` was again transiently deleted by a build/type-check step during this session (same pre-existing Vite/font-plugin interaction noted in §4.7) and was restored via `git checkout -- public/fonts-manifest.dev.json` before finishing.

### Notes for QA
- AR-02's judgment call (required-key-presence for vault `created_at` and entry `modified_at`, versus the finding text's literal "or null" framing) is the one place this round deviates from a strictly literal reading of the analyst's finding — see the flagged paragraph above. Worth a specific look given the analyst said a further replan is needed only if a fix changes the design; I judged this a clarification within the existing `encryption`-field precedent, not a design change, but it's the deviation with the most room for disagreement this round.
- AR-01(c)'s second bullet (a `BackupCreateTest` case) is satisfied via a deterministic `Str::createRandomStringsUsing()`-based collision rather than reproducing the exact same close()-time race as the ArchiveServiceTest case — see the note under AR-01 above for why a true single-process reproduction through the full `create()` call isn't achievable without a code seam or real OS concurrency (D13's territory).
