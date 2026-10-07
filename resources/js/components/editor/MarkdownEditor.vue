<script setup lang="ts">
import { computed, nextTick } from 'vue';
import type { EditorPreferences } from '@/types';

const {
    editable = true,
    readonly = false,
    preferences,
} = defineProps<{
    editable?: boolean;
    /** Forces the textarea read-only regardless of `editable` (R2-01: frozen during a guarded navigation). */
    readonly?: boolean;
    preferences: EditorPreferences;
}>();

const emit = defineEmits<{
    change: [];
}>();

const text = defineModel<string>({ default: '' });

const indentString = computed(() => ' '.repeat(preferences.indent_size ?? 4));

function onKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Tab') {
        return;
    }

    if (!editable || readonly) {
        return;
    }

    event.preventDefault();

    const textarea = event.target as HTMLTextAreaElement;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const val = text.value;
    const indentStr = indentString.value;

    if (!event.shiftKey) {
        // Tab: Increase indent
        if (start === end) {
            // Single cursor: insert indent spaces
            text.value = val.substring(0, start) + indentStr + val.substring(end);
            emit('change');
            void nextTick(() => {
                textarea.selectionStart = textarea.selectionEnd = start + indentStr.length;
            });
        } else {
            // Multi-line selection: indent all selected lines
            const lineStart = val.lastIndexOf('\n', start - 1) + 1;
            const lineEndIndex = val.indexOf('\n', end);
            const lineEnd = lineEndIndex === -1 ? val.length : lineEndIndex;

            const selectedBlock = val.substring(lineStart, lineEnd);
            const lines = selectedBlock.split('\n');
            const indented = lines.map((line) => indentStr + line).join('\n');

            text.value = val.substring(0, lineStart) + indented + val.substring(lineEnd);
            emit('change');
            void nextTick(() => {
                textarea.selectionStart = start + indentStr.length;
                textarea.selectionEnd = end + indentStr.length * lines.length;
            });
        }
    } else {
        // Shift-Tab: Decrease indent (outdent)
        const lineStart = val.lastIndexOf('\n', start - 1) + 1;
        const lineEndIndex = val.indexOf('\n', end);
        const lineEnd = lineEndIndex === -1 ? val.length : lineEndIndex;

        const selectedBlock = val.substring(lineStart, lineEnd);
        const lines = selectedBlock.split('\n');
        let removedFromFirstLine = 0;
        let totalRemoved = 0;

        const unindented = lines
            .map((line, idx) => {
                let removeCount = 0;
                if (line.startsWith(indentStr)) {
                    removeCount = indentStr.length;
                } else if (line.startsWith('\t')) {
                    removeCount = 1;
                } else if (line.startsWith(' ')) {
                    let spaces = 0;
                    while (spaces < indentStr.length && line[spaces] === ' ') {
                        spaces++;
                    }
                    removeCount = spaces;
                }

                if (idx === 0) {
                    removedFromFirstLine = removeCount;
                }
                totalRemoved += removeCount;

                return line.substring(removeCount);
            })
            .join('\n');

        text.value = val.substring(0, lineStart) + unindented + val.substring(lineEnd);
        emit('change');
        void nextTick(() => {
            textarea.selectionStart = Math.max(lineStart, start - removedFromFirstLine);
            textarea.selectionEnd = Math.max(lineStart, end - totalRemoved);
        });
    }
}

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

function onInput(event: Event): void {
    text.value = (event.target as HTMLTextAreaElement).value;
    emit('change');
}

function getText(): string {
    return text.value;
}

defineExpose({ getText });
</script>

<template>
    <div
        class="flex min-h-0 flex-1 flex-col rounded-xl border border-border/80 bg-card shadow-xs overflow-hidden transition-all duration-200 focus-within:border-primary/40 focus-within:ring-1 focus-within:ring-primary/20"
    >
        <textarea
            :value="text"
            :readonly="!editable || readonly"
            spellcheck="false"
            :wrap="preferences.word_wrap === false ? 'off' : 'soft'"
            class="h-full w-full flex-1 resize-none bg-transparent p-3 sm:p-5 md:p-6 font-mono outline-none"
            :class="[
                fontFamilyClass,
                { 'whitespace-pre': preferences.word_wrap === false },
            ]"
            :style="style"
            aria-label="Note source"
            @input="onInput"
            @keydown="onKeydown"
        />
    </div>
</template>
