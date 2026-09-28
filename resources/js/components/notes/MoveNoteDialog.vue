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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { move } from '@/routes/notes';
import type { NoteTreeNote } from '@/types';

const ROOT = '__root__';

const props = defineProps<{
    note: NoteTreeNote | null;
    folders: string[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    folder: ROOT,
});

watch(open, (isOpen) => {
    if (isOpen) {
        form.folder = ROOT;
        form.clearErrors();
    }
});

function submit() {
    if (!props.note) {
        return;
    }

    form.transform((data) => ({
        ...data,
        folder: data.folder === ROOT ? '' : data.folder,
    })).submit(move(props.note.uuid), {
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
                <DialogTitle>Move note</DialogTitle>
                <DialogDescription>
                    Move &#8220;{{ note.title }}&#8221; to another folder.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="move-note-folder">Folder</Label>
                    <Select v-model="form.folder">
                        <SelectTrigger id="move-note-folder">
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
                        Move
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
