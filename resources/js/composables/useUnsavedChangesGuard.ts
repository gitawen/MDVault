import { router } from '@inertiajs/vue3';
import type { PendingVisit } from '@inertiajs/core';
import { onMounted, onUnmounted, ref } from 'vue';

export type UnsavedChangesGuardOptions = {
    isDirty: () => boolean;
    flush: () => Promise<'clean' | 'saved' | 'failed'>;
    discard: () => void;
    /**
     * Makes the editor read-only for the duration of a navigation this
     * guard lets through (R2-01): without this, a keystroke typed during
     * the replayed visit's own round trip is lost once the component
     * unmounts, with no autosave left to catch it.
     */
    freeze: () => void;
    /** Called on `router.on('finish', …)`, i.e. once the visit settles. */
    unfreeze: () => void;
    runtime: 'desktop' | 'browser';
};

/**
 * Saves before any Inertia navigation or a window close (FR-17). A failed
 * save opens a Stay / Discard-and-continue dialog, which the caller (
 * `NoteEditor`) renders using the reactive `dialog` state this returns.
 */
export function useUnsavedChangesGuard(options: UnsavedChangesGuardOptions) {
    const dialogOpen = ref(false);

    let bypassNextVisit = false;
    let pendingVisit: PendingVisit | null = null;
    let unsubscribeBefore: (() => void) | null = null;
    let unsubscribeFinish: (() => void) | null = null;

    function replay(visit: PendingVisit): void {
        router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            only: visit.only,
            except: visit.except,
            preserveState: visit.preserveState,
            preserveScroll: visit.preserveScroll,
            replace: visit.replace,
            headers: visit.headers,
        });
    }

    function handleBefore(event: CustomEvent<{ visit: PendingVisit }>): void {
        if (bypassNextVisit) {
            bypassNextVisit = false;

            return;
        }

        if (!options.isDirty()) {
            // Nothing to save, but the visit may still take a moment: freeze
            // now so nothing typed during that round trip is lost once this
            // component unmounts with no autosave left to catch it.
            options.freeze();

            return;
        }

        event.preventDefault();
        const visit = event.detail.visit;

        void options.flush().then((result) => {
            if (result === 'saved' || result === 'clean') {
                options.freeze();
                replay(visit);

                return;
            }

            pendingVisit = visit;
            dialogOpen.value = true;
        });
    }

    function handleBeforeUnload(event: BeforeUnloadEvent): void {
        if (!options.isDirty()) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';

        void options.flush().then((result) => {
            if (
                (result === 'saved' || result === 'clean') &&
                options.runtime === 'desktop'
            ) {
                window.close();
            }
            // On failure, the editor's own error or conflict banner stays
            // visible; there is nothing more this guard can do here.
        });
    }

    function stay(): void {
        dialogOpen.value = false;
        pendingVisit = null;
    }

    function discardAndContinue(): void {
        dialogOpen.value = false;
        options.discard();

        if (pendingVisit) {
            bypassNextVisit = true;
            options.freeze();
            replay(pendingVisit);
            pendingVisit = null;
        }
    }

    onMounted(() => {
        unsubscribeBefore = router.on('before', handleBefore);
        // Visits that keep this component mounted (a re-index, a partial
        // reload of the same note, a cancelled visit) must unfreeze it
        // again once they settle.
        unsubscribeFinish = router.on('finish', () => {
            options.unfreeze();
        });
        window.addEventListener('beforeunload', handleBeforeUnload);
    });

    onUnmounted(() => {
        unsubscribeBefore?.();
        unsubscribeFinish?.();
        window.removeEventListener('beforeunload', handleBeforeUnload);
    });

    return {
        dialog: { open: dialogOpen },
        stay,
        discardAndContinue,
    };
}
