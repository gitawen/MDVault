<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBar from '@/components/StatusBar.vue';
import NoteEditor from '@/components/editor/NoteEditor.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import OrphanSaveNotice from '@/components/notes/OrphanSaveNotice.vue';
import UnlockVaultPanel from '@/components/vaults/UnlockVaultPanel.vue';
import VaultLockButton from '@/components/vaults/VaultLockButton.vue';
import VaultStatusBadge from '@/components/vaults/VaultStatusBadge.vue';
import { useExternalChanges } from '@/composables/useExternalChanges';
import { workspace } from '@/routes';
import { close, index } from '@/routes/vaults';
import type {
    EditorPreferences,
    NoteDetail,
    NoteTreeNode,
    SystemStatus,
    VaultSummary,
    WorkspaceEncryption,
} from '@/types';

const props = defineProps<{
    status: SystemStatus;
    editor: EditorPreferences;
    currentVault: VaultSummary | null;
    // `tree`, `folders` and `canTrash` are consumed by the sidebar through
    // `usePage()`; they are declared here so they do not fall through as
    // attributes on this component's root element.
    tree: NoteTreeNode[] | null;
    folders: string[];
    note: NoteDetail | null;
    canTrash: boolean;
    treeSignature: string | null;
    checkExternalChanges: boolean;
    encryption: WorkspaceEncryption | null;
}>();

const locked = computed(() => props.encryption?.locked === true);
const unlockedEncrypted = computed(
    () => props.currentVault?.is_encrypted === true && !locked.value,
);

function closeVault() {
    router.post(close.url());
}

const editorRef = ref<InstanceType<typeof NoteEditor> | null>(null);

const { requestCheck, orphanTempFiles } = useExternalChanges({
    vaultUuid: () => props.currentVault?.uuid ?? null,
    enabled: () => props.checkExternalChanges,
    vaultActive: () => props.currentVault?.status === 'active',
    treeSignature: () => props.treeSignature,
    editor: () => editorRef.value,
});

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
            <span class="shrink-0 font-medium">{{ currentVault.name }}</span>
            <span
                class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                :title="currentVault.path"
            >
                {{ currentVault.path }}
            </span>
            <VaultStatusBadge :status="currentVault.status" />
            <VaultLockButton
                v-if="unlockedEncrypted"
                :vault-uuid="currentVault.uuid"
            />
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

        <Alert
            v-if="encryption?.inconsistent && currentVault"
            variant="destructive"
            class="mx-4 mt-4"
        >
            <TriangleAlert />
            <AlertTitle>This vault's key file needs attention</AlertTitle>
            <AlertDescription>
                The key file (mdvault-encryption.json) is missing or damaged, so
                this vault may not unlock. Restore the file from a backup of
                this vault.
            </AlertDescription>
        </Alert>

        <Alert
            v-if="encryption && encryption.unencrypted_files.length > 0"
            class="mx-4 mt-4"
        >
            <TriangleAlert />
            <AlertTitle>Unencrypted files in an encrypted vault</AlertTitle>
            <AlertDescription>
                <p>
                    These Markdown files are not encrypted and MDVault does not
                    list them as notes. Anyone with the folder can read them.
                    Move them out of this vault, or delete them.
                </p>
                <ul class="mt-1 list-disc pl-5 break-all">
                    <li
                        v-for="file in encryption.unencrypted_files"
                        :key="file"
                    >
                        {{ file }}
                    </li>
                </ul>
            </AlertDescription>
        </Alert>

        <OrphanSaveNotice
            v-if="orphanTempFiles.length > 0"
            :paths="orphanTempFiles"
            class="mx-4 mt-4"
        />

        <div
            v-if="currentVault && currentVault.status === 'active'"
            class="flex min-h-0 flex-1 flex-col"
        >
            <main
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto p-4"
            >
                <UnlockVaultPanel v-if="locked" :vault="currentVault" />
                <NoteEditor
                    v-else-if="note"
                    ref="editorRef"
                    :key="`${note.uuid}:${note.base_hash ?? note.state}`"
                    :note="note"
                    :vault-uuid="currentVault.uuid"
                    :preferences="editor"
                    :runtime="status.runtime"
                    :external-checks="checkExternalChanges"
                    @request-check="requestCheck"
                />
                <p v-else class="text-sm text-muted-foreground">
                    Select a note from the sidebar, or create a new one.
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
