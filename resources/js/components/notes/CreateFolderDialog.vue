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
import { store } from '@/routes/vaults/folders';

const ROOT = '__root__';

const props = defineProps<{
    vaultUuid: string;
    folders: string[];
    defaultParent: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: '',
    parent: props.defaultParent === '' ? ROOT : props.defaultParent,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.parent = props.defaultParent === '' ? ROOT : props.defaultParent;
        form.clearErrors();
    }
});

function submit() {
    form.transform((data) => ({
        ...data,
        parent: data.parent === ROOT ? '' : data.parent,
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
                <DialogTitle>New folder</DialogTitle>
                <DialogDescription>
                    Creates a new, empty folder inside the vault.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="create-folder-name">Name</Label>
                    <Input
                        id="create-folder-name"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="create-folder-parent">Parent folder</Label>
                    <Select v-model="form.parent">
                        <SelectTrigger id="create-folder-parent">
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
                    <InputError :message="form.errors.parent" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Create folder
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
