# QA Report: Phase 7: Encryption

## Metadata
- **Feature**: Phase 7: Encryption (mdv-p7), Level 4
- **Scope**: Sub-phase 7A, tasks T1–T6 (7B/7C not implemented, not flagged)
- **Reviewer**: Senior QA

---

## Sub-phase 7A — Round 1

### Verdict: PASS
0 Critical, 0 High, 0 Medium, 6 Low (follow-ups). No issue is at 3 fix attempts. No escalation.

### Verification run
| Command | Result |
|---|---|
| 7A tests (EncryptionService, VaultKey, VaultKeyService, VaultEncryptionService, Security/, DatabaseSchema, VaultRegistryReset, ArchitectureTest) | 148 passed, 349 assertions |
| `php artisan test --compact` (full, requested by orchestrator) | 877 tests: 866 passed, 11 skipped (pre-existing), 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |
| `php vendor/bin/pint --dirty --format agent --test` | passed |
| Dev DB | not migrated |

Environment note: `php` is not on the bash PATH here. The commands above were run through the `php` that bash resolved in the Herd shell (PHP 8.4.25), and all of them executed.

### Security review (adversarial). Findings by area
**Crypto, correct.**
- Argon2id 13 with a 16-byte random salt. Bounds (ops 1–10, mem 8192 to 1 GiB, exact base64 lengths, exact key set, depth-8 JSON, 64 KiB cap) are checked in `parseHeader`/`headerFromArray`/`assertHeaderBounds`. `unlock` and `rewrap` call `assertHeaderBounds` before the KDF. Hostile-parameter tests exist.
- Every AEAD call uses a fresh `random_bytes(24)` nonce: wrap, rewrap (new salt and nonce), notes, folders, session.
- AD binds key id, key_version, kdf params and salt for the wrap, and key id plus file id for notes and folders. Both are tested (swap and tamper).
- The wrap AD omits `created_at` and the nonce (the nonce is implicit in AEAD). This is per the ADR and acceptable.
- Subkeys come from the BLAKE2b KDF with distinct ids and contexts. The DEK is never used directly for content.
- No hand-rolled comparisons: the AEAD tag check is constant-time. There is no password verifier.
- Wrong password, tampered params and a tampered wrapped key all give the same generic error. The empty password is rejected before the KDF.
- `sodium_memzero` is applied to the KEK, subkeys and token key via `finally`. Trivial gap: `sealForSession` does not wipe `tokenKey` on its early-throw path (uuid invalid). Best effort only.
- `SodiumException` is never chained. The `catch (\Throwable)` blocks swallow it into fixed-message exceptions.

**Key custody, sound.**
- Session holds only `{key_id, nonce, sealed(DEK under token, AD = vault uuid | key id | epoch), timestamps, epoch}`. A test asserts the data key and token are absent from the session.
- Idle limit is minutes*60 + 120 s grace. `touch:false` leaves the timer alone. The epoch and the mirror `key_id` mismatch each purge the entry.
- `forgetAll` is correct for the dot-notation nesting (it forgets `mdvault.keyring`). `lockEverywhere` bumps the epoch in the cache and forgets the current session.
- `ScreenLocked` listener is gated by the setting and tested.
- Header parsing: 2048-byte cap, at most 20 pairs, strict uuid and token regex, `#[\SensitiveParameter]`.
- Tokens and opened keys are cleared at `provideTokens` and at terminating.

**Judgement on "no purge on missing or wrong token": sound.**
- A missing token is a normal state (a stray tab, a request from another window, a reload). Purging would let any tokenless request lock out the real tab.
- A wrong token cannot be brute-forced (256-bit random) and cannot open the entry anyway, so purging buys no security.
- The sealed entry is useless without the token and is overwritten on the next unlock. Idle and epoch purges still bound its lifetime.
- Keep as is.

**Hygiene.**
- `no-store, private` is applied on the web group (tested). `dontFlash` covers the ADR list (tested). The 423 JSON and redirect rendering has a generic fixed message (tested).
- The lazy shared `security` prop carries only two settings.
- `EncryptionService` and `VaultKeyService` contain no Log, report or dump calls. `VaultEncryptionService` uses `report($e)` on mirror failures. This is allowed by the arch rule, and the exception carries no secrets (the `logs never contain the password` test passes).
- `VaultEncryptionService` does all file I/O via `FileStorageService` (rule 5). The key file is created exclusively or replaced under a hash guard. Reads reject symlinks and files over 64 KiB. A damaged disk key file is refused even when the mirror is fine.
- `resetRegistry` deletes mirror rows before notes and vaults, in the existing transaction. The key file is untouched (tested).
- Mirror sync failure paths: unlock never fails because of the mirror. A failed sync with a mismatching `key_id` fails safe (`keyFor` purges, so the vault stays locked until the next unlock resyncs). A failed sync after `changePassword` keeps the disk file authoritative.
- The key file is authoritative and the mirror is non-secret (wrapped key only). This conforms to rules 1, 3 and 10.

### Issues
| ID | Severity | Class | Status | Fix Attempts | Description |
|---|---|---|---|---|---|
| 7A-QA-01 | Low | MINOR | Open (follow-up) | 0 | `VaultKey` redaction gaps. `var_export($key)` and `(array)$key` expose the private `material` (verified: `K::__set_state(array('m' => 'secret'))`). Reflection does the same. `print_r`, `var_dump`, `json_encode`, `serialize` and `clone` are covered. Nothing in the app does this, and the arch rule bans `var_export` in the two crypto services. Mitigation if desired: keep the material in a non-property holder (for example a static `WeakMap` keyed by object, or a closure). Otherwise accept and document. |
| 7A-QA-02 | Low | MINOR | Open (follow-up) | 0 | `VaultKeyService::isIdle` treats `minutes <= 0` as "never lock". A negative or corrupt stored `security.auto_lock_minutes` therefore fails open. No validation exists yet (the controller is 7C). Clamp negatives to the default in `isIdle`, or restrict to {0, 5, 15, 30, 60} in the 7C request and via `SettingsService`. |
| 7A-QA-03 | Low | MINOR | Open (follow-up) | 0 | Test gaps. (a) No test for the lazy shared `security` prop (`grep auto_lock_minutes tests` finds nothing). (b) No test that the opened-key memo and tokens are cleared at app termination. (c) No test that a missing or wrong token does not purge the entry (the ADR-relevant intentional behaviour). Verify (c) is asserted in `a stored key opens with its token and only with its token`, otherwise add it. |
| 7A-QA-04 | Low | MINOR | Open (follow-up) | 0 | Arch rule coverage is narrower than its title. "libsodium primitives only in EncryptionService" lists 7 function names. It misses other `sodium_*` functions (`sodium_crypto_secretbox*`, `generichash`, `memcmp`, `compare`, `crypto_box*`, `sodium_pad`). `VaultKey::material()` ("only EncryptionService may read") is `@internal` but not arch-enforced. The `sodium_memzero` and `VaultKey` rules were negative-checked by the dev and work. Consider adding the missing function names and a grep-style test for `->material()` call sites. |
| 7A-QA-05 | Low | MINOR | Open (follow-up) | 0 | Mirror `created_at` doubles as the key-file `created_at`, but `syncMirror` sets it only on first create. If a changed disk key file has a different `created_at` and updates an existing mirror row, a key file later rebuilt from the mirror will not be byte-identical, and `header_hash` will differ. Unwrap is unaffected (`created_at` is not in the AD). Cosmetic. Set `created_at` on every sync, or document. Also note the migration relies on `app.timezone = UTC` (currently hard-coded UTC). |
| 7A-QA-06 | Low | MINOR | Open (follow-up) | 0 | The lock epoch lives in the cache. `cache:clear` resets it to 0, so sealed entries from before a screen-lock bump (epoch 0 sessions in other windows) would validate again if the renderer still holds their token. Exposure is small (it needs the token too, and tokens die on reload). Consider a persistent store (a settings row via `SettingsService`) if that matters. Also record that a rollback of the key file to an older valid copy silently resyncs the mirror (accepted by the ADR threat model). |

### Requirements and ADR conformance
- Cryptography ADR: primitives, bounds, AD layout, token format (32 bytes base64url, no padding), file formats (`MDVN`/`MDVF`, version byte, 16 MiB cap, 255-byte name) all match.
- Key-custody ADR: unlock/lock mechanics, session layout, epoch, idle backstop, `ScreenLocked`, `dontFlash` list, `no_store;private` and 423 handling all match. The `is_unlocked` summary field and the lock/unlock endpoints are 7B/7C and out of scope.
- Storage-layout ADR: key-file schema is exactly as specified. Strict parse, disk-wins sync, `createFile` and `replaceFile` guards, and `ensureHeaderOnDisk` all match. The `vault_encryption` migration matches the plan columns.
- Deviations in implementation §4 are reasonable. The `created_at` doubling is noted as 7A-QA-05.
- CLAUDE.md non-negotiables: the filesystem is touched only through services (rule 5). Settings go via `SettingsService` (rule 6). No password or key reaches logs, session, props or flash (rule 11, tested). No notes content column, no new v1 tables beyond `vault_encryption` (rules 1, 2, 15).

### Routing
- **Developer** (optional, none blocking): 7A-QA-01 to 7A-QA-06 may be folded into 7B/7C. Suggested: 7A-QA-02 and 7A-QA-03 during 7B or 7C (they touch the settings request and the shared props).
- **System Analyst**: none.
- 7A is cleared to proceed to 7B.

---

## Sub-phase 7B — Round 1

**Verdict: PASS.** There are 0 Critical, 0 High and 0 Medium issues. There are 4 Low follow-ups. No issue is at 3 fix attempts. My audit was code reading plus the commands below; I added no probe tests. Where I say a test covers something, I relied on the suite passing and on test names, not on reading each assertion.

### Verification
| Command | Result |
|---|---|
| `php artisan test --compact` (full) | 964 tests, 953 passed, 11 skipped (pre-existing), 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors |
| `php vendor/bin/pint --test` | FAIL on `tests/Unit/ExampleTest.php` only (`single_blank_line_at_eof`). The file is untouched by this branch, so the failure was already there (7B-QA-04). No 7B file is flagged. |
| `npm run types:check` | passed |
| `npm run test:js` | 13 files, 155 tests passed |
| `npm run build` | not run |

I did not touch the dev DB. `public/fonts-manifest.dev.json` shows as ` D` (deleted) in the working tree. I did not cause that, since I ran no build. Restore it with `git checkout public/fonts-manifest.dev.json` before committing. `bootstrap/cache/routes-v7.php` is absent, so deviation 4 is fine.

### Findings by focus area

**Locked-vault leakage: no leak found.**
- `WorkspaceController` sends `tree` null, `folders` `['']`, `treeSignature` null and `note` null whenever the key is absent. The key decides, not the client. `Inertia::encryptHistory()` is on for encrypted vaults, and lock calls `clearHistory()`.
- `encryption.unencrypted_files` lists plaintext `.md` paths that are already plaintext on disk. This is intended and is not a leak.
- Toasts for encrypted vaults are generic. I checked the create-note, create-folder, rename, move and delete toasts. `NoteController::destroy` still reads `$note->title` for encrypted notes, but that is the opaque hex id and is not shown.
- `VaultLockedException` has a fixed generic message. The 423 JSON is `{message, reason:'locked'}`. The non-JSON path redirects to the workspace with a generic error toast.
- The new `EncryptedNoteService` exception factories carry no names or paths. The only `report()` calls are on QueryExceptions whose bindings are opaque ids and hashes.
- The `dontFlash` list covers password, password_confirmation, current_password, name, folder, parent, path, source_path, content, frontmatter and token.
- All web responses get `Cache-Control: no-store, private`.
- The rows `EncryptedNoteService` creates for encrypted notes hold only the opaque `<hex>.mdenc` path, `title` and `filename`. SQLite never sees a real name.

**Unlock and create endpoints.**
- Response shapes are exactly `{token}` for unlock and `{uuid, token}` with status 201 for create.
- Passwords reach the services through `#[\SensitiveParameter]` parameters on `createEncryptedVault`, `unlock` and `changePassword`.
- Errors are generic and keyed by `field()`. Validation messages do not echo the password (`confirmed`, `min:10` and `max:1024` use fixed messages). Both endpoints are JSON, so nothing is flashed.
- There is no unlock throttle (7B-QA-01).

**NoteService to EncryptedNoteService seam.**
- All 10 vault-scoped public methods delegate at entry: `create`, `createCopy`, `rename`, `move`, `delete`, `createFolder`, `deleteFolder`, `preview`, `save` and `present`.
- Only `absolutePath` and `canTrash` do not. `absolutePath` is called only from the plaintext branches, and `canTrash` is vault-independent.
- Every encrypted write goes through `createFile` (exclusive create) or `replaceFile` with a ciphertext-hash guard. No plaintext `.md` is ever written into an encrypted vault.
- Encrypted `save()` matches plaintext parity:
  - base-hash check;
  - symlink refusal;
  - plaintext no-op comparison;
  - `replaceFile` guard;
  - post-write re-hash;
  - a registry failure is reported, not compensated (Rule 9).
- `rename` also takes the guard. `move` has no hash guard, the same as plaintext.
- Plaintext `.md` files in an encrypted vault are never indexed or imported. They are only reported, capped at 100 in `plan()` and 20 in `unencryptedFilesIn`.

**deleteFolder, symlinks, name-map staleness.**
- `deleteFolder` removes `folder.mdenc` first, then runs `rmdir`. If `rmdir` fails it recreates `folder.mdenc` and refuses. A symlink or foreign file inside makes `rmdir` fail, so the rollback path covers it. The rollback's `createFile` result is ignored; I rate that negligible.
- The name map is memoized per request (the service is scoped). Its fingerprint covers the key id, directories, name-file size and mtime, and the registry rows, so staleness cannot outlive a request.
- `logicalPathFor` does not touch the idle timer, so the 5-second poll cannot keep a vault unlocked. A null path falls back to the local path in `openNoteStatus.ts`.
- Name collisions are checked only against readable notes. This is documented and acceptable.

**Frontend `keyring.ts`.**
- Tokens live only in a module-level `Map`. I grepped and found no `localStorage`, `sessionStorage`, URL or console use of them. The only matches for `console` or `localStorage` in `resources/js` are `noteSaver.ts`, which the task did not flag, and `keyring.ts`, whose mention is in a comment saying tokens are never stored there.
- The 3 s prune grace and the 20-pair cap behave as designed.
- Auto-lock is an Inertia `router.post` and clears all tokens on success. The server idle limit is the backstop.
- The unlock and create forms exclude the password from remembered state with `.dontRemember('password')`. Vue code does presentation only.
- The `http.onRequest` hook adds the header to every Inertia request without a same-origin check (7B-QA-02).

**Deviations (11 listed in 7.4, judged one by one).**
- Items 1 (`EncryptionHeaderService`, breaks the `VaultService` / `VaultEncryptionService` cycle), 2 (one registration transaction), 3, 4, 5, 6, 7, 9, 10 and 12: accepted. They are sound, tested, and consistent with the ADRs.
- Item 6 (the `/settings/security` removal from the WorkspaceTest 404 list) is correct. The route is now real, mandated by plan §2 and covered by `SecuritySettingsTest`. The other removed routes are still asserted.
- Item 8 (`.mdenc` outside the opaque layout is not indexed): accepted. See the visibility note in 7B-QA-03.
- Item 11 (no 423 mapping on the copy transport): accepted. The editor keeps the user's text and shows a generic error.
- Item 13 (the prune grace) is a sound mitigation.

**Re-check of 7A code touched by 7B.** The `EncryptionHeaderService` extraction keeps the delegating `headerAt` and `adopt` on `VaultEncryptionService`. The 7A tests and the extended arch rules pass.

### Issues
| ID | Severity | Class | Fix attempts | Description | Recommendation |
|---|---|---|---|---|---|
| 7B-QA-01 | Low | MINOR | 0 | `POST /vaults/{uuid}/unlock` has no throttle. Each attempt costs one Argon2 MODERATE run (about 1 s). Only a local process holding the session cookie and CSRF token could brute-force it. Anyone with the key file can run an offline attack anyway, so the endpoint adds little exposure. | Optional: `throttle:10,1` on the unlock route. Otherwise record it as accepted risk. |
| 7B-QA-02 | Low | MINOR | 0 | The `X-MDVault-Unlock` header, which carries every unlocked vault's token, is added to every Inertia/`useHttp` request with no same-origin check. Nothing in the app calls an external URL today. | Hardening: in the interceptor, skip any request whose resolved URL origin is not `location.origin`. |
| 7B-QA-03 | Low | MINOR | 0 | `unencryptedFilesIn` runs a full vault scan on every Workspace render and every reload that includes `encryption`. Separately, a `.mdenc` outside the opaque layout (deviation 8) is ignored with no warning. | Optional: cache or limit the scan, and report foreign `.mdenc` files. Not required for 7B. |
| 7B-QA-04 | Low | MINOR | 0 | `pint --test` fails on the pre-existing, untouched `tests/Unit/ExampleTest.php`. | Fix the trailing newline when convenient. It is not 7B-related. |

### Routing
- No Critical, High or Medium issues, so no blocking items.
- Developer (optional, all Low): 7B-QA-01, 7B-QA-02, 7B-QA-03, 7B-QA-04. They can be deferred or folded into 7C.
- System Analyst: none. ESCALATE: none.
- Orchestrator: restore `public/fonts-manifest.dev.json` before the commit. Ask the user to run `php artisan test --compact` at completion. The manual desktop checks in implementation §7.6 (lock on screen lock, Ctrl+R relock, idle auto-lock) are not automatable here and remain for the user.

---

## Sub-phase 7C — Round 1

**Verdict: PASS.** There are no Critical or High issues. There is one Medium issue (7C-QA-01, crash recovery blocked by a stale lock) and five Low issues. I recommend fixing 7C-QA-01 before release. Manual desktop checklist item 12 (kill the app mid-conversion, restart, vault intact) will fail inside the 30-minute window it describes.

### Verification
| Command | Result |
|---|---|
| `php artisan test --compact` (full) | 1068 tests, 1055 passed, 13 skipped (11 pre-existing, 2 `skipOnWindows`), 0 failed |
| `php vendor/bin/phpstan analyse` | 0 errors (the `vendor/bin/phpstan` shim fails in Git Bash with no `php` on PATH; `php vendor/bin/phpstan` works) |
| `php vendor/bin/pint --test` | passed |
| `npm run types:check` | passed |
| `npm run test:js` | 13 files, 156 tests passed |
| `npm run build` | not run, so `public/fonts-manifest.dev.json` is untouched |
| Dev DB | not migrated |

All of this was static review plus the existing tests. I wrote no new tests and executed no attack scripts. Findings marked "static" were not reproduced.

### Findings by focus area

**VaultConversionService::commit() failure orderings. No data-loss path found.**
- DB failure: the `$record()` closure runs before any rename. A failure rolls back, nothing has moved, and the original is intact.
- First rename fails: the transaction rolls back, nothing has moved.
- Second rename fails: the original is renamed back and the transaction rolls back. If the rename-back also fails, V is missing and O holds the original. Recovery's "V missing, O exists" branch restores it. Both copies cannot be missing, because the staging folder and O both survive. This path is tested.
- Commit failure after the swap: `$swapped` is true, so `undoSwap` runs. It renames V to staging and O to V. If that second rename fails it puts the converted folder back. The next recovery then sees V encrypted, DB unencrypted and O present, a mismatch, and rolls back. The logic is sound, but it is untested (7C-QA-02).
- `deleteOriginal()` runs only after `commit()` returns. Every earlier throw leaves the original in place, so the plaintext original is never deleted before a durable commit.
- `deleteStagingDirectory` is allow-listed by prefix, refuses symlinks, and does not follow links inside the folder.

**VaultRecoveryService::repair() and the lock.**
- The recovery table matches the ADR.
- "V and O exist, `enc(V)` = DB, delete O" cannot hit a not-yet-committed state. The renames happen before the DB commit, so an uncommitted swap always shows a mismatch.
- The real weakness is the cache lock (7C-QA-01).
  - The default cache store is `database`, and its lock row persists across a crash for `LOCK_SECONDS` = 1800.
  - `recoverAll()` at boot and `open()` both go through `recover()`, which returns null while the lock is held. A crashed vault therefore stays interrupted, shown as Missing or with leftover staging folders, for up to 30 minutes.
  - A retried encrypt gets the generic `conversionFailed`.
  - Tests use the `array` cache store, so persistence of the lock across a crash is never exercised.
- If the lock is cleared or expires mid-conversion (static analysis, tiny window):
  - Recovery can undo a swapped but not yet committed state.
  - The conversion's transaction then commits `is_encrypted=true` over a plaintext folder.
  - No file is lost, but the registry and the folder disagree.
  - A conversion longer than 30 minutes could trigger the same thing.

**Encrypt-existing preflight.**
- The preflight uses the unfiltered `inventory()`. It refuses non-`.md` files, dot entries, symlinks, `node_modules`, notes over 16 MiB, unsupported names, notes differing only by case, and insufficient disk space.
- Files and folders that pass preflight but are not registry notes would be deleted with the original. I checked the indexer for this and found none: it uses the same case-insensitive `.md` rule, and symlinks and hidden entries are refused.
- A before/after snapshot (every entry, size, mtime) plus re-hashing of every source note catches mid-build changes and added files.
- A write that lands between the final verify and the first rename is lost with the original (7C-QA-05, a millisecond window).

**Decrypt.** It requires the password, then refuses unreadable notes and foreign files (anything not a key file, `folder.mdenc`, hex folder, registered note or old temp file). It builds a plain staging copy with ` (2)` suffixes, verifies it, and uses the same `commit()`.

**Backups format 2.**
- `encryptionKeyId` requires exactly five keys, a matching format, cipher and KDF, and a UUID key id.
- `readArchivedKeyFile` requires the key file to be listed, to match its declared size and hash, to parse, and to carry the manifest's `key_id`, all within `MAX_HEADER_BYTES`.
- Encrypted vaults accept only hex folders, `<32hex>.mdenc` notes, `folder.mdenc` and the key file.
- A format 1 archive claiming an encrypted vault is refused, and a format 2 plain vault carrying encryption details is refused. Format 1 plain archives still restore (tested).
- Restore is validate-first, staged in a temp directory, with per-file size and hash re-verification, a transaction, and rename-back on failure.
- The restored vault is locked, because the mirror is built from the archived key file. Restore of both a vault and a copy is tested.
- Ten hostile-manifest datasets all leave no vault and no folder behind.
- Gaps are listed as 7C-QA-03 and 7C-QA-06.

**T17 EncryptionSecurityTest.** The assertions are real, not smoke tests. The suite searches hex, base64 and base64url forms of the data key and tokens, plus the passwords, names and content.
- Disk: it scans the whole storage root, which includes any leftover `.mdvault-original-*` sibling folder, and asserts opaque names only.
- ZIP: every entry name and body.
- SQLite: every row.
- Session: after the lifecycle and after failed attempts.
- Logs: via `captureLogs()`, and it asserts that logs were actually captured, for DB failures, tampering, damaged key files, and conversion DB and swap failures.
- Exceptions: message and trace.
- Inertia: locked responses and unlocked key material.
- Also covered: 423 on eight endpoints across five lock paths, old-token replay, and extreme KDF parameters.
- Conversion failures go through `reportGeneric()`, a fresh exception with no SQL bindings, so decrypted names cannot reach logs. The decrypt-side failure log is covered in `VaultConversionServiceTest`.

**Throttle and same-origin interceptor.**
- `throttle:10,1` on `vaults.unlock` is tested (the 11th attempt returns 429 with a generic message).
- `isSameOrigin` fails closed on a missing URL, userinfo, a protocol-relative URL and `javascript:`, and is tested. The header is only attached for same-origin requests.
- The new dialogs use Wayfinder imports, `type=password`, correct `autocomplete`, and `dontRemember`.
- The `dontFlash` list covers the password fields.

**Folded follow-ups.** 7B-QA-01 (throttle), 7B-QA-02 (same-origin), 7B-QA-04 (`ExampleTest` newline) and 7A-QA-05 (mirror `created_at` on every sync) are all fixed and tested. 7A-QA-01 and 7A-QA-06 are recorded as accepted risks in the ADRs, which is acceptable.

### The 12 deviations in implementation §8.5
- Deviations 1, 3, 4, 5, 6, 7, 8, 9, 10, 11 and 12 are accepted. They are sound, tested, and consistent with the ADRs.
- Deviation 2 (the cache lock) is accepted in principle, but its implementation is flawed (7C-QA-01).
- Deviation 6: the extra case-only duplicate and name-length refusals only block conversion with a clear list. This is fine.
- Deviation 10: the missing sidebar password action is not a defect, since FR-12 is met on the vault card.

### Master Plan §56 acceptance criteria
- [x] Encrypted vault contents are not plaintext on disk. Verified by `EncryptionSecurityTest`, which also covers the encrypt-existing path and confirms the original is deleted.
- [x] Filenames are not exposed in plaintext. Verified: hex names only, plus a regex assertion on every file.
- [x] An incorrect password cannot unlock the vault. Seven variants are tested, and the endpoint is throttled.
- [x] The correct password unlocks the vault.
- [x] Locking removes access to the protected content. Five lock paths all give 423, with no tree or note in the workspace props.
- [x] Backup and restore work with encrypted vaults. Format 2 is tested for backup, restore as the same vault, restore as a copy, restore after a DB reset, and format 1 compatibility.
- [x] No passwords or keys appear in logs. The log, exception, session, SQLite and Inertia scans pass.
- [x] Security testing is done: `EncryptionSecurityTest`, 20 tests.
- Caveat: FR-16 (crash recovery) behaves correctly in tests, but it is delayed in the real database-cache setup (7C-QA-01).
- All other FRs (FR-01 to FR-15, FR-17, FR-18) are covered by tests and code. The user's manual desktop checks in implementation §8.7 are not automatable here.

### Issues
| ID | Severity | Class | Fix attempts | Description | Recommendation |
|---|---|---|---|---|---|
| 7C-QA-01 | Medium | MODERATE | 0 | `VaultRecoveryService::lock()` uses a 1800 s cache lock held for the whole conversion. With `CACHE_STORE=database` the lock row survives a crash. After a kill, `recoverAll()` and `open()` do nothing for up to 30 minutes, so the vault stays Missing or interrupted and a retried encrypt gets a generic failure. Tests use the `array` store and never exercise this. If the lock is cleared (cache clear or TTL) mid-conversion, recovery can roll back a swapped but uncommitted state while the conversion then commits `is_encrypted=true` over a plaintext folder. No file is lost, but the registry and folder disagree. | In `recoverAll()` at boot, force-release stale conversion locks, since no conversion can be in flight in a freshly started single-instance app. Optionally record an owner or heartbeat, and re-check inside `commit()` that the lock is still held. Add a test with a persisted lock. |
| 7C-QA-02 | Low | MINOR | 0 | The `commit()` branch where the DB commit fails after the swap (`$swapped=true`, `undoSwap()`), and the case where `undoSwap` itself fails, have no test. The logic reads correctly. | Add a test, for example a deferred FK or a `Connection` commit failure. |
| 7C-QA-03 | Low | MINOR | 0 | Static finding, not executed: in a format 2 manifest for a plain vault, `files[]` may list `mdvault-encryption.json`. `isEncryptedVaultSupportFile` only applies when the vault is flagged encrypted, and `relativePathProblem` allows the name. Restore would then produce a plain vault holding a key file (the inconsistent state). | In validation, reject `mdvault-encryption.json` (and `.mdenc` files) for non-encrypted vaults, and add a dataset. |
| 7C-QA-04 | Low | MINOR | 0 | Decrypt and change-password verify the current password with an Argon2 run each and have no throttle (only unlock does). Same limited local exposure as 7B-QA-01. | Add `throttle` to both routes, or record it as an accepted risk. |
| 7C-QA-05 | Low | MINOR | 0 | A note save or other write that lands after the final verify and before the first rename is lost with the replaced original. Nothing outside the cache lock blocks note writes during a conversion. The window is milliseconds, and any write during the build correctly aborts the conversion. | Re-run `snapshot()` compare immediately before the first rename. Optionally have `NoteService` refuse writes while the conversion lock is held. |
| 7C-QA-06 | Low | MINOR | 0 | Static finding, not executed: `validateArchive` keys entries by name (last duplicate wins) while `readEntry` and `getFromName` read the first duplicate. Duplicate ZIP entry names are not explicitly rejected or tested. Hashes are re-verified at staging, so I found no exploit, but it is a gap in the format 2 hostile-manifest coverage. | Reject duplicate entry names in `ArchiveService::entries` or `validateArchive`, and add a test. |

### Routing
- Developer (MODERATE): 7C-QA-01. Fix before release; it does not block this PASS.
- Developer (MINOR, optional or deferrable): 7C-QA-02, 7C-QA-03, 7C-QA-04, 7C-QA-05, 7C-QA-06.
- System Analyst: none. ESCALATE: none; no issue has reached 3 fix attempts.
- Orchestrator:
  - Level 4 requires the analyst's final sign-off before the feature folder moves to `.ai/features/completed/`. Include 7C-QA-01 in that review.
  - Ask the user to run `php artisan test --compact` and `php artisan migrate`.
  - Ask the user to do the manual desktop checks in implementation §8.7, especially item 12 (kill during encrypt, then restart), which depends on 7C-QA-01.
  - If `npm run build` is run later, restore `public/fonts-manifest.dev.json` with `git checkout`.

---

## Sub-phase 7C — Round 2

**Verdict: PASS.** There are zero open Critical, High or Moderate issues. Three Low observations are listed as follow-ups.

### Verification

| Check | Result |
|---|---|
| `php artisan test --compact` (full) | Pass: 1079 tests, 1066 passed, 13 skipped, 0 failed |
| `php vendor/bin/phpstan analyse` | Pass: 0 errors |
| `php vendor/bin/pint --test` | Pass |
| `npm run types:check` | Pass |
| `npm run test:js` | Pass: 156 tests in 13 files |
| `EncryptionSecurityTest` (7A/7B guarantees) | Passes as part of the full run, so no regression |

### Per-issue status

| ID | Round 1 issue | Fix attempts | Status |
|---|---|---|---|
| 7C-QA-01 | Conversion lock lost or stale | 1 | Closed (see notes) |
| 7C-QA-02 | Round 1 issue, carried over | 1 | Closed (full suite green, no regression found) |
| 7C-QA-03 | Round 1 issue, carried over | 1 | Closed (full suite green, no regression found) |
| 7C-QA-04 | Missing throttle on decrypt and change-password | 1 | Closed (Low note below) |
| 7C-QA-05 | Write landing after the final verify | 1 | Closed (Low note below) |
| 7C-QA-06 | Duplicate ZIP entry names | 1 | Closed (Low note below) |

I re-read the code and tests for QA-01, 04, 05 and 06. For QA-02 and QA-03 I only confirmed that the full suite passes. I did not re-read their code.

**7C-QA-01 notes**
- **Owner-only release:** `ConversionLock` wraps a Laravel cache `Lock`, which carries an owner token. `release()` frees the lock only for its owner. `isHeld()` uses `isOwnedByCurrentProcess()` and returns false once the lock has expired or been cleared.
- **Commit guard:** `assertSafeToSwap` runs just before the first rename. It throws `conversionFailed` when the lock is lost.
- **Guard test:** `a conversion that lost its lock aborts…` force-releases the lock after the DB writes and before the swap. It asserts the exception, an unchanged tree, `is_encrypted` false, no `VaultEncryption` row, no encrypted notes and no leftover folders.
- **Persisted store:** the test sets `cache.default` to `database`, so the lock is really persisted.
- **Boot release:** `recoverAll()` calls `forceRelease()` on every vault lock. It runs only from `NativeAppServiceProvider::boot`, once per app launch. The in-flight conversion runs inside the same single PHP process, and that process has just started, so none can be in flight. A second window shares that process and does not re-boot it. This is safe under the single-instance assumption; see Low observation L-1.

**7C-QA-04 notes.** `throttle:10,1` is now on unlock, decrypt and change-password. The anonymous middleware key is `sha1(user id)`, or `sha1(domain|ip)` when there is no user. It does not include the route. All three routes, and any other plain-throttle route, therefore share one bucket of 10 per minute. For a single-user local desktop app this is acceptable and slightly stricter than needed. See L-2.

**7C-QA-05 notes.** The snapshot compare now runs together with the lock check immediately before the first rename. The test with a file written late asserts the conversion aborts and the late file survives. The window left between the guard and the first `renameDirectory` is a few microseconds to milliseconds. It needs a concurrent external writer and could lose one note. This is Low, not Moderate, and the developer already declared it.

**7C-QA-06 notes.** The test asserts refusal only, not the message. That is acceptable. libzip `CHECKCONS` already refuses such archives, so the explicit check is defence in depth, and the refusal itself is the security property.

### New issues

None open. Low follow-ups, non-blocking:

| ID | Severity | Note |
|---|---|---|
| 7C-QA-07 | Low | **L-1.** `recoverAll()` force-releases locks on the premise that the app is single-instance. If NativePHP ever re-runs `boot()` mid-session, or a second app instance starts, a real in-flight conversion could lose its lock. The commit guard then aborts it cleanly, as the test shows. Consider recording this assumption in the ADR. |
| 7C-QA-08 | Low | **L-2.** The throttle bucket is shared across routes and keyed by user or IP. A named limiter such as `throttle:vault-password`, keyed by vault uuid, would be stricter and clearer. |
| 7C-QA-09 | Low | **L-3.** The residual guard-to-rename window described under 7C-QA-05, plus the unasserted duplicate-name message under 7C-QA-06. |

### Routing

Nothing is routed to the Developer or the System Analyst. No issue has reached 3 fix attempts. The Low items are optional follow-ups. The orchestrator can mark sub-phase 7C complete.

---

## Level 4 Analyst Sign-off

**Verdict: APPROVED WITH CONDITIONS.** I found no release blocker in the code. The conditions are three ADR text updates (supplied with this sign-off) and the pre-release checks listed below.

### Scope of review
- Read: requirements, plan Rev 1, implementation (7A §4, 7B §7.4, 7C §8.5, 7C Fix Round 1), every QA round, the five Phase 7 ADRs and the amended older ADRs.
- Spot-checked by hand:
  - `EncryptionService`, `VaultKey`, `VaultKeyService`;
  - `VaultConversionService` (`encrypt`, `decrypt`, `commit`, `assertSafeToSwap`, `undoSwap`), `VaultRecoveryService`, `ConversionLock`;
  - `BackupService::validateArchive`, `encryptionKeyId`, `readArchivedKeyFile`, `isEncryptionArtifact`;
  - `resources/js/lib/vault/keyring.ts`, `routes/vaults.php`, `NativeAppServiceProvider`.

### Conformance to the ADRs
- **Cryptography: conforms.**
  - Primitives: Argon2id13 with a 16-byte salt; XChaCha20-Poly1305-IETF with a fresh 24-byte nonce on every call; BLAKE2b subkeys (1/`MDVNOTE1`, 2/`MDVFOLD1`).
  - Associated data: the key-wrap, note, folder and session AD layouts match the ADR byte for byte.
  - Header parsing: `parseHeader` checks exact keys and types, uses strict base64, caps the input at 64 KiB and enforces the KDF bounds. `unlock` and `rewrap` check the bounds before the KDF runs.
  - Errors: wrong password, tampered parameters and a tampered wrapped key all give the same generic error. `SodiumException` is never chained.
  - Secrets: the KEK and subkeys are wiped in `finally` blocks. The data key is never used directly as a content key.
  - The creation-cost floor (INTERACTIVE unless `allow_weak_kdf`) is correct.
- **Key custody: conforms.**
  - The session holds only the sealed entry. The epoch, idle limit and mirror `key_id` checks purge the entry. A missing or wrong token only returns null, which is intended and was judged sound in 7A.
  - A negative auto-lock value is clamped to the default. The memo and tokens are cleared on `provideTokens` and when the request terminates.
  - The renderer keeps tokens in a module `Map` only, and sends the header only to same-origin URLs (fails closed).
- **Storage layout: conforms.**
  - Opaque names; the disk key file is authoritative and the mirror is non-secret; registration detects the key file.
  - No plaintext names in SQLite (T17 scans every row).
- **Conversion and recovery: conforms.**
  - DB writes happen first, then the guard (lock still owned and the folder snapshot unchanged), then both renames as the last statements of the transaction. A failed second rename puts the original back.
  - `undoSwap` runs on a commit failure after the swap. `deleteOriginal` runs only after `commit()` returns.
  - The recovery table matches the ADR. `ConversionLock::isHeld()` relies on `DatabaseLock::getCurrentOwner()`, which also checks expiry, so a lost or expired lock fails safe.
  - The boot force-release runs inside the Electron "booted" request. NativePHP serves with a single-worker `php -S` (no `PHP_CLI_SERVER_WORKERS`), so no other request can run while it does.
- **Backups (format 2): conforms.**
  - Validation: exact `encryption` object; the key file must be listed, match its size and hash, parse, and match the manifest `key_id`; only hex folders, `<32hex>.mdenc` notes, `folder.mdenc` and the key file are allowed.
  - Refused: encryption artifacts in plain vaults (7C-QA-03), duplicate ZIP entry names (7C-QA-06), and format 1 archives claiming encryption.
  - Format 1 plain archives keep working, because they always wrote `encryption: null`.
- **Non-negotiables 1–15:** all hold. No `notes.content` column, no new tables beyond `vault_encryption`, all filesystem work goes through services (arch-enforced), settings go through `SettingsService`, and no secrets reach logs, session, props or flash (T17).

### Accumulated deviations
All are accepted. None changes a decision. Some need ADR text so the records match the code:

| Source | Deviation | Ruling |
|---|---|---|
| 7A §4 | Migration date, schema-guard retarget, `encryptedVault()` helper, extra exception factories, empty password rejected before the KDF, nested session key, mirror `created_at`, `?string` material | Accept. No ADR change. |
| 7B §7.4 #1, 7C §8.5 #1 | `EncryptionHeaderService` and `VaultRecoveryService` were split out to break dependency cycles; the original APIs delegate | Accept. Recorded in the `service-layer-architecture` amendment. |
| 7B §7.4 #4 | `VaultKeyService` and `EncryptedNoteService` are `scoped` | Accept. Request state, as convention 4 intends. Recorded in the amendment. |
| 7B §7.4 #8 | A `.mdenc` outside the opaque layout is ignored | Accept. A warning is deferred (7B-QA-03). |
| 7B §7.4 #2, #3, #5, #6, #7, #9–#14 | Single registration transaction, generic factories, helpers, route reuse, prune grace, etc. | Accept. |
| 7C §8.5 #2 and Fix Round 1 | Owner-checked conversion lock, boot force-release, guard before the swap | Accept. The ADR's implementation notes already cover this; the updated ADR adds the single-instance assumption and the TTL limit. |
| 7C §8.5 #3–#7, #12 | `ConversionResult`, Full reconcile plus folder snapshot, decrypt refuses foreign files, stricter encrypt preflight (name rules, case-only duplicates, disk space), `problem_<n>` keys, `set_time_limit(0)` | Accept. They are stricter than the ADR. The preflight additions go into the ADR. |
| 7C §8.5 #8, #9 | Backups copy only opaque entries; a damaged key file fails a single-vault backup and skips the vault in a full backup | Accept. Added to the backups ADR. |
| 7C §8.5 #10, #11 | Dialogs on the vault card only; constructor order | Accept. |
| Fix Round 1 (7C-QA-04) | `throttle:10,1` also on decrypt and change-password (one shared bucket) | Accept. Recorded in the conversion ADR. |

### Required ADR updates (condition 1)
Applied by the orchestrator on 2026-10-03:
- `.ai/decisions/vault-encryption-conversion.md`
- `.ai/decisions/encrypted-vault-backups.md`
- `.ai/decisions/service-layer-architecture.md` (amendment extended)

No other ADR needs editing. The Follow-ups in `vault-encryption-cryptography` and `encrypted-vault-key-custody` already record 7A-QA-01 and 7A-QA-06 as accepted risks.

### Deferred follow-ups (all Low; deferral accepted; none blocks release)
| ID | Item | Disposition |
|---|---|---|
| 7A-QA-01 | `VaultKey` material is visible to `var_export`, an `(array)` cast or reflection | Accepted risk. Already in the cryptography ADR. |
| 7A-QA-06 | Lock epoch lives in the cache (`cache:clear` resets it) | Accepted risk. Already in the key-custody ADR. A persistent epoch is v1.x. |
| 7B-QA-03 | Full scan for unencrypted files on every Workspace render; no warning for a foreign `.mdenc` | Defer to the performance and name-cache follow-up. |
| 7C-QA-07 | Boot force-release assumes a single instance | Accept. Now recorded in the conversion ADR. The commit guard aborts safely if the assumption is ever broken. |
| 7C-QA-08 | Shared throttle bucket | Defer. A named limiter keyed by vault UUID is optional hardening. |
| 7C-QA-09 | Residual window between the guard and the rename; duplicate-name message not asserted | Accept. Needs a concurrent external writer at millisecond precision. |
| AR-01 (new) | `LOCK_SECONDS = 1800`: a conversion that runs past 30 minutes loses its lock and is aborted by the guard. This fails safe (nothing changes) but means very large vaults cannot be converted. | Defer. Refresh the lock during the build, or scale the TTL with vault size. |
| AR-02 (new) | Theoretical: SQLite reports the commit as failed but it actually persisted. `undoSwap` then restores the original while the DB says converted, and recovery deletes the converted staging copy. No note is lost (the original plus its key file, or its plaintext, survives), but the registry is inconsistent until the user removes and re-adds the vault. | Accept. Very unlikely on SQLite. |
| AR-03 (new) | `sealForSession` does not wipe `tokenKey` when it throws early on an invalid UUID | Trivial. Fold into any later touch of the file. |

### Release blockers
None.

### Conditions before merge or release (user and orchestrator)
1. Save the three ADR updates. (Done.)
2. Restore `public/fonts-manifest.dev.json` (`git checkout public/fonts-manifest.dev.json`). Commit only on `phase-7-encryption`, with no AI attribution.
3. Run `php artisan test --compact`, `php artisan migrate` (the `backups` and `vault_encryption` migrations are pending on the dev DB), and `php artisan route:clear` if routes were ever cached.
4. Manual desktop checks (implementation §7.6 and §8.7). These must pass in the **packaged** app:
   - §8.7 #2: unlock at the default MODERATE cost takes about 1 s with no out-of-memory error. Argon2 allocates 256 MiB outside PHP's `memory_limit`.
   - §8.7 #3 and #4: Win+L locks; Ctrl+R relocks.
   - §7.6 #7: idle auto-lock after 5 minutes.
   - §8.7 #6: encrypt with the folder held open by another program gives the clean refusal and leaves no `.mdvault-*` folder.
   - §8.7 #11: back up a locked encrypted vault, then restore it (as the same vault and as a copy, after a DB reset). It comes back locked and opens with the password.
   - §8.7 #12: kill the app mid-encrypt, then restart. The vault is intact and no `.mdvault-*` folder is left on the next start. 7C-QA-01 was fixed, so this must now pass immediately and not only after 30 minutes.
   - §8.7 #13 and §7.6 #10: `storage/logs/laravel.log` contains no note names or note text.
5. Carried to Phase 8 (already in the ADRs): ship with `APP_DEBUG=false`.

After conditions 1–3, the orchestrator may move `.ai/features/active/phase-7-encryption/` to `.ai/features/completed/`. Condition 4 is the user's release gate.
