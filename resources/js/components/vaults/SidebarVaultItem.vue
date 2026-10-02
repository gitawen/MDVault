<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronRight,
    FilePlus,
    FolderClosed,
    FolderOpen,
    FolderPlus,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { ref } from 'vue';
import NoteTree from '@/components/notes/NoteTree.vue';
import {
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { reindex } from '@/routes/vaults';
import type { NoteTreeNode, VaultSummary } from '@/types';

const props = defineProps<{
    vault: VaultSummary;
    expanded: boolean;
    canExpand: boolean;
    hasTree: boolean;
    tree: NoteTreeNode[];
    folders: string[];
    canTrash: boolean;
    selectedUuid: string | null;
}>();

const emit = defineEmits<{ activate: [] }>();

const { isMobile, setOpenMobile } = useSidebar();

const treeRef = ref<InstanceType<typeof NoteTree> | null>(null);
const reindexing = ref(false);

function runReindex() {
    reindexing.value = true;

    router.post(
        reindex.url(props.vault.uuid),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reindexing.value = false;
            },
        },
    );
}

function onNoteSelected() {
    if (isMobile.value) {
        setOpenMobile(false);
    }
}
</script>

<template>
    <SidebarMenuItem>
        <SidebarMenuButton
            :is-active="vault.is_current"
            :tooltip="vault.name"
            as="button"
            :aria-expanded="hasTree ? expanded : undefined"
            :class="{
                'group-has-data-[sidebar=menu-action]/menu-item:pr-20': hasTree,
            }"
            @click="emit('activate')"
        >
            <TriangleAlert v-if="vault.status === 'missing'" />
            <FolderOpen v-else-if="expanded" />
            <FolderClosed v-else />
            <span class="truncate">{{ vault.name }}</span>
            <span v-if="vault.status === 'missing'" class="sr-only">
                (folder missing)
            </span>
            <ChevronRight
                v-if="canExpand"
                class="ml-auto transition-transform group-data-[collapsible=icon]:hidden"
                :class="{ 'rotate-90': expanded }"
            />
        </SidebarMenuButton>

        <template v-if="hasTree">
            <SidebarMenuAction
                show-on-hover
                class="right-13"
                title="New note"
                aria-label="New note"
                @click="treeRef?.newNote('')"
            >
                <FilePlus />
            </SidebarMenuAction>
            <SidebarMenuAction
                show-on-hover
                class="right-7"
                title="New folder"
                aria-label="New folder"
                @click="treeRef?.newFolder('')"
            >
                <FolderPlus />
            </SidebarMenuAction>
            <SidebarMenuAction
                show-on-hover
                class="right-1"
                title="Re-index"
                aria-label="Re-index"
                :disabled="reindexing"
                @click="runReindex"
            >
                <RefreshCw :class="{ 'animate-spin': reindexing }" />
            </SidebarMenuAction>

            <div
                v-show="expanded"
                class="mt-0.5 ml-3.5 border-l border-sidebar-border pl-2 group-data-[collapsible=icon]:hidden"
            >
                <NoteTree
                    ref="treeRef"
                    :vault-uuid="vault.uuid"
                    :tree="tree"
                    :folders="folders"
                    :selected-uuid="selectedUuid"
                    :can-trash="canTrash"
                    @note-selected="onNoteSelected"
                />
            </div>
        </template>
    </SidebarMenuItem>
</template>
