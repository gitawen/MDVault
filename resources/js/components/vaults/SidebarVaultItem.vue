<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronRight,
    FilePlus,
    FolderClosed,
    FolderOpen,
    FolderPlus,
    Lock,
    LockOpen,
    RefreshCw,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import NoteTree from '@/components/notes/NoteTree.vue';
import {
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { forget } from '@/lib/vault/keyring';
import { lock, reindex } from '@/routes/vaults';
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
const locking = ref(false);

const isEncrypted = computed(() => props.vault.is_encrypted);
const showLock = computed(
    () => props.vault.is_encrypted && props.vault.is_unlocked,
);

function lockVault() {
    locking.value = true;

    router.post(
        lock.url(props.vault.uuid),
        {},
        {
            onSuccess: () => forget(props.vault.uuid),
            onFinish: () => {
                locking.value = false;
            },
        },
    );
}

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
                'group-has-data-[sidebar=menu-action]/menu-item:pr-20':
                    hasTree && !showLock,
                'group-has-data-[sidebar=menu-action]/menu-item:pr-26':
                    hasTree && showLock,
                'group-has-data-[sidebar=menu-action]/menu-item:pr-8':
                    !hasTree && showLock,
            }"
            @click="emit('activate')"
        >
            <TriangleAlert v-if="vault.status === 'missing'" />
            <Lock v-else-if="isEncrypted && !vault.is_unlocked" />
            <LockOpen v-else-if="isEncrypted" />
            <FolderOpen v-else-if="expanded" />
            <FolderClosed v-else />
            <span class="truncate">{{ vault.name }}</span>
            <span v-if="vault.status === 'missing'" class="sr-only">
                (folder missing)
            </span>
            <span v-else-if="isEncrypted" class="sr-only">
                ({{ vault.is_unlocked ? 'encrypted, unlocked' : 'locked' }})
            </span>
            <ChevronRight
                v-if="canExpand"
                class="ml-auto transition-transform group-data-[collapsible=icon]:hidden"
                :class="{ 'rotate-90': expanded }"
            />
        </SidebarMenuButton>

        <SidebarMenuAction
            v-if="showLock"
            show-on-hover
            :class="hasTree ? 'right-19' : 'right-1'"
            title="Lock vault"
            aria-label="Lock vault"
            :disabled="locking"
            @click="lockVault"
        >
            <Lock />
        </SidebarMenuAction>

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
