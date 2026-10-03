<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    CheckCircle2,
    Folder,
    FolderOpen,
    HardDrive,
    Info,
    RotateCcw,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DirectoryBrowserDialog from '@/components/DirectoryBrowserDialog.vue';
import InputError from '@/components/InputError.vue';
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
import { browse, destroy, edit, update } from '@/routes/settings/storage';
import type { StorageSettings } from '@/types';

const props = defineProps<{
    storage: StorageSettings;
    canBrowse: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Storage settings',
                href: edit(),
            },
        ],
    },
});

const form = useForm({
    location: props.storage.location,
    folder_name: props.storage.folder_name,
});

const page = usePage();
const browsing = ref(false);
const browserOpen = ref(false);

// The location is server-derived (Save, reset to default), so keep it in
// sync whenever it actually changes there. Choosing a folder does NOT change
// it server-side any more (see chooseFolder() below) - it only fills the
// field locally, so it never triggers this watcher. The folder name is
// deliberately NOT resynced from props here: it is the single source of
// truth the user types, and is only ever re-baselined (see save() below)
// after a request that itself submitted it.
watch(
    () => props.storage.location,
    (location) => {
        form.defaults('location', location);
        form.reset('location');
        form.clearErrors('location');
    },
);

function separatorOf(path: string): string {
    return path.includes('\\') ? '\\' : '/';
}

function withoutTrailingSeparators(path: string): string {
    return path.replace(/[\\/]+$/, '');
}

function basenameOf(path: string): string {
    const trimmed = withoutTrailingSeparators(path);
    const index = Math.max(trimmed.lastIndexOf('\\'), trimmed.lastIndexOf('/'));

    return index === -1 ? trimmed : trimmed.slice(index + 1);
}

// Mirrors the server's rule: the folder name is not appended when the
// chosen location's own basename already matches it (case-insensitively).
// The folder name field is the single source of truth - an empty value
// means there is nothing to preview yet (it is required to save).
const previewPath = computed(() => {
    const location = form.location.trim();
    const folderName = form.folder_name.trim();

    if (location === '' || folderName === '') {
        return '';
    }

    const trimmedLocation = withoutTrailingSeparators(location);

    if (
        basenameOf(trimmedLocation).toLowerCase() === folderName.toLowerCase()
    ) {
        return trimmedLocation;
    }

    return `${trimmedLocation}${separatorOf(location)}${folderName}`;
});

function save() {
    form.patch(update.url(), {
        preserveScroll: true,
        onSuccess: () =>
            form.defaults({
                location: form.location,
                folder_name: form.folder_name,
            }),
    });
}

type PickedLocationFlash = { pickedLocation?: { location: string } };

// Picking a folder only fills the location field - it creates nothing on
// disk and writes no setting. The user must still click Save to apply it;
// cancelling the picker (no `pickedLocation` flash) changes nothing.
function chooseFolder() {
    browsing.value = true;

    router.post(
        browse.url(),
        {},
        {
            preserveState: true,
            preserveScroll: true,
            onFlash: (flash) => {
                const pickedLocation = (flash as PickedLocationFlash)
                    ?.pickedLocation;

                if (pickedLocation) {
                    form.location = pickedLocation.location;
                }
            },
            onFinish: () => {
                browsing.value = false;
            },
        },
    );
}

function onChooseFolderClick() {
    if (props.canBrowse) {
        chooseFolder();
    } else {
        browserOpen.value = true;
    }
}

function onLocationSelected(path: string) {
    form.location = path;
}

function useDefaultLocation() {
    router.delete(destroy.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Storage settings" />

        <h1 class="sr-only">Storage settings</h1>

        <!-- Section Header -->
        <div class="space-y-1">
            <h2 class="text-xl font-semibold tracking-tight">Storage Location</h2>
            <p class="text-sm text-muted-foreground">
                Designate where MDVault writes new vaults and organizes internal document directories.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="save">
            <!-- Path Configuration Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-4">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <HardDrive class="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle class="text-base font-medium">Root Storage Paths</CardTitle>
                            <CardDescription>
                                Set the base directory and root vault subfolder name.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-5">
                    <!-- Base Location Field -->
                    <div class="grid gap-2">
                        <Label for="location" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Base Directory
                        </Label>
                        <div class="flex flex-col gap-2 sm:flex-row">
                            <div class="relative flex-1">
                                <Input
                                    id="location"
                                    v-model="form.location"
                                    type="text"
                                    autocomplete="off"
                                    class="bg-background pr-9 font-mono text-sm"
                                    placeholder="C:\Users\..."
                                />
                                <Folder class="pointer-events-none absolute right-3 top-2.5 h-4 w-4 text-muted-foreground" />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="browsing"
                                class="shrink-0 gap-1.5"
                                @click="onChooseFolderClick"
                            >
                                <FolderOpen class="h-4 w-4" />
                                <span>Choose folder…</span>
                            </Button>
                        </div>
                        <InputError
                            :message="form.errors.location ?? page.props.errors.location"
                        />
                    </div>

                    <!-- Folder Name Field -->
                    <div class="grid gap-2 sm:max-w-md">
                        <Label for="folder_name" class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            Subfolder Name
                        </Label>
                        <Input
                            id="folder_name"
                            v-model="form.folder_name"
                            type="text"
                            autocomplete="off"
                            placeholder="MDVault"
                            class="bg-background font-mono text-sm"
                        />
                        <InputError
                            :message="form.errors.folder_name ?? page.props.errors.folder_name"
                        />
                    </div>

                    <!-- Resolved Path Banner -->
                    <div class="rounded-xl border border-border/60 bg-muted/30 p-4 shadow-2xs">
                        <div class="flex items-center justify-between gap-2 mb-1.5">
                            <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                Active Vaults Destination
                            </span>
                            <Badge variant="outline" class="font-mono text-[11px]">
                                Auto-resolved
                            </Badge>
                        </div>
                        <p class="font-mono text-sm font-semibold tracking-tight text-foreground break-all">
                            {{ previewPath || '—' }}
                        </p>
                    </div>
                </CardContent>
            </Card>

            <!-- Directory Diagnostics & Defaults Card -->
            <Card class="border-border/60 shadow-xs">
                <CardHeader class="pb-3">
                    <CardTitle class="text-base font-medium">Directory Health & Defaults</CardTitle>
                    <CardDescription>Status check on selected filesystem target.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            v-if="storage.exists"
                            variant="secondary"
                            class="gap-1.5 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border border-emerald-500/20"
                        >
                            <CheckCircle2 class="h-3.5 w-3.5" />
                            Folder exists on disk
                        </Badge>
                        <Badge v-else variant="outline" class="gap-1.5 text-muted-foreground">
                            <Info class="h-3.5 w-3.5" />
                            Will be created when needed
                        </Badge>

                        <Badge
                            v-if="storage.exists && !storage.writable"
                            variant="destructive"
                            class="gap-1.5"
                        >
                            <AlertTriangle class="h-3.5 w-3.5" />
                            Write permission denied
                        </Badge>
                    </div>

                    <!-- Default Path Details -->
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 rounded-lg border border-border/40 bg-muted/20 p-3 text-xs">
                        <div class="space-y-0.5">
                            <span class="font-medium text-foreground">System Default:</span>
                            <p class="font-mono text-muted-foreground break-all">
                                {{ storage.default_path }}
                            </p>
                        </div>
                        <Button
                            v-if="!storage.is_default"
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="shrink-0 gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                            @click="useDefaultLocation"
                        >
                            <RotateCcw class="h-3 w-3" />
                            Reset to default
                        </Button>
                    </div>

                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Changing storage location does not automatically move existing vault directories on disk.
                        The folder specified above is created inside your chosen directory unless the location you select is already named that.
                    </p>
                </CardContent>
            </Card>

            <!-- Bottom Action Bar -->
            <div class="flex items-center justify-between border-t border-border/60 pt-4">
                <p class="text-xs text-muted-foreground">
                    Directory changes will apply to all subsequent new vaults.
                </p>
                <Button type="submit" :disabled="form.processing" class="min-w-28 gap-1.5">
                    <Check v-if="!form.processing" class="h-4 w-4" />
                    <span>Save Location</span>
                </Button>
            </div>
        </form>

        <DirectoryBrowserDialog
            v-model:open="browserOpen"
            title="Choose storage location"
            description="Select the parent directory where your vaults will be stored."
            :initial-path="form.location || null"
            @select="onLocationSelected"
        />
    </div>
</template>
