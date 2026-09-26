# ADR: Server-side theme persistence

- **Status**: Proposed (Phase 1 plan, pending user approval B2)
- **Date**: 2026-09-27
- **Phase**: Master Plan Phase 1 — Storage and Settings (§10 `appearance.theme`, §35, §50: "User can change theme. Settings persist after application restart.")

## Context
The starter kit stores the theme in `localStorage` and in an unencrypted `appearance` cookie:
- `HandleAppearance` shares the cookie value to Blade for a first-paint `dark` class;
- `useAppearance.ts` reads `localStorage` on the client.

This happens to survive restarts in Electron, because the default session is persistent. However:
- it is a second source of truth outside SQLite, contradicting §2 and §60 Rule 2 ("SQLite manages application settings");
- it is invisible to `SettingsService`, backup/export and tests;
- it can diverge between the cookie and `localStorage`.

## Options Considered
1. **Keep cookie + `localStorage`.** Zero work, but violates the Master Plan settings model and is untestable server-side.
2. **SQLite as the source, and keep writing the cookie/`localStorage` as a cache for first paint.** There would be two sources that can disagree (e.g. after a DB reset).
3. **SQLite as the single source, rendered server-side into the root HTML (chosen).**

## Decision
- `appearance.theme` (`light|dark|system`, default `system`) is stored via `SettingsService`.
- `HandleAppearance` reads it (fail-soft: default `system`) and shares it to Blade. `app.blade.php` renders `data-appearance="<theme>"` and the `dark` class for `dark` on `<html>`. The existing inline script resolves `system` via `prefers-color-scheme` before paint.
- `useAppearance.ts`:
  - initialises from `document.documentElement.dataset.appearance`;
  - applies a change immediately (optimistic);
  - updates the data attribute;
  - persists with `PATCH /settings/appearance` (Wayfinder action, `preserveScroll`/`preserveState`);
  - restores the previous theme on error.
- The `localStorage` and cookie code is removed, and `appearance` is removed from `encryptCookies(except:)`.
- An old browser-stored preference is not migrated (pre-release).

## Consequences
- **Positive**:
  - One source of truth.
  - Correct first paint with no flash.
  - Persistence is covered by feature tests (HTML contains `data-appearance`/`class="dark"`).
  - Future appearance keys (accent colour, etc.) follow the same path.
- **Negative / trade-offs**:
  - Every full page load does one small settings query (shared with other settings via the scoped memo).
  - Browser dev and native dev keep separate themes (separate SQLite files).
  - A failed save causes a brief visual revert.
- **Follow-ups**: accent colour, logo and favicon (deferred, B4) should extend `HandleAppearance`/Blade in the same way rather than reintroduce client storage.

Next step: the orchestrator saves the five artifacts and gets the user's answers to B1–B4. After that, send `senior-developer` to implement `.ai/features/active/phase-1-storage-settings/plan.md` (Revision 1), T1 through T11 in order.
