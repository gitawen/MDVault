<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import StatusBar from '@/components/StatusBar.vue';
import TiptapEditor from '@/components/editor/TiptapEditor.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import NoteTree from '@/components/notes/NoteTree.vue';
import NoteViewer from '@/components/notes/NoteViewer.vue';
import VaultStatusBadge from '@/components/vaults/VaultStatusBadge.vue';
import { workspace } from '@/routes';
import { close, index } from '@/routes/vaults';
import type {
    EditorPreferences,
    NoteDetail,
    NoteTreeNode,
    SystemStatus,
    VaultSummary,
} from '@/types';

defineProps<{
    status: SystemStatus;
    editor: EditorPreferences;
    currentVault: VaultSummary | null;
    tree: NoteTreeNode[] | null;
    folders: string[];
    note: NoteDetail | null;
    canTrash: boolean;
}>();

function closeVault() {
    router.post(close.url());
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspace', href: workspace() }],
    },
});

const demoContent = `
    <h1>Welcome to MDVault</h1>
    <p>This is a preview editor. Changes here are not saved yet.</p>
    <ul>
        <li>Local-first notes, stored on your device</li>
        <li>Rich-text editing powered by Tiptap</li>
        <li>Markdown and saving arrive in a later phase</li>
    </ul>
`;
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <Head title="Workspace" />

        <div
            v-if="currentVault && currentVault.status === 'active'"
            class="flex items-center gap-2 border-b p-2 px-4"
        >
            <span class="font-medium">{{ currentVault.name }}</span>
            <span
                class="truncate text-xs text-muted-foreground"
                :title="currentVault.path"
            >
                {{ currentVault.path }}
            </span>
            <VaultStatusBadge :status="currentVault.status" />
            <Button
                variant="ghost"
                size="sm"
                class="ml-auto"
                @click="closeVault"
            >
                Close vault
            </Button>
        </div>

        <Alert v-else-if="currentVault" variant="destructive" class="m-4">
            <AlertDescription>
                This vault's folder can't be found at {{ currentVault.path }}.
                Reconnect the drive, or
                <Link :href="index()" class="underline">
                    remove the vault from the Vaults page </Link
                >.
            </AlertDescription>
        </Alert>

        <div
            v-else
            class="flex items-center gap-2 border-b p-2 px-4 text-sm text-muted-foreground"
        >
            No vault open.
            <Link :href="index()" class="underline"
                >Open or create a vault</Link
            >
        </div>

        <div
            v-if="
                currentVault &&
                currentVault.status === 'active' &&
                tree !== null
            "
            class="flex min-h-0 flex-1"
        >
            <aside class="w-64 shrink-0 overflow-auto border-r">
                <NoteTree
                    :vault-uuid="currentVault.uuid"
                    :tree="tree"
                    :folders="folders"
                    :selected-uuid="note?.uuid ?? null"
                    :can-trash="canTrash"
                />
            </aside>
            <main class="flex-1 overflow-auto p-4">
                <NoteViewer
                    v-if="note"
                    :note="note"
                    :vault-uuid="currentVault.uuid"
                />
                <p v-else class="text-sm text-muted-foreground">
                    Select a note from the list, or create a new one.
                </p>
            </main>
        </div>

        <main v-else class="flex-1 overflow-auto p-4">
            <TiptapEditor :content="demoContent" :preferences="editor" />
        </main>

        <StatusBar :status="status" />
    </div>
</template>
