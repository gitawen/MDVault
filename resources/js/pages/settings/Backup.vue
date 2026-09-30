<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import RestoreBackupDialog from '@/components/backups/RestoreBackupDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes } from '@/lib/formatBytes';
import { edit, store } from '@/routes/settings/backup';
import { browse } from '@/routes/settings/backup/restore';
import type { BackupRecord } from '@/types';

const props = defineProps<{
    backups: BackupRecord[];
    defaultDirectory: string;
    canBrowse: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Backup settings', href: edit() }],
    },
});

const page = usePage();
const activeVaultExists = computed(() =>
    page.props.vaults.some((vault) => vault.status === 'active'),
);

const backingUp = ref(false);

function backUpAll(): void {
    backingUp.value = true;

    router.post(
        store.url(),
        { vault: null },
        {
            preserveScroll: true,
            onFinish: () => {
                backingUp.value = false;
            },
        },
    );
}

const browsing = ref(false);
const dialogOpen = ref(false);
const dialogPath = ref<string | null>(null);
const typedPath = ref('');

type PickedBackupFlash = { pickedBackup?: { path: string } };

function chooseBackupFile(): void {
    browsing.value = true;

    router.post(
        browse.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFlash: (flash) => {
                const picked = (flash as PickedBackupFlash)?.pickedBackup;

                if (picked) {
                    dialogPath.value = picked.path;
                    dialogOpen.value = true;
                }
            },
            onFinish: () => {
                browsing.value = false;
            },
        },
    );
}

function checkTypedPath(): void {
    if (typedPath.value.trim() === '') {
        return;
    }

    dialogPath.value = typedPath.value.trim();
    dialogOpen.value = true;
}

function restoreBackup(record: BackupRecord): void {
    dialogPath.value = record.path;
    dialogOpen.value = true;
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Backup settings" />

        <h1 class="sr-only">Backup settings</h1>

        <Heading
            variant="small"
            title="Back up"
            description="Save every note, folder and attachment in your vaults to a single file."
        />

        <div class="space-y-2">
            <p class="text-sm text-muted-foreground">
                {{
                    canBrowse
                        ? "You'll choose where to save the backup."
                        : `Backups are saved to ${defaultDirectory}.`
                }}
            </p>
            <p class="text-sm text-muted-foreground">
                Backups include every note, folder and attachment in your
                vaults, but not hidden folders such as
                <code>.git</code>, or app settings.
            </p>
            <Button
                type="button"
                :disabled="!activeVaultExists || backingUp"
                @click="backUpAll"
            >
                Back up all vaults
            </Button>
        </div>

        <div class="space-y-4">
            <Heading variant="small" title="Restore" />

            <div class="flex flex-wrap items-center gap-2">
                <Button
                    v-if="canBrowse"
                    type="button"
                    variant="outline"
                    :disabled="browsing"
                    @click="chooseBackupFile"
                >
                    Choose backup file&#8230;
                </Button>
            </div>

            <div class="grid gap-2">
                <Label for="backup-file-path">Backup file path</Label>
                <div class="flex gap-2">
                    <Input
                        id="backup-file-path"
                        v-model="typedPath"
                        type="text"
                        autocomplete="off"
                        class="flex-1"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        @click="checkTypedPath"
                    >
                        Check backup
                    </Button>
                </div>
            </div>
        </div>

        <div class="space-y-4">
            <Heading variant="small" title="Recent backups" />

            <p
                v-if="backups.length === 0"
                class="text-sm text-muted-foreground"
            >
                No backups yet.
            </p>

            <div v-else class="space-y-3">
                <div
                    v-for="record in backups"
                    :key="record.uuid"
                    class="space-y-2 rounded-md border p-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">
                            {{ new Date(record.created_at).toLocaleString() }}
                        </span>
                        <span class="text-sm text-muted-foreground">
                            {{
                                record.scope === 'all'
                                    ? 'All vaults'
                                    : record.vaults.join(', ')
                            }}
                        </span>
                        <Badge v-if="!record.exists" variant="destructive">
                            Missing
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ record.note_count }} notes &middot;
                        {{ formatBytes(record.file_size) }}
                    </p>
                    <p
                        class="font-mono text-xs break-all text-muted-foreground"
                    >
                        {{ record.path }}
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="!record.exists"
                        @click="restoreBackup(record)"
                    >
                        Restore&#8230;
                    </Button>
                </div>
            </div>
        </div>

        <RestoreBackupDialog v-model:open="dialogOpen" :path="dialogPath" />
    </div>
</template>
