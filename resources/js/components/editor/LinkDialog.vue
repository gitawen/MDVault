<script setup lang="ts">
import type { EditorView } from '@codemirror/view';
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
import {
    insertLink,
    linkAt,
    removeLink,
} from '@/lib/editor/codemirrorCommands';
import { isAllowedHref } from '@/lib/editor/linkProtocols';

const props = defineProps<{
    view?: EditorView | undefined;
    initialHref?: string;
}>();

const emit = defineEmits<{
    apply: [href: string];
    remove: [];
}>();

const open = defineModel<boolean>('open', { default: false });

const href = ref('');
const error = ref<string | null>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        href.value = props.initialHref ?? '';
        error.value = null;
    }
});

function apply(): void {
    const trimmed = href.value.trim();
    const active = props.view;
    error.value = null;

    if (!active) {
        open.value = false;
        return;
    }

    if (trimmed === '') {
        removeLink(active);
    } else {
        if (!isAllowedHref(trimmed)) {
            error.value = "That link type isn't allowed.";
            return;
        }

        const existing = linkAt(active.state, active.state.selection.main.head);
        if (existing) {
            insertLink(active, trimmed, existing.text, {
                from: existing.from,
                to: existing.to,
            });
        } else {
            insertLink(active, trimmed);
        }
    }

    emit('apply', trimmed);
    open.value = false;
}

function remove(): void {
    const active = props.view;

    if (active) {
        removeLink(active);
    }

    emit('remove');
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
