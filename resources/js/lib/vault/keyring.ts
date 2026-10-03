import { http, router } from '@inertiajs/vue3';

/**
 * The renderer's half of the vault key custody (ADR
 * `encrypted-vault-key-custody`): a per-unlock random token for each
 * unlocked vault, held in module memory only. It is never written to
 * localStorage, sessionStorage, cookies, Inertia props or remembered form
 * state, so a reload, a crash or closing the window locks every vault.
 */

export const HEADER_NAME = 'X-MDVault-Unlock';

/** The server reads at most this many `uuid:token` pairs. */
export const MAX_PAIRS = 20;

/**
 * A fresh token is never pruned within this window: a visit that started
 * before the vault was unlocked can still be answered afterwards with the
 * vault reported as locked.
 */
export const PRUNE_GRACE_MS = 3000;

type Entry = { token: string; setAt: number };

const tokens = new Map<string, Entry>();

export type VaultLockState = {
    uuid: string;
    is_encrypted: boolean;
    is_unlocked: boolean;
};

export function setToken(uuid: string, token: string): void {
    // Re-inserting moves the entry to the end, so the newest tokens survive
    // the 20-pair cap.
    tokens.delete(uuid);
    tokens.set(uuid, { token, setAt: Date.now() });
}

export function forget(uuid: string): void {
    tokens.delete(uuid);
}

export function forgetAll(): void {
    tokens.clear();
}

export function has(uuid: string): boolean {
    return tokens.has(uuid);
}

export function hasAny(): boolean {
    return tokens.size > 0;
}

/**
 * The `X-MDVault-Unlock` header value, or null when no vault is unlocked.
 */
export function headerValue(): string | null {
    if (tokens.size === 0) {
        return null;
    }

    return [...tokens.entries()]
        .slice(-MAX_PAIRS)
        .map(([uuid, entry]) => `${uuid}:${entry.token}`)
        .join(',');
}

/**
 * Drops the tokens the server reports as locked, unencrypted or gone.
 */
export function pruneFrom(vaults: VaultLockState[]): void {
    const byUuid = new Map(vaults.map((vault) => [vault.uuid, vault]));
    const now = Date.now();

    for (const [uuid, entry] of tokens.entries()) {
        if (now - entry.setAt < PRUNE_GRACE_MS) {
            continue;
        }

        const vault = byUuid.get(uuid);

        if (!vault || !vault.is_encrypted || !vault.is_unlocked) {
            tokens.delete(uuid);
        }
    }
}

/**
 * Whether the URL resolves to this window's own origin. Relative URLs do; an
 * absolute URL to another origin, a missing URL or garbage does not.
 */
export function isSameOrigin(url: string | undefined): boolean {
    if (typeof url !== 'string' || url === '') {
        return false;
    }

    try {
        const base = globalThis.location?.origin ?? 'http://localhost';

        return new URL(url, base).origin === new URL(base).origin;
    } catch {
        return false;
    }
}

let uninstall: (() => void) | null = null;

/**
 * Adds the header to every Inertia request (router visits, `useForm`,
 * `<Form>` and `useHttp`) and prunes stale tokens after each visit.
 * Idempotent; returns a function that removes both hooks.
 */
export function installVaultKeyringInterceptor(): () => void {
    if (uninstall) {
        return uninstall;
    }

    const offRequest = http.onRequest((config) => {
        const value = headerValue();

        // The tokens go only to this app's own server: a request to any other
        // origin (or one whose URL can't be resolved) never carries them.
        if (value === null || !isSameOrigin(config.url)) {
            return config;
        }

        return {
            ...config,
            headers: { ...config.headers, [HEADER_NAME]: value },
        };
    });

    const offSuccess = router.on('success', (event) => {
        const vaults: unknown = event.detail.page.props.vaults;

        if (Array.isArray(vaults)) {
            pruneFrom(vaults as VaultLockState[]);
        }
    });

    uninstall = () => {
        offRequest();
        offSuccess();
        uninstall = null;
    };

    return uninstall;
}
