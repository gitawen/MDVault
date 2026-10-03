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

const indentSize = computed(() => preferences.indent_size ?? 4);
const indentSpaces = computed(() => ' '.repeat(indentSize.value));

function handleKeyDown(_view: EditorView, event: KeyboardEvent): boolean {
    if (event.key !== 'Tab') {
        return false;
    }

    if (!editable) {
        return false;
    }

    // Always prevent default to prevent tabbing focus away from the editor
    event.preventDefault();

    if (!editor.value) {
        return true;
    }

    const ed = editor.value;
    const indentStr = indentSpaces.value;
    const size = indentSize.value;

    if (!event.shiftKey) {
        // Tab (Increase indent)
        if (ed.can().goToNextCell()) {
            return ed.commands.goToNextCell();
        }

        if (ed.can().sinkListItem('listItem')) {
            return ed.commands.sinkListItem('listItem');
        }

        if (ed.can().sinkListItem('taskItem')) {
            return ed.commands.sinkListItem('taskItem');
        }

        return ed.commands.command(({ tr, dispatch }) => {
            dispatch?.(tr.insertText(indentStr));
            return true;
        });
    } else {
        // Shift-Tab (Decrease indent / lift)
        if (ed.can().goToPreviousCell()) {
            return ed.commands.goToPreviousCell();
        }

        if (ed.can().liftListItem('listItem')) {
            return ed.commands.liftListItem('listItem');
        }

        if (ed.can().liftListItem('taskItem')) {
            return ed.commands.liftListItem('taskItem');
        }

        const { state } = ed;
        const { from, empty } = state.selection;
        if (empty && from > 0) {
            const lookback = Math.min(from, size);
            const textBefore = state.doc.textBetween(
                from - lookback,
                from,
                '\n',
                '\n',
            );
            if (textBefore === ' '.repeat(lookback)) {
                return ed.commands.deleteRange({ from: from - lookback, to: from });
            } else if (textBefore.endsWith(' ')) {
                let spacesCount = 0;
                while (spacesCount < lookback && textBefore[textBefore.length - 1 - spacesCount] === ' ') {
                    spacesCount++;
                }
                if (spacesCount > 0) {
                    return ed.commands.deleteRange({ from: from - spacesCount, to: from });
                }
            }
        }

        return true;
    }
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
        handleKeyDown,
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
    <div
        class="flex min-h-0 flex-1 flex-col rounded-xl border border-border/80 bg-card shadow-xs overflow-hidden transition-all duration-200 focus-within:border-primary/40 focus-within:ring-1 focus-within:ring-primary/20"
    >
        <EditorToolbar v-if="editable" :editor="editor" />
        <div
            class="min-h-0 flex-1 overflow-auto p-3 sm:p-5 md:p-6 cursor-text"
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
