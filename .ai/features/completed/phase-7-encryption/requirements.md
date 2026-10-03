# Requirements: Phase 7: Encryption

## Metadata
- **Feature Name**: Phase 7: Encryption
- **Feature ID**: mdv-p7
- **Master Plan Phase**: Implements **Phase 7, Encryption** (`docs/Masterplan.md` §56). Related sections: §9, §11–§18, §24, §25–§34, §37–§44, §62, §63 (items 19–21), §67; Rules 1–10.
- **Author**: System Analyst
- **Created Date**: 2026-10-03
- **Task Complexity**: Level 4, Architectural
- **Status**: APPROVED (E1–E12 approved by the user, 2026-10-03).

---

## 1. Problem Statement
MDVault vaults are unencrypted Markdown on disk. Users with sensitive notes (credentials, health, finance) need a vault whose contents and names can't be read by anyone who gets the folder, a backup or the app's database while the vault is locked or the app is closed.

The Master Plan makes this a v1 deliverable (§56, §63.19–21). The registry, indexer, editor, external-change detection and backups (Phases 3–6) are all built around readable paths, so encryption cuts across every subsystem.

## 2. Goals & Non-Goals
### In Scope (Goals)
- Create a new encrypted vault.
- Encrypt an existing vault (E7) and remove encryption (E8).
- Encrypted note contents, encrypted note and folder names (opaque IDs on disk), and encryption metadata (`vault_encryption` plus the on-disk key file).
- Unlock with a password (wrong passwords fail, correct ones unlock), lock, lock all, auto-lock (idle and OS screen lock), and locking on close.
- Change password without re-encrypting notes.
- Every existing note and folder operation and the editor working in unlocked encrypted vaults, with the same safety semantics.
- Indexing, re-index and external-change detection on encrypted vaults (no key needed).
- Backup and restore of encrypted vaults (format 2).
- Re-registering an encrypted folder after a DB reset or on another machine.
- Crash-safe conversion with deterministic recovery.
- Secret hygiene and mandatory security tests: no plaintext on disk, and no secrets in logs, exceptions, session, SQLite, Inertia props or the HTTP cache.

### Out of Scope (Non-Goals)
- Search over encrypted notes (search isn't implemented yet).
- Attachments or non-`.md` files in encrypted vaults.
- A recovery key or password hint.
- Importing stray unencrypted files.
- Padding to hide sizes.
- Password-protected backup archives.
- Encrypting the vault name.
- Folder rename.
- Hardware keys or the OS keychain.
- A native file watcher.
- App-level login (ADR `local-app-without-authentication`).
- Any sync, sharing or device features.

## 3. User Personas & Stories
- **As a** user with sensitive notes, **I want to** create an encrypted vault with a password, **so that** its notes and names are unreadable on disk.
- **As a** user with an existing vault, **I want to** encrypt it in place, **so that** I don't have to move notes by hand.
- **As a** user, **I want to** unlock with my password and lock again (manually, when idle or when my screen locks), **so that** an unattended computer doesn't expose my notes.
- **As a** user, **I want to** change my password, and back up and restore encrypted vaults, **so that** I keep control and resilience.
- **As a** user who loses the app database, **I want to** re-add my encrypted folder and unlock it, **so that** my data isn't tied to SQLite.

## 4. Functional Requirements

| ID | Requirement | Description | Acceptance Criteria |
|---|---|---|---|
| **FR-01** | Create encrypted vault | The Create vault dialog has an "Encrypt this vault" option: password (min 10), confirmation and a data-loss acknowledgment. JSON endpoint. The vault ends up unlocked. | Given valid input, When created, Then the folder contains only `mdvault-encryption.json`, `vault_encryption` and `is_encrypted` are set, the response holds a token, and the Workspace shows the empty unlocked vault. Short or mismatched passwords give 422, with nothing created. |
| **FR-02** | Encrypt existing vault | Preflight, stage, verify, swap, then delete the original (ADR `vault-encryption-conversion`). | Given a vault with notes and folders, When encrypted, Then every note keeps its UUID and content (after unlock), no `.md` file or original name remains under the vault or its parent, and the unencrypted original is gone. Given unsupported entries, Then it is refused with a list and nothing changes. Given an injected failure at any step, Then the vault is exactly as before. |
| **FR-03** | Encrypted names | Notes are `<32hex>.mdenc`; folders are `<32hex>/` with `folder.mdenc`. | No file or folder name under an encrypted vault contains any note or folder name. Only the readable names allowed by E4 appear in SQLite. |
| **FR-04** | Encrypted contents | Notes use the `MDVN` v1 AEAD format. | Note bytes on disk never contain the plaintext. Tampering, truncation or swapping files is detected as unreadable, never a crash. |
| **FR-05** | Encryption metadata | `vault_encryption` mirror plus the authoritative on-disk key file, kept in sync. | The key file contains no secrets. A missing key file is restored from the mirror after a successful unlock. A changed key file updates the mirror. |
| **FR-06** | Unlock and password validation | `POST /vaults/{vault}/unlock` (JSON). | A wrong password gives 422 with a generic message and no session entry. The correct password gives 200 `{token}` and a session entry. A key file with out-of-bounds KDF parameters is rejected without running the KDF. |
| **FR-07** | Lock | Lock one vault, lock all, lock on close. | After locking, requests carrying the old token behave as locked (423 JSON, or the Workspace locked state), and the Workspace props contain no tree, folder or note content. |
| **FR-08** | Auto-lock and Security settings | Settings → Security: auto-lock minutes (0, 5, 15, 30, 60) and lock on screen lock. Client idle lock plus server backstop plus screen-lock epoch. Reload or restart locks. | Given idle longer than minutes + 2 (server clock), Then locked. Given `ScreenLocked` with the setting on, Then all vaults are locked. Given no token header, Then locked. |
| **FR-09** | Encrypted note and folder operations | Create, open, edit and save (Rich and Source), rename, move, delete (to trash), create and delete folder, "save mine as new note", disk compare. Logical paths in the UI, opaque paths on disk. Requires the key. | Each operation works as it does in plaintext (conflict, no-overwrite and compensation semantics) and leaves only ciphertext on disk. Locked gives 423. |
| **FR-10** | Workspace and sidebar | A locked vault shows an unlock panel. An unlocked vault shows the tree with decrypted names. Lock icon and Lock action. Readable-vault-name hint. Warnings for unencrypted or unreadable files and inconsistent state. | The unlock panel renders when locked. The tree renders decrypted names when unlocked. Lock returns to the panel. |
| **FR-11** | Indexing and external changes | Quick and Full reconcile, change checks and the open-note check work without the key. Unencrypted `.md` files in an encrypted vault are not indexed and are reported. | External delete, move, copy or modify of `.mdenc` files is detected while locked. A plaintext `.md` produces a warning, not a registry row. |
| **FR-12** | Change password | Current password plus new password (confirmed). Re-wraps the key; notes are untouched. | The old password then fails and the new one works. Note hashes are unchanged. The vault stays unlocked. |
| **FR-13** | Remove encryption (E8) | Password required. Mirrors FR-02. | Notes are readable `.md` again under their logical names with the same UUIDs, the key file and mirror are gone, and no `.mdenc` remains. |
| **FR-14** | Backup and restore | Format 2 (ADR `encrypted-vault-backups`). | The backup ZIP (entries and manifest) contains no plaintext from encrypted vaults. Restore produces a locked vault, the original password unlocks it, and contents match. Restore as a copy also works. |
| **FR-15** | Registration and reset recovery | "Add existing folder" detects the key file. | After a DB reset and re-add, the vault is encrypted and locked, and the password unlocks it. A damaged key file is refused. |
| **FR-16** | Crash recovery | Recovery table in ADR `vault-encryption-conversion`. | Each simulated crash state resolves to the documented end state on boot, open or refresh. |
| **FR-17** | Secret hygiene | `no-store` headers, history encryption and clearing, `dontFlash`, generic messages, `#[\SensitiveParameter]`, redacted `VaultKey`, logging bans. | The security suite (plan T17) passes. |
| **FR-18** | ADRs and enforcement | New ADRs saved, amendments applied, arch rules added. | Arch tests pass; ADR statuses are updated at sign-off. |

## 5. Non-Functional Requirements
- **Security**:
  - Threat model and primitives as in the ADRs.
  - No plaintext password, data key or token is ever persisted.
  - No passwords, keys, tokens, decrypted names or content appear in logs, exception messages or traces, session data, SQLite or the HTTP cache.
  - Decrypted data reaches the renderer only for unlocked vaults and is never persisted there.
  - No app-level login (ADR `local-app-without-authentication`).
- **Performance**:
  - Unlock takes about 1 s (Argon2id MODERATE).
  - Tree and operations use O(notes) decryptions, aiming for under 300 ms at 1,000 notes of 5 KB.
  - Change checks need no decryption.
- **Accessibility and UX**:
  - Password inputs are `type=password` with `autocomplete="current-password"` or `"new-password"`.
  - Labelled errors; a single root element per component.
  - Clear warnings: lost password, readable vault name, permanent deletion of the original, old backups keep the old password.
- **Reliability and data integrity**:
  - Conversions are all-or-nothing, with deterministic crash recovery.
  - Every file write uses the existing no-overwrite primitives with hash guards.
  - Note UUIDs are preserved.

## 6. Technical Constraints & Context
- Laravel 13 / PHP 8.4. NativePHP desktop 2.3.1 (`php -S`, file sessions).
- Inertia v3 + Vue 3 + Tailwind v4 + shadcn-vue.
- Wayfinder; Pest 5; Pint; SQLite metadata only.
- `ext-sodium` (bundled in `nativephp/php-bin` for all OSes).
- No new composer or npm packages.

### Pre-implementation checklist (§67)
1. **In v1?** Yes, §56 and §63.19–21.
2. **Markdown stays the source of truth?** Yes: the files on disk (ciphertext) remain the only content store.
3. **Filesystem stays portable?** Yes: the key file travels with the vault, and paths are relative.
4. **Logic in services?** Yes, all of it: `EncryptionService`, `VaultKeyService`, `VaultEncryptionService`, `EncryptedNoteService`, `VaultConversionService`.
5. **Migration needed?** Yes: `vault_encryption`.
6. **Premature sync complexity?** None.
7. **External edits?** Detected on ciphertext without the key. Foreign or unencrypted files are reported.
8. **Offline?** Fully offline.
9. **DB/filesystem mismatch?** Disk-authoritative key file, the staging-and-swap protocol, and the recovery table.
10. **Testing?** Plan §4, including the mandatory security suite.

## 7. Risks & Assumptions
- **Assumption**: the threat model is at-rest (ADR `vault-encryption-cryptography`).
- **Assumption**: `ext-sodium` is available in Herd/CI (checked in T0).
- **Risk**: a forgotten password means permanent loss. **Mitigation**: acknowledgment checkbox, repeated warnings, backups.
- **Risk**: regressions in Phases 3–6. **Mitigation**: encrypted branches delegate at method entry; the full suite runs at every QA gate.
- See plan §5 for the rest.

## 8. Requirements Approval
- [x] Requirements fully defined
- [x] Edge cases identified
- [x] Approved to proceed to Planning (`plan.md`). Pending E1–E12.
