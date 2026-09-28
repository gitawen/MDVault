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
import { destroy } from '@/routes/vaults/folders';
import type { NoteTreeFolder } from '@/types';

const props = defineProps<{
    vaultUuid: string;
    folder: NoteTreeFolder | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    path: props.folder?.path ?? '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.path = props.folder?.path ?? '';
        form.clearErrors();
    }
});

function submit() {
    form.submit(destroy(props.vaultUuid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="folder">
            <DialogHeader>
                <DialogTitle>Delete folder</DialogTitle>
                <DialogDescription>
                    Delete the empty folder &#8220;{{ folder.path }}&#8221;?
                    Folders that still contain files can&#8217;t be deleted
                    here.
                </DialogDescription>
            </DialogHeader>

            <InputError :message="form.errors.path" />

            <DialogFooter>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing"
                    @click="submit"
                >
                    Delete folder
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
