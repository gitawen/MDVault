<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { browse, destroy, edit, update } from '@/routes/settings/storage';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

function useDefaultLocation() {
    router.delete(destroy.url(), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <Head title="Storage settings" />

        <h1 class="sr-only">Storage settings</h1>

        <Heading
            variant="small"
            title="Storage location"
            description="Where MDVault creates new vaults."
        />

        <div class="space-y-4">
            <div class="grid gap-2">
                <Label for="location">Location</Label>
                <Input
                    id="location"
                    v-model="form.location"
                    type="text"
                    autocomplete="off"
                />
                <InputError
                    :message="
                        form.errors.location ?? page.props.errors.location
                    "
                />
            </div>

            <div class="grid gap-2">
                <Label for="folder_name">Folder name</Label>
                <Input
                    id="folder_name"
                    v-model="form.folder_name"
                    type="text"
                    autocomplete="off"
                    placeholder="MDVault"
                />
                <InputError
                    :message="
                        form.errors.folder_name ?? page.props.errors.folder_name
                    "
                />
            </div>

            <p class="text-sm text-muted-foreground">
                Vaults will be stored in:
                <span class="font-medium text-foreground">{{
                    previewPath || '—'
                }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-2">
                <Button type="button" :disabled="form.processing" @click="save">
                    Save
                </Button>
                <Button
                    v-if="canBrowse"
                    type="button"
                    variant="outline"
                    :disabled="browsing"
                    @click="chooseFolder"
                >
                    Choose folder…
                </Button>
                <Button
                    v-if="!storage.is_default"
                    type="button"
                    variant="ghost"
                    @click="useDefaultLocation"
                >
                    Use default location
                </Button>
            </div>

            <div class="space-y-1 text-sm text-muted-foreground">
                <p>Default: {{ storage.default_path }}</p>
                <div class="flex items-center gap-2">
                    <Badge v-if="storage.exists" variant="secondary">
                        Folder exists
                    </Badge>
                    <Badge v-else variant="outline">
                        Will be created when needed
                    </Badge>
                    <span
                        v-if="storage.exists && !storage.writable"
                        class="text-destructive"
                    >
                        MDVault cannot write to this folder.
                    </span>
                </div>
                <p>
                    Changing this does not move existing files. The folder named
                    above is created inside the location you choose, unless the
                    location you choose is already named that.
                </p>
            </div>
        </div>
    </div>
</template>
