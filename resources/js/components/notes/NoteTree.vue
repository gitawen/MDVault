<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FilePlus, FolderPlus, RefreshCw } from '@lucide/vue';
import { provide, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { reindex } from '@/routes/vaults';
import type { NoteTreeFolder, NoteTreeNode, NoteTreeNote } from '@/types';
import CreateFolderDialog from './CreateFolderDialog.vue';
import CreateNoteDialog from './CreateNoteDialog.vue';
import DeleteFolderDialog from './DeleteFolderDialog.vue';
import DeleteNoteDialog from './DeleteNoteDialog.vue';
import MoveNoteDialog from './MoveNoteDialog.vue';
import { noteTreeActionsKey } from './noteTreeActions';
import NoteTreeItem from './NoteTreeItem.vue';
import RenameNoteDialog from './RenameNoteDialog.vue';

const props = defineProps<{
    vaultUuid: string;
    tree: NoteTreeNode[];
    folders: string[];
    selectedUuid: string | null;
    canTrash: boolean;
}>();

const reindexing = ref(false);

function runReindex() {
    reindexing.value = true;

    router.post(
        reindex.url(props.vaultUuid),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                reindexing.value = false;
            },
        },
    );
}

const createNoteOpen = ref(false);
const createNoteFolder = ref('');
const createFolderOpen = ref(false);
const createFolderParent = ref('');
const renameNoteOpen = ref(false);
const renameNoteTarget = ref<NoteTreeNote | null>(null);
const moveNoteOpen = ref(false);
const moveNoteTarget = ref<NoteTreeNote | null>(null);
const deleteNoteOpen = ref(false);
const deleteNoteTarget = ref<NoteTreeNote | null>(null);
const deleteFolderOpen = ref(false);
const deleteFolderTarget = ref<NoteTreeFolder | null>(null);

provide(noteTreeActionsKey, {
    newNote(folder: string) {
        createNoteFolder.value = folder;
        createNoteOpen.value = true;
    },
    newFolder(parent: string) {
        createFolderParent.value = parent;
        createFolderOpen.value = true;
    },
    rename(note: NoteTreeNote) {
        renameNoteTarget.value = note;
        renameNoteOpen.value = true;
    },
    move(note: NoteTreeNote) {
        moveNoteTarget.value = note;
        moveNoteOpen.value = true;
    },
    remove(note: NoteTreeNote) {
        deleteNoteTarget.value = note;
        deleteNoteOpen.value = true;
    },
    removeFolder(folder: NoteTreeFolder) {
        deleteFolderTarget.value = folder;
        deleteFolderOpen.value = true;
    },
});
</script>

<template>
    <div class="flex h-full flex-col">
        <div class="flex items-center gap-1 border-b px-2 py-1.5">
            <span class="flex-1 text-sm font-medium">Notes</span>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                title="New note"
                aria-label="New note"
                @click="
                    createNoteFolder = '';
                    createNoteOpen = true;
                "
            >
                <FilePlus class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                title="New folder"
                aria-label="New folder"
                @click="
                    createFolderParent = '';
                    createFolderOpen = true;
                "
            >
                <FolderPlus class="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                title="Re-index"
                aria-label="Re-index"
                :disabled="reindexing"
                @click="runReindex"
            >
                <RefreshCw
                    class="size-4"
                    :class="{ 'animate-spin': reindexing }"
                />
            </Button>
        </div>

        <div class="flex-1 overflow-auto p-1">
            <p
                v-if="tree.length === 0"
                class="p-3 text-sm text-muted-foreground"
            >
                No notes yet. Create one, or add .md files to the vault folder
                and re-index.
            </p>
            <NoteTreeItem
                v-for="node in tree"
                :key="node.type === 'folder' ? node.path : node.uuid"
                :node="node"
                :selected-uuid="selectedUuid"
                :can-trash="canTrash"
                :depth="0"
            />
        </div>

        <CreateNoteDialog
            v-model:open="createNoteOpen"
            :vault-uuid="vaultUuid"
            :folders="folders"
            :default-folder="createNoteFolder"
        />
        <CreateFolderDialog
            v-model:open="createFolderOpen"
            :vault-uuid="vaultUuid"
            :folders="folders"
            :default-parent="createFolderParent"
        />
        <RenameNoteDialog
            v-model:open="renameNoteOpen"
            :note="renameNoteTarget"
        />
        <MoveNoteDialog
            v-model:open="moveNoteOpen"
            :note="moveNoteTarget"
            :folders="folders"
        />
        <DeleteNoteDialog
            v-model:open="deleteNoteOpen"
            :note="deleteNoteTarget"
            :can-trash="canTrash"
        />
        <DeleteFolderDialog
            v-model:open="deleteFolderOpen"
            :vault-uuid="vaultUuid"
            :folder="deleteFolderTarget"
        />
    </div>
</template>
