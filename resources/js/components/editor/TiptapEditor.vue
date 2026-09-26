<script setup lang="ts">
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import { computed } from 'vue';
import type { EditorPreferences } from '@/types';

const {
    content,
    editable = true,
    preferences,
} = defineProps<{
    content: string;
    editable?: boolean;
    preferences?: EditorPreferences;
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

const fontFamilyClass = computed(() => {
    switch (preferences?.font_family) {
        case 'serif':
            return 'font-serif';
        case 'mono':
            return 'font-mono';
        default:
            return 'font-sans';
    }
});

const style = computed(() =>
    preferences
        ? {
              fontSize: `${preferences.font_size}px`,
              lineHeight: String(preferences.line_height),
          }
        : undefined,
);
</script>

<template>
    <div
        class="rounded-lg border bg-card p-4"
        :class="[
            fontFamilyClass,
            { 'tiptap-nowrap': preferences?.word_wrap === false },
        ]"
        :style="style"
    >
        <EditorContent :editor="editor" />
    </div>
</template>
