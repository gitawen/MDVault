# ADR: Custody of unlocked keys, lock and auto-lock

- **Status**: Accepted (E5 and E6 approved 2026-10-03)
- **Date**: 2026-10-03
- **Phase**: Master Plan Phase 7 (§33, §34, §42, §44, §56 "Locking removes access", "No passwords or keys appear in logs"; Rule 6)

## Context
- NativePHP serves Laravel with `php -S`. It is single-threaded, every request starts fresh, there is no APCu, and no PHP memory survives between requests.
- NativePHP forces `session.driver=file`. Browser development uses the `database` driver and tests use `array`.
- Laravel puts flash data, validation errors and old input into the session. That means they reach disk.
- The renderer is Chromium (Electron):
  - Inertia keeps page props in `history.state`;
  - Chromium's HTTP disk cache may store GET responses.
- Inertia v3 `http.onRequest` interceptors apply to every Inertia request (router, `useForm`, `<Form>`, `useHttp`).
- `useUnsavedChangesGuard` already flushes the editor before any router visit.
- NativePHP sends `Native\Desktop\Events\PowerMonitor\ScreenLocked` to Laravel when the OS screen locks. It has no suspend event.
- §34 forbids exposing keys to Vue unnecessarily and keeping decrypted content longer than needed.

## Options Considered
1. **Data key in the session or cache, plain or encrypted with `APP_KEY`.** The key stays on disk while unlocked and survives crashes, and `APP_KEY` ships inside the build. Rejected.
2. **Data key held by the renderer and sent with each request.** That hands the actual key to Vue, and the server cannot enforce a lock. Rejected.
3. **The renderer keeps the password and the server re-derives the key on every request.** That costs about 1 s of Argon2 per request and keeps the password in JS memory. Rejected.
4. **OS keychain.** NativePHP has no API for it, and the key would persist. Rejected.
5. **Split custody (chosen, E5).** The renderer's memory holds a random per-unlock token. The session holds the data key sealed (encrypted) under that token.

## Decision
- **Unlock** (`POST /vaults/{vault}/unlock`, JSON only, so nothing is flashed):
  1. Verify the password (Argon2id, then the AEAD unwrap).
  2. `token = random 32 bytes`, base64url without padding.
  3. Store the session entry `mdvault.keyring.<vaultUuid>` = `{key_id, nonce, sealed, unlocked_at, last_used_at, epoch}`. Here `sealed = AEAD_token(DEK, AD=MDVS|vault_uuid|key_id|epoch)`.
  4. The response is `{ "token": "<token>" }` only.
- **The renderer** keeps tokens in a module-level `Map<vaultUuid, token>` (`resources/js/lib/vault/keyring.ts`):
  - never in `localStorage`, `sessionStorage`, cookies, Inertia props, history state or remember-state;
  - one interceptor adds `X-MDVault-Unlock: <uuid>:<token>[,<uuid>:<token>…]` (at most 20 pairs) to every Inertia request.
- **On each request**:
  - Middleware `ProvideVaultKeys` hands the header's raw string to `VaultKeyService::provideTokens()`. Services never see `Illuminate\Http`.
  - `VaultKeyService::keyFor($vault, touch)` opens the sealed key on demand and checks these, purging the entry (= locked) if any fails:
    - the epoch;
    - the idle limit;
    - that `key_id` still matches `vault_encryption`.
  - Request-scoped `VaultKey` objects are wiped when the request ends (`app()->terminating`).
  - A missing or invalid key on a key-requiring operation throws `VaultLockedException`:
    - HTTP 423 JSON `{message, reason:'locked'}` for JSON requests;
    - otherwise a redirect to the Workspace with a generic toast.
- **Lock** (`POST /vaults/{vault}/lock`, an Inertia visit, so the unsaved-changes guard flushes saves first): delete the session entry, `Inertia::clearHistory()`, redirect to the Workspace. The client drops the token once the lock succeeds.
- **Lock all** (`POST /vaults/lock`): same as Lock, for every vault.
- **Closing an encrypted vault locks it.** Switching to another vault does not; idle auto-lock covers it.
- **Auto-lock (E6)**:
  - Setting `security.auto_lock_minutes`: Integer, default 15, allowed `0` (never), 5, 15, 30, 60.
  - Setting `security.lock_on_screen_lock`: Boolean, default true.
  - **Client, primary**: `useAutoLock` in `AppLayout` tracks keydown, pointerdown and wheel. When idle for that many minutes and any token is held, it issues the Lock-all visit. The guard flushes pending saves first.
  - **Server, backstop**: an entry whose `last_used_at` is older than (minutes + 2) is purged. Only user-initiated keyed requests update `last_used_at`. The 5-second external-change check uses `touch: false`.
  - **Screen lock**: a listener for `ScreenLocked` registered in `AppServiceProvider::boot` increments the cache value `mdvault.vault_lock_epoch` when the setting is on. Every existing sealed entry, in any session, then stops working.
  - Reloading the window, restarting the app or a crash discards the renderer's tokens, which locks every vault. Stale session entries are useless without their token and are overwritten on the next unlock.
- **Keeping secrets and plaintext out of storage and logs**:
  - **No HTTP cache**: `SetCacheHeaders::using('no_store;private')` is appended to the `web` group.
  - **Inertia history**: `Inertia::encryptHistory()` on every Workspace response whose current vault is encrypted, and `Inertia::clearHistory()` on lock and lock-all.
  - **Session**:
    - `bootstrap/app.php` adds `dontFlash` for `password`, `password_confirmation`, `current_password`, `name`, `folder`, `parent`, `path`, `source_path`, `content`, `frontmatter` and `token`.
    - For encrypted vaults, toasts and validation or exception messages never include decrypted names or paths. Generic factories are used instead.
    - The only plaintext that leaves the server is the tree and note props and JSON bodies for an unlocked vault. Those are never persisted.
  - **Logs and exceptions**:
    - `#[\SensitiveParameter]` everywhere;
    - `EncryptionService` and `VaultKeyService` contain no `Log`, `logger`, `report`, `dump`, `dd`, `var_dump`, `print_r`, `var_export` or `error_log` (arch rule);
    - `VaultKey` redacts itself.
- **Shared props**: each `vaults[]` summary gains `is_unlocked: bool` (computed with `touch: false`). There is a new `security: {auto_lock_minutes, lock_on_screen_lock}`. Key material, salts and wrapped keys never appear in props.

## Consequences
- **Positive**:
  - Nothing on disk can decrypt a vault once the app is closed, the window is reloaded, or the vault is locked or idle.
  - The server enforces locks: the session entry is deleted or the epoch changes.
  - One interceptor covers every request path.
  - Fully testable with Pest by sending the header.
- **Negative / trade-offs**:
  - A window reload asks for the password again.
  - Unlocking blocks the single-threaded server for about 1 s.
  - Edits typed within the last autosave debounce (about 1.5 s) before an OS sleep or screen lock may hit 423. The editor then shows its existing "couldn't save" Stay or Discard dialog.
  - Tokens sit in renderer memory, which is accepted under the threat model. Any XSS could already read the decrypted content.
- **Follow-ups**:
  - Phase 8 must use `APP_DEBUG=false`.
  - Optional "lock when the window is minimised".
  - Accepted risk (7A-QA-06, accepted at the 7C security review): the lock epoch lives in the cache, so `cache:clear` resets it to 0 and a sealed session entry from before a screen-lock bump would validate again if the renderer still held its token. Exploiting it needs the token too, tokens die on reload, and the idle limit still applies. A persistent epoch (a settings row) is the follow-up if this matters.
  - Resolved at 7C (7B-QA-01, 7B-QA-02): the unlock route is throttled (`throttle:10,1`, generic error), and the keyring interceptor attaches `X-MDVault-Unlock` to same-origin requests only.
