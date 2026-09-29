import type { Editor } from '@tiptap/core';
import { ref, watch, type Ref } from 'vue';

/**
 * Forces the calling component to re-render on every editor transaction.
 * `useEditor()`'s ref is a `shallowRef` that Tiptap never mutates
 * reactively (selection and mark changes happen inside the editor
 * instance, not on the ref itself), so toolbar state derived from
 * `editor.isActive()`/`editor.can()` would otherwise only refresh when an
 * unrelated prop changed. Read the returned ref's `.value` from anywhere
 * that should react to a transaction.
 */
export function useEditorTick(
    getEditor: () => Editor | undefined,
): Ref<number> {
    const tick = ref(0);

    watch(
        getEditor,
        (editor, _previous, onCleanup) => {
            if (!editor) {
                return;
            }

            const bump = (): void => {
                tick.value++;
            };

            editor.on('transaction', bump);

            onCleanup(() => {
                editor.off('transaction', bump);
            });
        },
        { immediate: true },
    );

    return tick;
}
