<script setup lang="ts">
import type { Editor } from '@tiptap/core';
import { ref, watch } from 'vue';
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

const props = defineProps<{
    editor: Editor | undefined;
    initialHref: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const href = ref('');
const error = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        href.value = props.initialHref;
        error.value = null;
    }
});

function apply(): void {
    if (!props.editor) {
        return;
    }

    error.value = null;
    const chain = props.editor.chain().focus().extendMarkRange('link');
    const trimmed = href.value.trim();

    const applied =
        trimmed === ''
            ? chain.unsetLink().run()
            : chain.setLink({ href: trimmed }).run();

    if (!applied) {
        error.value = "That link type isn't allowed.";

        return;
    }

    open.value = false;
}

function remove(): void {
    if (!props.editor) {
        return;
    }

    props.editor.chain().focus().extendMarkRange('link').unsetLink().run();
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Link</DialogTitle>
                <DialogDescription>
                    Add, edit or remove a link on the selected text.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label for="link-href">URL</Label>
                <Input
                    id="link-href"
                    v-model="href"
                    type="url"
                    placeholder="https://example.com"
                    @keydown.enter.prevent="apply"
                />
                <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
            </div>

            <DialogFooter>
                <Button type="button" variant="outline" @click="remove">
                    Remove
                </Button>
                <Button type="button" @click="apply">Apply</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
