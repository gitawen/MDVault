<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { PanelLeft } from '@lucide/vue';
import { ref } from 'vue';
import StatusBar from '@/components/StatusBar.vue';
import NoteEditor from '@/components/editor/NoteEditor.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import NoteTree from '@/components/notes/NoteTree.vue';
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

// Below `md`, the note tree starts collapsed behind this toggle so the
// editor gets the full width on a phone; from `md` up it is always shown
// (the toggle button itself is hidden there via `md:hidden`).
const treeOpen = ref(false);

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Workspace', href: workspace() }],
    },
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <Head title="Workspace" />

        <div
            v-if="currentVault && currentVault.status === 'active'"
            class="flex items-center gap-2 border-b p-2 px-4"
        >
            <Button
                v-if="tree !== null"
                variant="ghost"
                size="icon-sm"
                class="shrink-0 md:hidden"
                :aria-pressed="treeOpen"
                title="Toggle note list"
                aria-label="Toggle note list"
                @click="treeOpen = !treeOpen"
            >
                <PanelLeft />
            </Button>
            <span class="shrink-0 font-medium">{{ currentVault.name }}</span>
            <span
                class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                :title="currentVault.path"
            >
                {{ currentVault.path }}
            </span>
            <VaultStatusBadge :status="currentVault.status" />
            <Button
                variant="ghost"
                size="sm"
                class="shrink-0"
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
            class="flex min-h-0 flex-1 flex-col md:flex-row"
        >
            <aside
                class="shrink-0 overflow-auto md:block md:w-64 md:border-r md:border-b-0"
                :class="treeOpen ? 'block max-h-64 border-b' : 'hidden'"
            >
                <NoteTree
                    :vault-uuid="currentVault.uuid"
                    :tree="tree"
                    :folders="folders"
                    :selected-uuid="note?.uuid ?? null"
                    :can-trash="canTrash"
                />
            </aside>
            <main
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto p-4"
            >
                <NoteEditor
                    v-if="note"
                    :key="`${note.uuid}:${note.base_hash ?? note.state}`"
                    :note="note"
                    :vault-uuid="currentVault.uuid"
                    :preferences="editor"
                    :runtime="status.runtime"
                />
                <p v-else class="text-sm text-muted-foreground">
                    Select a note from the list, or create a new one.
                </p>
            </main>
        </div>

        <main
            v-else
            class="flex flex-1 items-center justify-center p-4 text-sm text-muted-foreground"
        >
            <p>
                Open or create a vault to start writing.
                <Link :href="index()" class="underline">Go to Vaults</Link>
            </p>
        </main>

        <StatusBar :status="status" />
    </div>
</template>
