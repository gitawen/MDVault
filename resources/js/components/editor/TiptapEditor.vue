<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';

const { content, editable = true } = defineProps<{
    content: string;
    editable?: boolean;
}>();

const editor = useEditor({
    content,
    editable,
    extensions: [StarterKit],
    editorProps: {
        attributes: {
            class: 'tiptap-content',
            'aria-label': 'Note editor',
        },
    },
});
</script>

<template>
    <div class="rounded-lg border bg-card p-4">
        <EditorContent :editor="editor" />
    </div>
</template>
