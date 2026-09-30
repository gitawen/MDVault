import { router, useHttp } from '@inertiajs/vue3';
import type { PendingVisit } from '@inertiajs/core';
import { onMounted, onUnmounted, ref, type Ref } from 'vue';
import {
    createChangeChecker,
    type ChangeChecker,
    type CheckRunResult,
} from '@/lib/external/changeChecker';
import { check } from '@/routes/vaults/changes';
import type { RemoteOpenNote, VaultChangeCheckResponse } from '@/types';

/**
 * What `NoteEditor` exposes so a check can act on the open note without
 * this composable knowing anything about the editor itself (ADR
 * `open-note-external-conflicts`): the save epoch token guards against
 * acting on a result that arrived after a save changed what "current"
 * means.
 */
export type ExternalChangeTarget = {
    noteUuid(): string;
    /** null while a save is in flight: the check is skipped that round. */
    externalCheckToken(): number | null;
    applyExternalStatus(token: number, remote: RemoteOpenNote): void;
};

export type UseExternalChangesOptions = {
    vaultUuid: () => string | null;
    enabled: () => boolean;
    vaultActive: () => boolean;
    treeSignature: () => string | null;
    editor: () => ExternalChangeTarget | null;
};

export type UseExternalChangesReturn = {
    requestCheck(): void;
    orphanTempFiles: Ref<string[]>;
};

/**
 * Wires the renderer-driven polling described in ADR
 * `external-change-detection`: a check on mount, on focus/visibility, and
 * on a 5 s interval (adaptive) while visible, paused during Inertia visits
 * and while a save is in flight.
 */
export function useExternalChanges(
    options: UseExternalChangesOptions,
): UseExternalChangesReturn {
    const orphanTempFiles = ref<string[]>([]);

    let checker: ChangeChecker | null = null;
    let navigating = false;
    let disposed = false;

    function isVisible(): boolean {
        return (
            typeof document === 'undefined' ||
            document.visibilityState === 'visible'
        );
    }

    function postCheck(
        vaultUuid: string,
        openNoteUuid: string | null,
    ): Promise<VaultChangeCheckResponse | null> {
        return new Promise((resolve) => {
            void useHttp<
                { open_note: string | null },
                VaultChangeCheckResponse
            >({
                open_note: openNoteUuid,
            }).post(check.url(vaultUuid), {
                onSuccess: (response) => resolve(response),
                onError: () => resolve(null),
                onHttpException: () => resolve(null),
                onNetworkError: () => resolve(null),
            });
        });
    }

    async function run(): Promise<CheckRunResult> {
        const vaultUuid = options.vaultUuid();

        if (vaultUuid === null) {
            return 'stop';
        }

        const target = options.editor();
        const token = target ? target.externalCheckToken() : null;

        if (target && token === null) {
            return 'skipped';
        }

        const response = await postCheck(vaultUuid, target?.noteUuid() ?? null);

        // The component may have unmounted, or a real navigation may have
        // started, while this request was in flight: acting on it now
        // (reloading, or calling into a target that has moved on) would be
        // acting on stale information (AR-02).
        if (disposed || navigating) {
            return 'skipped';
        }

        if (response === null) {
            return 'error';
        }

        if (response.status === 'disabled' || response.status === 'inactive') {
            return 'stop';
        }

        if (response.status === 'busy') {
            return 'skipped';
        }

        if (response.status === 'unavailable') {
            if (options.vaultActive()) {
                router.reload();
            }

            return 'ok';
        }

        // response.status === 'ok'
        if (!options.vaultActive()) {
            router.reload();

            return 'ok';
        }

        if (response.tree_signature !== options.treeSignature()) {
            router.reload({ only: ['tree', 'folders', 'treeSignature'] });
        }

        if (
            response.open_note &&
            target &&
            token !== null &&
            options.editor() === target
        ) {
            target.applyExternalStatus(token, response.open_note);
        }

        orphanTempFiles.value = response.orphan_temp_files;

        return 'ok';
    }

    function requestCheck(): void {
        checker?.trigger();
    }

    function onFocus(): void {
        checker?.trigger();
    }

    function onVisibilityChange(): void {
        checker?.setActive(isVisible() && !navigating);
    }

    let unsubscribeStart: (() => void) | null = null;
    let unsubscribeFinish: (() => void) | null = null;

    onMounted(() => {
        if (options.enabled()) {
            checker = createChangeChecker({ run });
            checker.start();
        }

        window.addEventListener('focus', onFocus);
        document.addEventListener('visibilitychange', onVisibilityChange);

        unsubscribeStart = router.on(
            'start',
            (event: CustomEvent<{ visit: PendingVisit }>) => {
                // Background reloads (this composable's own tree refresh,
                // NoteEditor's `refresh()`) are async visits, not
                // navigations: they must not pause the checker or a failed
                // one would never resume it (AR-01).
                if (event.detail.visit.async) {
                    return;
                }

                navigating = true;
                checker?.setActive(false);
            },
        );

        unsubscribeFinish = router.on(
            'finish',
            (event: CustomEvent<{ visit: PendingVisit }>) => {
                if (event.detail.visit.async) {
                    return;
                }

                navigating = false;
                checker?.setActive(isVisible());
            },
        );
    });

    onUnmounted(() => {
        disposed = true;
        checker?.dispose();
        checker = null;
        window.removeEventListener('focus', onFocus);
        document.removeEventListener('visibilitychange', onVisibilityChange);
        unsubscribeStart?.();
        unsubscribeFinish?.();
    });

    return { requestCheck, orphanTempFiles };
}
