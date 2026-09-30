# QA Report: Phase 6: Backup and Restore

## Metadata
- **Feature Name**: Phase 6: Backup and Restore
- **Feature ID**: mdv-p6
- **Author**: Senior QA Engineer
- **QA Round**: 1
- **Date**: 2026-09-30
- **Verdict**: **PASS** (zero open Critical/High issues; 2 Medium and 2 Low follow-ups)

---

## 1. Requirements Coverage
| Requirement | Implemented | Tested By | Result |
|---|---|---|---|
| FR-01 Back up all | Yes | `BackupCreateTest` (all-vault, skipped-missing tests) | ✅ |
| FR-02 Back up one vault | Yes | `BackupCreateTest` (single-vault, missing-single tests) | ✅ |
| FR-03 Content selection | Yes | `BackupCreateTest` (ignored entries, empty folders, byte-exact) | ✅ |
| FR-04 Manifest | Yes | `BackupCreateTest` (keys/counts/UUIDs/no absolute paths) | ✅ |
| FR-05 Consistent all-or-nothing backup | Yes | `BackupCreateTest` (publish failure, stale temp cleanup) | ✅ |
| FR-06 Destination | Yes | `BackupCreateTest` + `BackupHttpTest` (desktop/browser, cancel, existing file, inside-vault) | ✅ |
| FR-07 Backup records | Yes | `BackupCreateTest` (record, record-failure, `recent()`) | ✅ |
| FR-08 Validate/inspect | Yes | `BackupInspectTest` (25 tests) | ✅ (see QA-03) |
| FR-09 Archive safety | Yes | `BackupInspectTest`/`ArchiveServiceTest` (symlink, encrypted, zip-slip, entry counts) | ✅ (see QA-01, QA-03) |
| FR-10 Restore actions/identity | Yes | `BackupRestoreTest` (restore, copy, collision, registered-UUID refusal) | ✅ |
| FR-11 Restore destination/names | Yes | `BackupRestoreTest` (suffix, unregistered-folder collision) | ✅ |
| FR-12 Atomic restore | Yes | `BackupRestoreTest` (damaged, zip-slip, DB failure, rename failure, stale staging) | ✅ (see QA-02) |
| FR-13 Post-restore verification | Yes | `BackupRestoreTest` (Full reconcile, `current()` open) | ✅ (see QA-04) |
| FR-14 §55 round trip | Yes | `BackupRoundTripTest` (service + HTTP, real wipe via `simulateFreshInstall`) | ✅ |
| FR-15 UI | Yes | `Backup.vue`, `RestoreBackupDialog.vue`, `BackupVaultButton.vue` (single root, no `v-html`, Wayfinder) | ✅ |
| FR-16 Boundaries | Yes | `ArchitectureTest` (ZipArchive, raw-FS, `Backup` model rules) + T12 greps | ✅ |

---

## 2. Test Execution
| Command | Result |
|---|---|
| `php artisan test --compact tests/Feature/Backups tests/Feature/Services/ArchiveServiceTest.php tests/Feature/Services/FileStorageServiceTest.php tests/Feature/Services/FileHashServiceTest.php tests/Feature/Services/NativeDialogServiceTest.php tests/Feature/Services/StoragePathServiceTest.php tests/Feature/Services/VaultServiceTest.php tests/Feature/DatabaseSchemaTest.php tests/Unit/ArchitectureTest.php` | 322 tests, 315 passed, 7 skipped, 0 failed |
| `php artisan test --compact` (full suite) | 717 tests, 706 passed, 11 skipped, 0 failed |
| `vendor/bin/phpstan analyse` (level 7) | 0 errors |
| `npm run types:check` (`vue-tsc --noEmit`) | Clean, no errors |
| T12 greps (`extractTo`, `ZipArchive`, `Schema::create`, `deleteDirectory`, `v-html`, `mdvault.sqlite`/`notes.content`, `sync`/`device`, `Co-Authored-By`/`Generated with`) | All match the plan's expected results exactly |
| `git status` vs `implementation.md` file list | Matches exactly; no stray files (`boost.json`/`fonts-manifest.dev.json` already reverted) |

No failures to paste.

---

## 3. Issues
| ID | Severity | Classification | Location | Description | Expected | Fix Attempts |
|---|---|---|---|---|---|---|
| QA-01 | Medium | MODERATE | `app/Services/BackupService.php::validateArchive()` (Stage 8) / `parseRegistryEntry()` | `inspect()` (a JSON POST endpoint that accepts an arbitrary, untrusted file path) has no upper bound on a manifest entry's declared `file_size` before `FileHashService::hashStream($stream, $expected['size'] + 1)` streams and hashes it. `MANIFEST_MAX_BYTES` (16 MiB) only caps `manifest.json` itself; `MAX_ENTRIES` (100,000) caps entry count, not size. A single well-compressed deflate entry (theoretical max ≈1032:1 ratio) with a self-consistent declared size (so the zip's own central-directory size and the manifest agree, passing Stage 7) can force the single-threaded desktop server to spend a long time decompressing/hashing tens of GB from a modestly sized archive — a zip-bomb DoS specifically on the `inspect` path, which (unlike `restore`) has no free-space-style gate at all. Restore is comparatively safer since it free-space-checks the declared total before staging. | Add an absolute per-file and/or total-declared-bytes sanity cap (e.g. reject `file_size`/`total_bytes` beyond a fixed large threshold, independent of free space) applied in `validateArchive()` before Stage 8, so `inspect()` on a hostile archive fails fast instead of streaming an unbounded amount of attacker-controlled data. | 0 |
| QA-02 | Medium | MODERATE | `tests/Feature/Backups/BackupRestoreTest.php` | Plan T7 test 11 ("Rename-back failure… → `restoreRollbackFailed`, whose message names the leftover folder; no rows") is not implemented. `BackupException::restoreRollbackFailed()` and the compensation-failure branch in `BackupService::restore()`'s commit `catch` block (where `renameDirectory($to, $from)` itself fails during rollback) are never exercised by any test — `grep -rn "restoreRollbackFailed" tests/` returns zero hits. This is the exact scenario the ADR calls out as a known risk ("Rename-back can fail under Windows locks, in which case the user is told which folder to handle"), and is the most complex recovery branch in the whole feature. Manual code review of the branch looks correct (it names the folder left at `$to` and still deletes the staging dir), but it is unproven by any automated test. Deviation 4 in `implementation.md` documents changing test 10's `failFolderRenames` argument but does not mention test 11 being dropped. | Add a test using e.g. `failFolderRenames([2, 3, 4, 5, 6, 7])` (failing both the forward move for vault 2 and its rename-back attempt) that asserts a `BackupException` is thrown, its message names the orphaned folder, `Vault::count()` is 0, and no `.mdvault-restore-*` remains. | 0 |
| QA-03 | Low | MINOR | `tests/Feature/Backups/BackupInspectTest.php` | Per `implementation.md` deviation 3, the plan's ~30-row T6 hostile-archive dataset was reduced to a 25-test cross-section. Notably absent: dedicated rows for `/abs.md`, `C:/x.md`, a bare backslash, `.hidden/x.md`, an **extra undeclared physical entry**, a **missing declared entry**, a **duplicate note UUID**, and the Windows/Darwin case-duplicate row. Code review confirms the shared `relativePathProblem()` and Stage 7 entry-set-matching logic handle all of these correctly (verified by tracing the code paths; e.g. Stage 7's exact-name matching rejects any undeclared physical entry — including traversal names — independent of the manifest's own path-safety checks), so this is assessed as a coverage gap rather than a functional defect. | Add the missing literal dataset rows for full traceability against the ADR's stage list, per the plan's original T6 test enumeration. | 0 |
| QA-04 | Low | MINOR | `tests/Feature/Backups/BackupRestoreTest.php` | Plan T7 test 1 also requires asserting "the file mtimes are restored (±2 s)" after a fresh restore. The current fresh-restore test only asserts the DB's `file_mtime` column is null (correct, per FR-13) but never asserts the actual filesystem mtime was touched via `setModifiedTime` during staging. Separately, T5 test 12 (`chmod 000` unreadable file during `create()`) is not implemented; this is reasonably justified in `implementation.md` (Windows doesn't reliably enforce POSIX permission bits) and the `unreadable` code path is exercised indirectly elsewhere, so no action is required there. | Add a filesystem-mtime assertion (within a few seconds of the manifest's `modified_at`) to the fresh-restore test. | 0 |

**No Critical or High issues found.**

---

## 4. Code Review Notes
- **Security & Authorization**: Zip-slip is prevented architecturally, not just by pattern-matching: every manifest-declared relative path is validated by `relativePathProblem()` (rejects `..`, absolute, drive letters, backslashes, control chars, dot-segments, Windows-reserved names) *before* any path join, and the physical zip's actual entry names are separately cross-checked one-for-one against that already-validated expected set in Stage 7 — an entry with an unsafe or extra name that isn't in the expected set is rejected as "not declared", even if the manifest's own paths were safe. `extractTo()` is never used anywhere (`ArchiveService::eachEntryStream`/`readEntry` stream named entries only). Symlink and encrypted entries are rejected unconditionally in Stage 2, before any manifest parsing, so they can never reach extraction. `stagePlan()` builds staging paths exclusively from the already-validated manifest data, never from raw physical entry names. See QA-01 for the one residual gap (unbounded stream size on the `inspect`-only path).
- **Validation & Data Integrity**: The backup's own write path (`create()`) re-opens and fully re-validates+hashes the archive before publishing and before recording it — genuinely proves every backup is readable. Restore's staging phase verifies size and hash per file as it writes. The DB transaction plus rename-back compensation in `restore()` is correctly scoped (traced by code review across success/DB-failure/rename-failure paths); `deleteStagingDirectory()`'s prefix+non-symlink guard is airtight (uses `basename()` on the literal path, well-tested). No absolute paths appear in the manifest (tested). UUID handling for `restore` vs `copy` vs a colliding note UUID is correct and tested.
- **Performance (N+1, indexes)**: Notes are bulk-inserted in chunks of 500; hashing and ZIP I/O are streamed throughout (no whole-file buffering) except for the QA-01 gap. `backups.created_at` is indexed for the "recent" listing.
- **Conventions (AGENTS.md, `.ai/rules/`)**: `ZipArchive` confined to `ArchiveService` (arch-tested, with a documented and justified `ignoring('Tests')` for hostile-archive fixtures); `BackupService`/`ArchiveService` use no raw filesystem functions (arch-tested); `Backup` model usage restricted (arch-tested); services are `final`; no AI attribution anywhere in the diff; no stray files.
- **Frontend (Inertia/Vue/Wayfinder)**: All three new components are single-root, use Wayfinder route/action imports (no hardcoded URLs), have no `v-html`, and do no filesystem/path logic — presentation only, as required. `vue-tsc --noEmit` is clean.

---

## 5. Routing Recommendation
- [x] **PASS** → move feature to `.ai/features/completed/`
- [ ] **FAIL — MINOR/MODERATE** → Senior Developer: QA-01, QA-02 (Medium/MODERATE — should be addressed, but neither blocks the PASS verdict since they are hardening/coverage gaps, not broken acceptance criteria); QA-03, QA-04 (Low/MINOR, optional follow-ups)
- [ ] **FAIL — MAJOR** → System Analyst: none

This is Level 4: per `.claude/CLAUDE.md`, route to `system-analyst` for final sign-off before moving the folder to `.ai/features/completed/`. Given the Medium findings (QA-01, QA-02), consider looping the developer for one more pass before the analyst's final review, though neither blocks PASS as written.
