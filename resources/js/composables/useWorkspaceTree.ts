import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { NoteDetail, NoteTreeNode, VaultSummary } from '@/types';

/**
 * Read-only view over the Workspace page props that the sidebar needs to
 * render the current vault's note tree. Selection and casting only.
 */
export function useWorkspaceTree() {
    const page = usePage();

    const onWorkspace = computed(() => page.component === 'Workspace');

    const currentVault = computed<VaultSummary | null>(() => {
        const fromPage = page.props.currentVault as
            | VaultSummary
            | null
            | undefined;

        if (onWorkspace.value && fromPage) {
            return fromPage;
        }

        return page.props.vaults.find((v) => v.is_current) ?? null;
    });

    const currentVaultUuid = computed(() => currentVault.value?.uuid ?? null);

    const tree = computed(
        () => (page.props.tree as NoteTreeNode[] | null | undefined) ?? null,
    );
    const folders = computed(
        () => (page.props.folders as string[] | undefined) ?? [''],
    );
    const canTrash = computed(
        () => (page.props.canTrash as boolean | undefined) ?? false,
    );
    const selectedUuid = computed(
        () => (page.props.note as NoteDetail | null | undefined)?.uuid ?? null,
    );

    const available = computed(
        () =>
            onWorkspace.value &&
            currentVault.value?.status === 'active' &&
            Array.isArray(tree.value),
    );

    return {
        onWorkspace,
        currentVaultUuid,
        tree,
        folders,
        canTrash,
        selectedUuid,
        available,
    };
}
