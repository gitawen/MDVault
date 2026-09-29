<script setup lang="ts">
import { computed } from 'vue';
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
    <textarea
        :value="text"
        :readonly="!editable || readonly"
        spellcheck="false"
        :wrap="preferences.word_wrap === false ? 'off' : 'soft'"
        class="h-full w-full resize-none rounded-md border bg-card p-4 font-mono outline-none"
        :class="[
            fontFamilyClass,
            { 'whitespace-pre': preferences.word_wrap === false },
        ]"
        :style="style"
        aria-label="Note source"
        @input="onInput"
    />
</template>
