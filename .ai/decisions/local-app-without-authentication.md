# ADR: Local desktop app without authentication

- **Status**: Accepted (A1/A3/A5 approved 2026-09-26; implemented in phase-0-foundation, analyst sign-off 2026-09-27)
- **Date**: 2026-09-26
- **Phase**: Master Plan Phase 0 — Foundation
- **Deciders**: System Analyst (pending user approval)

## Context
The project was created from the Laravel Vue starter kit. It ships Laravel Fortify 1.40 (login, registration, password reset, email verification, password confirmation), passkeys (`laravel/passkeys`, `@laravel/passkeys`), TOTP 2FA (`pragmarx/google2fa`, `bacon/bacon-qr-code`, `vue-input-otp`), a `User` model, and `users`, `password_reset_tokens` and `passkeys` tables. Profile and security settings pages sit behind `auth`/`verified` middleware.

The Master Plan says MDVault v1:
- must not need user accounts, an authentication server or remote servers (§1, §5);
- lists "Remote accounts" and "User registration" as out of scope (§64);
- assumes one local user and one running instance (§44);
- limits v1 tables to `settings`, `vaults`, `notes`, `vault_encryption`, `backups` (§9).

Per-vault protection is handled by vault encryption (Phase 7), not by application login.

When running under NativePHP, the Laravel app is served by a local PHP server. NativePHP pushes the `PreventRegularBrowserAccess` global middleware, which rejects requests without the per-launch secret (`_php_native` cookie or `X-NativePHP-Secret` header).

## Options Considered
1. **Keep Fortify; auto-create and auto-login a single local user.** Keeps the starter kit intact. But it keeps a fake account with a meaningless password, plus dead 2FA/passkey code and tables, and every future feature would carry `auth` middleware and user scoping that has no product meaning.
2. **Keep Fortify installed but disable all features and routes.** Smaller diff. But dead dependencies, a `users` table and user-coupled code stay, contradicting §9 and §64, and the dead code misleads future contributors.
3. **Remove authentication entirely (chosen).** Uninstall Fortify and passkeys; delete the User model, factory, auth actions, controllers, requests, pages, components and tests; drop the `users`, `password_reset_tokens` and `passkeys` tables by editing the pre-release base migrations; make all routes public.
4. **Defer the decision to a later phase.** Every Phase 1+ feature (settings, vaults) would have to pick auth-or-not per route, and removal would get more expensive over time.

## Decision
Option 3. MDVault v1 has **no application authentication**:
- Remove `laravel/fortify` (and its transitive `laravel/passkeys`, 2FA and QR packages) and the npm packages `@laravel/passkeys` and `vue-input-otp`.
- Delete all auth/account backend and frontend code and tests. Keep only the Appearance settings page.
- The `sessions` table stays: the framework session, CSRF and flash data need it in browser dev, while NativePHP forces the `file` session driver at runtime. Its nullable `user_id` column stays because the framework's database session handler writes it.
- Because the app is pre-release with no user data, edit the base migrations in place rather than adding drop migrations, so v1 ships a clean migration history. Local dev DBs are rebuilt with `migrate:fresh` / `native:migrate:fresh`.
- Delete `config/auth.php` and `config/fortify.php`. Laravel's framework defaults cover the unused auth config.
- Protection of the local server relies on NativePHP's `PreventRegularBrowserAccess`. Protection of sensitive content is the job of vault encryption (Phase 7).

## Consequences
- **Positive**
  - The app opens straight to the workspace, as §5 and §64 require.
  - Less code, fewer dependencies and fewer tables.
  - Future features need no user scoping or `auth` middleware.
- **Negative / trade-offs**
  - Anyone with OS-level access to the user's account can open the app. This is accepted for a local single-user app, same as other local note apps; encrypted vaults cover sensitive data.
  - About 40 starter-kit files and 9 tests are deleted; this needs user approval and should be done under version control.
  - Re-adding accounts later (sync/sharing, v2+) needs a new auth design. That is intentional: the requirements for remote identity will differ from Fortify's web-login model.
- **Follow-ups**
  - Phase 7 must not reintroduce app-level login. Vault unlock is per-vault via `EncryptionService`.
  - Any future feature that needs identity must reopen this ADR.
