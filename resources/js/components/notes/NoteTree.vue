<script setup lang="ts">
import { provide, ref } from 'vue';
import { notifyNoteSelected } from '@/composables/useNoteNavigation';
import type { NoteTreeFolder, NoteTreeNode, NoteTreeNote } from '@/types';
import CreateFolderDialog from './CreateFolderDialog.vue';
import CreateNoteDialog from './CreateNoteDialog.vue';
import DeleteFolderDialog from './DeleteFolderDialog.vue';
import DeleteNoteDialog from './DeleteNoteDialog.vue';
import MoveNoteDialog from './MoveNoteDialog.vue';
import { noteTreeActionsKey } from './noteTreeActions';
import NoteTreeItem from './NoteTreeItem.vue';
import RenameNoteDialog from './RenameNoteDialog.vue';

defineProps<{
    vaultUuid: string;
    tree: NoteTreeNode[];
    folders: string[];
    selectedUuid: string | null;
    canTrash: boolean;
}>();

const emit = defineEmits<{ 'note-selected': [] }>();

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

function newNote(folder = '') {
    createNoteFolder.value = folder;
    createNoteOpen.value = true;
}

function newFolder(parent = '') {
    createFolderParent.value = parent;
    createFolderOpen.value = true;
}

defineExpose({ newNote, newFolder });

provide(noteTreeActionsKey, {
    newNote,
    newFolder,
    noteSelected() {
        notifyNoteSelected();
        emit('note-selected');
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
    <div class="flex flex-col">
        <div class="py-1">
            <p
                v-if="tree.length === 0"
                class="px-2 py-1 text-xs text-muted-foreground"
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
