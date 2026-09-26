<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    root_path: props.storage.root_path,
});

const page = usePage();
const browsing = ref(false);

function save() {
    form.patch(update.url(), {
        preserveScroll: true,
        onSuccess: () => form.defaults({ root_path: form.root_path }),
    });
}

function chooseFolder() {
    browsing.value = true;

    router.post(
        browse.url(),
        {},
        {
            preserveScroll: true,
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
                <Label for="root_path">Folder</Label>
                <Input
                    id="root_path"
                    v-model="form.root_path"
                    type="text"
                    autocomplete="off"
                />
                <InputError
                    :message="
                        form.errors.root_path ?? page.props.errors.root_path
                    "
                />
            </div>

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
                    Changing this does not move existing files. The folder is
                    created if it doesn't exist.
                </p>
            </div>
        </div>
    </div>
</template>
