<script setup lang="ts">
import type { EditorView } from '@tiptap/pm/view';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { computed, watch } from 'vue';
import EditorToolbar from '@/components/editor/EditorToolbar.vue';
import { markdownExtensions, serializeEditor } from '@/lib/markdown/extensions';
import type { EditorPreferences } from '@/types';

const {
    markdown,
    editable = true,
    preferences,
} = defineProps<{
    markdown: string;
    editable?: boolean;
    preferences: EditorPreferences;
}>();

const emit = defineEmits<{
    ready: [markdown: string];
    change: [];
}>();

// Links are inert in the schema (`openOnClick: false` in extensions.ts, so a
// plain click always places the caret instead of hijacking navigation) and
// opened here instead: a click in the read-only view, or a Ctrl/Cmd+click
// while editing, standard editor behaviour so a plain click can still select
// or position the caret. Restricted to http(s)/mailto so no other scheme
// (e.g. `javascript:`) can be triggered this way.
const ALLOWED_LINK_PROTOCOLS = new Set(['http:', 'https:', 'mailto:']);

function allowedLinkHref(anchor: HTMLAnchorElement): string | null {
    let url: URL;

    try {
        url = new URL(anchor.href);
    } catch {
        return null;
    }

    return ALLOWED_LINK_PROTOCOLS.has(url.protocol) ? anchor.href : null;
}

function handleContentClick(
    view: EditorView,
    _pos: number,
    event: MouseEvent,
): boolean {
    if (event.button !== 0) {
        return false;
    }

    if (view.editable && !(event.ctrlKey || event.metaKey)) {
        return false;
    }

    const anchor = (event.target as HTMLElement | null)?.closest('a[href]');

    if (!(anchor instanceof HTMLAnchorElement)) {
        return false;
    }

    const href = allowedLinkHref(anchor);

    if (!href) {
        return false;
    }

    event.preventDefault();
    window.open(href, '_blank', 'noopener,noreferrer');

    return true;
}

const editor = useEditor({
    extensions: markdownExtensions(),
    content: markdown,
    contentType: 'markdown',
    editable,
    editorProps: {
        attributes: {
            class: 'tiptap-content',
            'aria-label': 'Note editor',
        },
        handleClick: handleContentClick,
    },
    onCreate: ({ editor: created }) => {
        emit('ready', serializeEditor(created));
    },
    onUpdate: () => {
        emit('change');
    },
});

watch(
    () => editable,
    (value) => {
        editor.value?.setEditable(value);
    },
);

const fontFamilyClass = computed(() => {
    switch (preferences.font_family) {
        case 'serif':
            return 'font-serif';
        case 'mono':
            return 'font-mono';
        default:
            return 'font-sans';
    }
});

const style = computed(() => ({
    fontSize: `${preferences.font_size}px`,
    lineHeight: String(preferences.line_height),
}));

function getContent(): string {
    return editor.value ? serializeEditor(editor.value) : '';
}

defineExpose({ getContent });
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col rounded-lg border bg-card">
        <EditorToolbar v-if="editable" :editor="editor" />
        <div
            class="min-h-0 flex-1 overflow-auto p-4"
            :class="[
                fontFamilyClass,
                { 'tiptap-nowrap': preferences.word_wrap === false },
            ]"
            :style="style"
        >
            <EditorContent :editor="editor" />
        </div>
    </div>
</template>
