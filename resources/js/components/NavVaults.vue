<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { FolderClosed, Plus, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import {
    SidebarGroup,
    SidebarGroupAction,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index, open } from '@/routes/vaults';

const page = usePage();
const vaults = computed(() => page.props.vaults);

function openVault(uuid: string) {
    router.post(open.url(uuid));
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
            <SidebarMenuItem v-for="vault in vaults" :key="vault.uuid">
                <SidebarMenuButton
                    :is-active="vault.is_current"
                    :tooltip="vault.name"
                    as="button"
                    @click="openVault(vault.uuid)"
                >
                    <TriangleAlert v-if="vault.status === 'missing'" />
                    <FolderClosed v-else />
                    <span>{{ vault.name }}</span>
                    <span v-if="vault.status === 'missing'" class="sr-only">
                        (folder missing)
                    </span>
                </SidebarMenuButton>
            </SidebarMenuItem>
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
