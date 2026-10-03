import { router, usePage } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import { forgetAll, hasAny } from '@/lib/vault/keyring';
import { all as lockAll } from '@/routes/vaults/lock';

/** How often the idle clock is checked. */
const CHECK_INTERVAL_MS = 15_000;

const ACTIVITY_EVENTS = ['keydown', 'pointerdown', 'wheel'] as const;

/**
 * Locks every vault when the user has been idle for
 * `security.auto_lock_minutes` (0 = never), but only while some vault is
 * unlocked. The lock is an Inertia visit, so the unsaved-changes guard
 * flushes pending saves first. The server enforces its own, slightly longer
 * limit as a backstop (ADR `encrypted-vault-key-custody`).
 */
export function useAutoLock(): void {
    const page = usePage();

    let lastActivity = Date.now();
    let interval: ReturnType<typeof setInterval> | null = null;
    let locking = false;

    function markActive(): void {
        lastActivity = Date.now();
    }

    function check(): void {
        const minutes = page.props.security?.auto_lock_minutes ?? 0;

        if (locking || minutes <= 0 || !hasAny()) {
            return;
        }

        if (Date.now() - lastActivity < minutes * 60_000) {
            return;
        }

        locking = true;

        router.post(
            lockAll.url(),
            {},
            {
                onSuccess: () => forgetAll(),
                onFinish: () => {
                    locking = false;
                    lastActivity = Date.now();
                },
            },
        );
    }

    function onVisible(): void {
        if (document.visibilityState === 'visible') {
            check();
        }
    }

    onMounted(() => {
        for (const name of ACTIVITY_EVENTS) {
            window.addEventListener(name, markActive, { passive: true });
        }

        document.addEventListener('visibilitychange', onVisible);
        interval = setInterval(check, CHECK_INTERVAL_MS);
    });

    onBeforeUnmount(() => {
        for (const name of ACTIVITY_EVENTS) {
            window.removeEventListener(name, markActive);
        }

        document.removeEventListener('visibilitychange', onVisible);

        if (interval !== null) {
            clearInterval(interval);
        }
    });
}
