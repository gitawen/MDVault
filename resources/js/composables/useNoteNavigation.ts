import { ref } from 'vue';

const noteSelectionTick = ref(0);

export function notifyNoteSelected(): void {
    noteSelectionTick.value++;
}

export function useNoteNavigation() {
    return {
        noteSelectionTick,
        notifyNoteSelected,
    };
}

export { noteSelectionTick };
