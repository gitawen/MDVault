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
import { destroy } from '@/routes/notes';
import type { NoteTreeNote } from '@/types';

const props = defineProps<{
    note: NoteTreeNote | null;
    canTrash: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({});

watch(open, (isOpen) => {
    if (isOpen) {
        form.clearErrors();
    }
});

function submit() {
    if (!props.note) {
        return;
    }

    form.submit(destroy(props.note.uuid), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="note">
            <DialogHeader>
                <DialogTitle>Delete note</DialogTitle>
                <DialogDescription>
                    Move &#8220;{{ note.title }}&#8221; to the Recycle Bin /
                    Trash? You can restore it from there.
                </DialogDescription>
            </DialogHeader>

            <p v-if="!canTrash" class="text-sm text-muted-foreground">
                Deleting notes is only available in the desktop app.
            </p>
            <InputError
                :message="(form.errors as Record<string, string>).note"
            />

            <DialogFooter>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="form.processing || !canTrash"
                    @click="submit"
                >
                    Move to Trash
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
