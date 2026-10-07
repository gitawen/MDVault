<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    CheckCircle2,
    Clock,
    FileArchive,
    FolderOpen,
    HardDrive,
    Info,
    RotateCcw,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ResetDatabaseDialog from '@/components/backups/ResetDatabaseDialog.vue';
import RestoreBackupDialog from '@/components/backups/RestoreBackupDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
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
const missingVaultCount = computed(
    () =>
        page.props.vaults.filter((vault) => vault.status === 'missing').length,
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

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">
                Backup & Recovery
            </h2>
            <p class="text-sm text-muted-foreground">
                Safeguard all vault contents into standalone archives or restore
                previously created snapshots.
            </p>
        </div>

        <!-- Create Backup Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <Archive class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium"
                            >Create Full Backup</CardTitle
                        >
                        <CardDescription>
                            Bundle every note, directory structure, and
                            attachment across all vaults into a single archive.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <div
                    class="space-y-2 rounded-xl border border-border/50 bg-muted/20 p-4 text-xs text-muted-foreground"
                >
                    <div
                        class="flex items-center gap-2 font-medium text-foreground"
                    >
                        <HardDrive class="h-4 w-4 text-primary" />
                        <span>Destination</span>
                    </div>
                    <p class="leading-relaxed">
                        {{
                            canBrowse
                                ? "When initiated, you'll choose the folder or drive where the backup archive will be written."
                                : `Backups are automatically saved to your configured directory: ${defaultDirectory}`
                        }}
                    </p>
                    <p class="text-[11px] text-muted-foreground/80">
                        Backups preserve all notes and media, excluding hidden
                        version control directories (such as
                        <code class="font-mono">.git</code>) and application
                        runtime logs.
                    </p>
                </div>

                <div class="flex items-center justify-between pt-1">
                    <p
                        v-if="!activeVaultExists"
                        class="text-xs text-muted-foreground"
                    >
                        Create or open a vault before generating a backup.
                    </p>
                    <span v-else />
                    <Button
                        type="button"
                        :disabled="!activeVaultExists || backingUp"
                        class="min-w-36 gap-2"
                        @click="backUpAll"
                    >
                        <Spinner v-if="backingUp" class="h-4 w-4" />
                        <Archive v-else class="h-4 w-4" />
                        <span>{{
                            backingUp ? 'Backing up…' : 'Back up all vaults'
                        }}</span>
                    </Button>
                </div>
            </CardContent>
        </Card>

        <!-- Restore Backup Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                    >
                        <RotateCcw class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle class="text-base font-medium"
                            >Restore from Archive</CardTitle
                        >
                        <CardDescription>
                            Inspect and import notes from a previous backup
                            file.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent class="space-y-4">
                <!-- Missing Vaults Warning -->
                <div
                    v-if="missingVaultCount > 0"
                    class="flex items-start gap-3 rounded-lg border border-amber-500/20 bg-amber-500/10 p-3.5 text-xs text-amber-700 dark:text-amber-400"
                >
                    <AlertTriangle
                        class="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400"
                    />
                    <div class="space-y-1">
                        <p class="font-medium">Missing Vaults Detected</p>
                        <p class="leading-relaxed">
                            {{ missingVaultCount }} vault(s) are missing from
                            disk. A backup of them would be skipped or restored
                            as copies. Reset the database below (or remove them
                            on the Vaults page) before restoring.
                        </p>
                    </div>
                </div>

                <!-- Restore File Picker & Path Input -->
                <div class="space-y-3">
                    <div v-if="canBrowse">
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="browsing"
                            class="gap-1.5"
                            @click="chooseBackupFile"
                        >
                            <FolderOpen class="h-4 w-4" />
                            <span>Choose backup file…</span>
                        </Button>
                    </div>

                    <div class="grid gap-2">
                        <Label
                            for="backup-file-path"
                            class="text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                        >
                            Backup File Path
                        </Label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="backup-file-path"
                                v-model="typedPath"
                                type="text"
                                autocomplete="off"
                                placeholder="Paste or type path to .tar.gz / backup file"
                                class="flex-1 bg-background font-mono text-sm"
                            />
                            <Button
                                type="button"
                                variant="secondary"
                                class="shrink-0"
                                @click="checkTypedPath"
                            >
                                Check backup
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Recent Backups Card -->
        <Card class="border-border/60 shadow-xs">
            <CardHeader class="pb-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary"
                        >
                            <Clock class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium"
                                >Recent Backups</CardTitle
                            >
                            <CardDescription
                                >History of snapshots created on this
                                device.</CardDescription
                            >
                        </div>
                    </div>
                    <Badge variant="outline" class="font-mono text-xs">
                        {{ backups.length }} snapshot{{
                            backups.length === 1 ? '' : 's'
                        }}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent>
                <!-- Empty State -->
                <div
                    v-if="backups.length === 0"
                    class="flex flex-col items-center justify-center rounded-xl border border-dashed border-border/70 p-8 text-center"
                >
                    <div
                        class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-muted text-muted-foreground"
                    >
                        <FileArchive class="h-5 w-5" />
                    </div>
                    <p class="text-sm font-medium text-foreground">
                        No backups found
                    </p>
                    <p class="mt-0.5 max-w-sm text-xs text-muted-foreground">
                        Use the "Back up all vaults" button above to generate a
                        complete safety archive of your markdown vault.
                    </p>
                </div>

                <!-- Backups List -->
                <div v-else class="space-y-3">
                    <div
                        v-for="record in backups"
                        :key="record.uuid"
                        class="flex flex-col justify-between gap-4 rounded-xl border border-border/50 bg-muted/20 p-4 transition-colors hover:bg-muted/30 sm:flex-row sm:items-center"
                    >
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <div class="flex flex-wrap items-center gap-2">
                                <span
                                    class="text-sm font-medium text-foreground"
                                >
                                    {{
                                        new Date(
                                            record.created_at,
                                        ).toLocaleString()
                                    }}
                                </span>
                                <Badge variant="secondary" class="text-xs">
                                    {{
                                        record.scope === 'all'
                                            ? 'All vaults'
                                            : record.vaults.join(', ')
                                    }}
                                </Badge>
                                <Badge
                                    v-if="!record.exists"
                                    variant="destructive"
                                    class="text-xs"
                                >
                                    Missing
                                </Badge>
                            </div>

                            <p class="text-xs text-muted-foreground">
                                {{ record.note_count }} notes &middot;
                                {{ formatBytes(record.file_size) }}
                            </p>

                            <p
                                class="truncate font-mono text-[11px] break-all text-muted-foreground/80"
                            >
                                {{ record.path }}
                            </p>
                        </div>

                        <div class="shrink-0 self-end sm:self-center">
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                :disabled="!record.exists"
                                class="gap-1.5"
                                @click="restoreBackup(record)"
                            >
                                <RotateCcw class="h-3.5 w-3.5" />
                                <span>Restore…</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Restore Backup Modal -->
        <RestoreBackupDialog v-model:open="dialogOpen" :path="dialogPath" />

        <!-- Danger Zone: Reset Database -->
        <Card class="border-destructive/30 bg-destructive/[0.02] shadow-xs">
            <CardHeader class="pb-3">
                <div class="flex items-center gap-2.5">
                    <div
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-destructive/10 text-destructive"
                    >
                        <Trash2 class="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle
                            class="text-base font-medium text-destructive"
                            >Reset Database</CardTitle
                        >
                        <CardDescription>
                            Clear MDVault's internal registry of vaults and
                            notes so you can restore a backup from scratch.
                            Files on disk are never deleted.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent>
                <ResetDatabaseDialog />
            </CardContent>
        </Card>
    </div>
</template>
