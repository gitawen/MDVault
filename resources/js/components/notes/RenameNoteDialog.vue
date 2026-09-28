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
import { update } from '@/routes/notes';
import type { NoteTreeNote } from '@/types';

const props = defineProps<{
    note: NoteTreeNote | null;
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    name: props.note?.title ?? '',
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.name = props.note?.title ?? '';
        form.clearErrors();
    }
});

function submit() {
    if (!props.note) {
        return;
    }

    form.submit(update(props.note.uuid), {
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
                <DialogTitle>Rename note</DialogTitle>
                <DialogDescription>
                    Renaming &#8220;{{ note.title }}&#8221;.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="rename-note-name">Name</Label>
                    <Input
                        id="rename-note-name"
                        v-model="form.name"
                        type="text"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        Save
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
