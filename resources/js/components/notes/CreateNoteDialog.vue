<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store } from '@/routes/vaults/notes';

const ROOT = '__root__';

const props = defineProps<{
    vaultUuid: string;
    folders: string[];
    defaultFolder: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    folder: props.defaultFolder === '' ? ROOT : props.defaultFolder,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.folder = props.defaultFolder === '' ? ROOT : props.defaultFolder;
        form.clearErrors();
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        folder: data.folder === ROOT ? '' : data.folder,
    })).submit(store(props.vaultUuid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>New note</DialogTitle>
                <DialogDescription>
                    &#8220;.md&#8221; is added for you.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="create-note-name">Name</Label>
                    <Input
                        id="create-note-name"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-note-folder">Folder</Label>
                    <Select v-model="form.folder">
                        <SelectTrigger id="create-note-folder">
                            <SelectValue placeholder="Choose a folder" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ROOT">Top level</SelectItem>
                            <SelectItem
                                v-for="folder in props.folders.filter(
                                    (f) => f !== '',
                                )"
                                :key="folder"
                                :value="folder"
                            >
                                {{ folder }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.folder" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Create note
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
