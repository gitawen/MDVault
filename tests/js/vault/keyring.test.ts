import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';

type RequestHandler = (config: {
    headers?: Record<string, string>;
    [key: string]: unknown;
}) => { headers?: Record<string, string>; [key: string]: unknown };

const registered = vi.hoisted(() => ({
    request: null as null | ((config: never) => unknown),
    success: null as null | ((event: never) => void),
    offRequest: vi.fn(),
    offSuccess: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    http: {
        onRequest: (handler: (config: never) => unknown) => {
            registered.request = handler;

            return registered.offRequest;
        },
    },
    router: {
        on: (name: string, handler: (event: never) => void) => {
            if (name === 'success') {
                registered.success = handler;
            }

            return registered.offSuccess;
        },
    },
}));

import {
    forget,
    forgetAll,
    has,
    hasAny,
    headerValue,
    HEADER_NAME,
    installVaultKeyringInterceptor,
    MAX_PAIRS,
    PRUNE_GRACE_MS,
    pruneFrom,
    setToken,
} from '../../../resources/js/lib/vault/keyring';

const A = '11111111-1111-4111-8111-111111111111';
const B = '22222222-2222-4222-8222-222222222222';

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-03T10:00:00Z'));
    forgetAll();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    registered.offRequest.mockClear();
    registered.offSuccess.mockClear();
});

describe('header encoding', () => {
    it('is null when no vault is unlocked', () => {
        expect(headerValue()).toBeNull();
        expect(hasAny()).toBe(false);
    });

    it('joins uuid:token pairs with commas', () => {
        setToken(A, 'tokenA');
        setToken(B, 'tokenB');

        expect(headerValue()).toBe(`${A}:tokenA,${B}:tokenB`);
        expect(has(A)).toBe(true);
        expect(hasAny()).toBe(true);
    });

    it('replaces a token and keeps the newest pairs within the cap', () => {
        for (let i = 0; i < MAX_PAIRS + 3; i++) {
            setToken(`vault-${i}`, `t${i}`);
        }

        const pairs = (headerValue() ?? '').split(',');

        expect(pairs).toHaveLength(MAX_PAIRS);
        expect(pairs[pairs.length - 1]).toBe(
            `vault-${MAX_PAIRS + 2}:t${MAX_PAIRS + 2}`,
        );
        expect(pairs[0]).toBe('vault-3:t3');

        setToken('vault-3', 'fresh');
        expect((headerValue() ?? '').endsWith('vault-3:fresh')).toBe(true);
    });
});

describe('forgetting', () => {
    it('forgets one vault or all of them', () => {
        setToken(A, 'a');
        setToken(B, 'b');

        forget(A);
        expect(headerValue()).toBe(`${B}:b`);

        forgetAll();
        expect(headerValue()).toBeNull();
    });
});

describe('pruneFrom', () => {
    beforeEach(() => {
        setToken(A, 'a');
        setToken(B, 'b');
        vi.advanceTimersByTime(PRUNE_GRACE_MS + 1);
    });

    it('keeps the tokens of unlocked encrypted vaults', () => {
        pruneFrom([
            { uuid: A, is_encrypted: true, is_unlocked: true },
            { uuid: B, is_encrypted: true, is_unlocked: true },
        ]);

        expect(has(A)).toBe(true);
        expect(has(B)).toBe(true);
    });

    it('drops tokens the server reports as locked, unencrypted or gone', () => {
        pruneFrom([{ uuid: A, is_encrypted: true, is_unlocked: false }]);

        expect(has(A)).toBe(false);
        expect(has(B)).toBe(false);

        setToken(A, 'a');
        vi.advanceTimersByTime(PRUNE_GRACE_MS + 1);
        pruneFrom([{ uuid: A, is_encrypted: false, is_unlocked: false }]);

        expect(has(A)).toBe(false);
    });

    it('never prunes a token younger than the grace period', () => {
        setToken(A, 'fresh');

        pruneFrom([{ uuid: A, is_encrypted: true, is_unlocked: false }]);

        expect(has(A)).toBe(true);

        vi.advanceTimersByTime(PRUNE_GRACE_MS + 1);
        pruneFrom([{ uuid: A, is_encrypted: true, is_unlocked: false }]);

        expect(has(A)).toBe(false);
    });
});

describe('the interceptor', () => {
    it('adds the header only when a token is held and keeps other headers', () => {
        installVaultKeyringInterceptor();
        const handler = registered.request as unknown as RequestHandler;

        expect(handler({ headers: { Accept: 'x' }, url: '/u' })).toEqual({
            headers: { Accept: 'x' },
            url: '/u',
        });

        setToken(A, 'tokenA');

        expect(handler({ headers: { Accept: 'x' }, url: '/u' })).toEqual({
            url: '/u',
            headers: { Accept: 'x', [HEADER_NAME]: `${A}:tokenA` },
        });
        expect(handler({ url: '/u' })).toEqual({
            url: '/u',
            headers: { [HEADER_NAME]: `${A}:tokenA` },
        });
    });

    it('attaches the header to same-origin requests only', () => {
        vi.stubGlobal('location', { origin: 'https://app.test' });
        installVaultKeyringInterceptor();
        const handler = registered.request as unknown as RequestHandler;
        setToken(A, 'tokenA');

        const header = { [HEADER_NAME]: `${A}:tokenA` };

        for (const url of [
            '/vaults/x/unlock',
            'relative/path',
            'https://app.test/vaults',
        ]) {
            expect(handler({ url }).headers).toEqual(header);
        }

        for (const url of [
            'https://evil.test/collect',
            'http://app.test/vaults',
            'https://app.test.evil.test/x',
            '//evil.test/x',
            'https://user@evil.test/',
            'javascript:alert(1)',
            '',
        ]) {
            expect(handler({ url, headers: { Accept: 'x' } })).toEqual({
                url,
                headers: { Accept: 'x' },
            });
        }

        expect(handler({ headers: { Accept: 'x' } })).toEqual({
            headers: { Accept: 'x' },
        });
    });

    it('prunes after each successful visit using the shared vaults prop', () => {
        installVaultKeyringInterceptor();
        setToken(A, 'a');
        vi.advanceTimersByTime(PRUNE_GRACE_MS + 1);

        (registered.success as unknown as (event: unknown) => void)({
            detail: {
                page: {
                    props: {
                        vaults: [
                            { uuid: A, is_encrypted: true, is_unlocked: false },
                        ],
                    },
                },
            },
        });

        expect(has(A)).toBe(false);
    });

    it('ignores a page without a vaults list', () => {
        installVaultKeyringInterceptor();
        setToken(A, 'a');
        vi.advanceTimersByTime(PRUNE_GRACE_MS + 1);

        (registered.success as unknown as (event: unknown) => void)({
            detail: { page: { props: {} } },
        });

        expect(has(A)).toBe(true);
    });

    it('is idempotent and can be removed', () => {
        const remove = installVaultKeyringInterceptor();

        expect(installVaultKeyringInterceptor()).toBe(remove);

        remove();

        expect(registered.offRequest).toHaveBeenCalledTimes(1);
        expect(registered.offSuccess).toHaveBeenCalledTimes(1);
    });
});

describe('storage', () => {
    it('never touches localStorage or sessionStorage', () => {
        const forbidden = {
            getItem: vi.fn(),
            setItem: vi.fn(),
            removeItem: vi.fn(),
            clear: vi.fn(),
            key: vi.fn(),
            length: 0,
        };
        vi.stubGlobal('localStorage', forbidden);
        vi.stubGlobal('sessionStorage', forbidden);
        vi.stubGlobal('document', { cookie: '' });

        installVaultKeyringInterceptor();
        setToken(A, 'a');
        headerValue();
        pruneFrom([{ uuid: A, is_encrypted: true, is_unlocked: true }]);
        forget(A);
        forgetAll();

        for (const fn of Object.values(forbidden)) {
            if (typeof fn === 'function') {
                expect(fn).not.toHaveBeenCalled();
            }
        }

        expect(
            (globalThis as { document: { cookie: string } }).document.cookie,
        ).toBe('');
    });
});
