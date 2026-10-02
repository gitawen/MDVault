<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SidebarVaultItem from '@/components/vaults/SidebarVaultItem.vue';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenu,
    useSidebar,
} from '@/components/ui/sidebar';
import { useWorkspaceTree } from '@/composables/useWorkspaceTree';
import { workspace } from '@/routes';
import { index, open } from '@/routes/vaults';
import type { VaultSummary } from '@/types';

const page = usePage();
const vaults = computed(() => page.props.vaults);

const { state, isMobile } = useSidebar();
const { onWorkspace, tree, folders, canTrash, selectedUuid, available } =
    useWorkspaceTree();

const currentExpanded = ref(true);

const currentUuid = computed(
    () => vaults.value.find((v) => v.is_current)?.uuid ?? null,
);

watch(currentUuid, () => {
    currentExpanded.value = true;
});

function onActivate(vault: VaultSummary) {
    if (vault.status === 'missing' || !vault.is_current) {
        if (vault.status !== 'missing') {
            currentExpanded.value = true;
        }

        router.post(open.url(vault.uuid));

        return;
    }

    if (!onWorkspace.value) {
        currentExpanded.value = true;
        router.visit(workspace());

        return;
    }

    if (state.value === 'collapsed' && !isMobile.value) {
        return;
    }

    currentExpanded.value = !currentExpanded.value;
}
</script>

<template>
    <SidebarGroup class="px-2 py-0">
        <SidebarGroupLabel>Vaults</SidebarGroupLabel>
        <SidebarGroupAction as-child>
            <Link :href="index()" title="Manage vaults">
                <Plus />
            </Link>
        </SidebarGroupAction>

        <SidebarMenu>
            <SidebarVaultItem
                v-for="vault in vaults"
                :key="vault.uuid"
                :vault="vault"
                :can-expand="vault.status !== 'missing'"
                :has-tree="vault.is_current && available"
                :expanded="vault.is_current && available && currentExpanded"
                :tree="tree ?? []"
                :folders="folders"
                :can-trash="canTrash"
                :selected-uuid="selectedUuid"
                @activate="onActivate(vault)"
            />
        </SidebarMenu>

        <div
            v-if="vaults.length === 0"
            class="px-2 py-1 text-xs text-muted-foreground"
        >
            <Link :href="index()" class="hover:underline">
                Create a vault
            </Link>
        </div>
    </SidebarGroup>
</template>
