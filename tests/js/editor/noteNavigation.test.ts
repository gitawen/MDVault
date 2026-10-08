import { describe, expect, it } from 'vite-plus/test';
import {
    notifyNoteSelected,
    useNoteNavigation,
} from '../../../resources/js/composables/useNoteNavigation';

describe('useNoteNavigation', () => {
    it('increments noteSelectionTick when notifyNoteSelected is called', () => {
        const { noteSelectionTick } = useNoteNavigation();
        const initialTick = noteSelectionTick.value;

        notifyNoteSelected();
        expect(noteSelectionTick.value).toBe(initialTick + 1);

        notifyNoteSelected();
        expect(noteSelectionTick.value).toBe(initialTick + 2);
    });
});
