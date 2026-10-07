<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    FileText,
    FolderArchive,
    FolderOpen,
    LogOut,
    TriangleAlert,
} from '@lucide/vue';
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
            class="flex items-center justify-between gap-2 border-b border-border/70 bg-card/40 px-3 py-2 sm:px-4"
        >
            <div class="flex min-w-0 items-center gap-2.5">
                <div
                    class="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary"
                >
                    <FolderArchive class="size-4" />
                </div>
                <div class="flex min-w-0 flex-col">
                    <div class="flex items-center gap-1.5">
                        <span
                            class="truncate text-xs font-semibold text-foreground sm:text-sm"
                            >{{ currentVault.name }}</span
                        >
                        <VaultStatusBadge :status="currentVault.status" />
                    </div>
                    <span
                        class="hidden max-w-sm min-w-0 truncate font-mono text-[11px] text-muted-foreground md:block"
                        :title="currentVault.path"
                    >
                        {{ currentVault.path }}
                    </span>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-1.5">
                <VaultLockButton
                    v-if="unlockedEncrypted"
                    :vault-uuid="currentVault.uuid"
                />
                <Button
                    variant="ghost"
                    size="sm"
                    class="h-7 gap-1 px-2 text-xs text-muted-foreground hover:text-foreground sm:px-2.5"
                    @click="closeVault"
                >
                    <LogOut class="size-3.5" />
                    <span class="hidden sm:inline">Close vault</span>
                </Button>
            </div>
        </div>

        <Alert
            v-else-if="currentVault"
            variant="destructive"
            class="m-3 sm:m-4"
        >
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
            class="flex items-center gap-2 border-b border-border/70 bg-card/40 px-3 py-2 text-xs text-muted-foreground sm:px-4 sm:text-sm"
        >
            <FolderOpen class="size-4 text-muted-foreground" />
            <span>No vault open.</span>
            <Link
                :href="index()"
                class="font-medium text-foreground underline hover:text-primary"
                >Open or create a vault</Link
            >
        </div>

        <Alert
            v-if="encryption?.inconsistent && currentVault"
            variant="destructive"
            class="mx-3 mt-3 sm:mx-4 sm:mt-4"
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
            class="mx-3 mt-3 sm:mx-4 sm:mt-4"
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
            class="mx-3 mt-3 sm:mx-4 sm:mt-4"
        />

        <div
            v-if="currentVault && currentVault.status === 'active'"
            class="flex min-h-0 flex-1 flex-col"
        >
            <main
                class="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto p-2.5 sm:p-4 md:p-6"
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
                <div
                    v-else
                    class="flex min-h-[300px] flex-1 flex-col items-center justify-center rounded-xl border border-dashed border-border/80 bg-muted/10 p-8 text-center"
                >
                    <div
                        class="mb-3 flex size-12 items-center justify-center rounded-full bg-muted/70 text-muted-foreground"
                    >
                        <FileText class="size-6 text-muted-foreground/80" />
                    </div>
                    <h3 class="text-sm font-semibold text-foreground">
                        No note selected
                    </h3>
                    <p class="mt-1 max-w-xs text-xs text-muted-foreground">
                        Select a note from the sidebar, or create a new one to
                        begin writing.
                    </p>
                </div>
            </main>
        </div>

        <main
            v-else
            class="flex flex-1 flex-col items-center justify-center p-6 text-center text-sm text-muted-foreground"
        >
            <div
                class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-muted/60 text-muted-foreground"
            >
                <FolderArchive class="size-7 text-primary/70" />
            </div>
            <h3 class="text-base font-semibold text-foreground">
                Welcome to MDVault
            </h3>
            <p class="mt-1 max-w-sm text-xs text-muted-foreground">
                Open an existing markdown vault on your device, or create a
                brand new vault to start organizing your knowledge.
            </p>
            <div class="mt-4">
                <Button size="sm" as-child>
                    <Link :href="index()">Open or Create Vault</Link>
                </Button>
            </div>
        </main>

        <StatusBar :status="status" />
    </div>
</template>
