# ADR: "Reset database" is a vault-registry reset, not `migrate:fresh`

- **Status**: Accepted (2026-09-30, D1–D6 approved as recommended)
- **Date**: 2026-09-30
- **Phase**: Master Plan Phase 6, Backup and Restore (§55 "Remove local data → Fresh application state → Import backup"); §38/§39 Rebuild Index; §41; Rules 1, 2, 4, 9, 10
- **Amends**: `vault-removal-and-rename-semantics` (bulk unregister), `backup-restore-semantics` (documented path for registered-but-missing vaults)

## Context
- Users can delete vault folders outside MDVault (Rule 8). The vaults stay registered, with status Missing.
- Restore never replaces a registered vault UUID and never reuses a registered name (ADR `backup-restore-semantics`). So a backup of vaults that are registered but missing is skipped by default, refused with `restore`, or turned into "(restored)" copies.
- The user asked for a "reset database / migrate:fresh" so a backup can be restored fresh.
- The DB holds more than the vault index:
  - `settings`: storage root, folder name, theme, editor;
  - `backups`: the history the Restore UI lists;
  - `sessions` and `cache`: `SESSION_DRIVER=database` and `CACHE_STORE=database`;
  - `migrations`.
- NativePHP uses `nativephp.sqlite` and runs pending migrations on startup. A packaged app needs `--force` for any destructive migration command.
- A per-vault unregister exists (`VaultService::remove`), but nothing works in bulk.

## Options Considered
1. **`Artisan::call('migrate:fresh', ['--force' => true])` from a request.** It drops `sessions` and `cache` mid-request (the next request fails with 419 or a missing-table error). It loses the storage root and folder name, so the following restore lands in a different folder. It also loses theme and editor preferences and backup history. It runs a schema operation from HTTP and can't be tested cleanly in the in-memory test suite. **Rejected.**
2. **Truncate every app table except `migrations`.** Same loss of settings and history, and it still clears sessions and cache. **Rejected.**
3. **Loop `VaultService::remove()` over every vault.** N transactions (not all-or-nothing) and N status-refresh writes. **Rejected.**
4. **Bulk "remove missing vaults only".** Safe and narrow, but not what the user asked for when active vaults also exist. **Offered as D2.**
5. **`VaultService::resetRegistry()`: one transaction that deletes all note rows, then all vault rows, and forgets `app.current_vault`. No filesystem access. Settings and backup history kept. (Chosen, subject to D1–D4.)**

## Decision
- "Reset database" means **reset the vault registry**. `notes` and `vaults` are emptied, and `app.current_vault` is forgotten through `SettingsService`, all in one DB transaction. Notes are deleted explicitly before vaults, so correctness doesn't depend on the FK cascade.
- It **never** touches the filesystem: no vault folders, notes, other files, storage root or staging folders. Because nothing on disk changes, no compensation is needed (Rule 10).
- It never touches `settings` (other than the current vault), `backups`, `sessions`, `cache`, `jobs` or `migrations`. It never runs schema operations.
- It is exposed only as `DELETE /settings/backup/database`, with a Form Request requiring `confirmation === 'RESET'` (case-sensitive) and a dialog that requires typing it.
- It is idempotent: an empty registry returns zeros.
- **Recovery story**: files are untouched. A reset is undone by "Add existing folder" (new UUIDs) or by restoring a backup (original UUIDs, §12, §55). Developers keep `php artisan native:migrate:fresh` for a true schema reset; it is not a product feature.

## Consequences
- **Positive**:
  - One click unblocks a fresh restore that keeps the original names and UUIDs.
  - Nothing on disk can be lost.
  - The user's storage root and preferences survive, so the restore lands where they expect.
  - Backup history stays available for one-click restore.
  - Atomic and fully testable.
- **Negative / trade-offs**:
  - Resetting while vaults are active loses their registry UUIDs (their files are kept). Re-adding a folder gives new UUIDs.
  - If folders are still on disk, restoring them produces "(restored)" copies, because restore never merges.
  - Removing the registry also drops `file_mtime` and `file_hash` baselines. These are rebuilt by reconcile on restore or re-add.
- **Follow-ups**:
  - **Phase 7 (blocking for encryption):** if `vault_encryption` (salt, wrapped key) lives only in SQLite, then both this reset and `VaultService::remove()` would make an encrypted vault's files permanently undecryptable. Phase 7 must choose one of:
    - (a) keep recoverable key metadata alongside the vault on disk;
    - (b) refuse or require a separate confirmation for encrypted vaults;
    - (c) require a backup first.

    `vault_encryption` must reference `vaults` with an explicit delete policy, and `resetRegistry()` must be updated to match.
  - Optional later: a "Back up, then reset" combined action.

## Amendment (Phase 7, 2026-10-03)
- Follow-up resolved with option (a): the key file lives in the vault folder (ADR `encrypted-vault-storage-layout`); `resetRegistry()` deletes `vault_encryption` rows first.
