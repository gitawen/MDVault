import { Editor } from '@tiptap/core';
import { describe, expect, it } from 'vite-plus/test';
import { markdownExtensions } from '../../../resources/js/lib/markdown/extensions';

describe('Tab indentation logic', () => {
    it('sinks a bullet list item when indented with sinkListItem', () => {
        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: '- Item 1\n- Item 2',
            contentType: 'markdown',
        });

        // Place cursor at Item 2
        editor.commands.setTextSelection(12);

        expect(editor.can().sinkListItem('listItem')).toBe(true);
        editor.commands.sinkListItem('listItem');

        const markdown = editor.getMarkdown();
        expect(markdown).toContain('  - Item 2');

        // Test lifting with liftListItem
        expect(editor.can().liftListItem('listItem')).toBe(true);
        editor.commands.liftListItem('listItem');
        expect(editor.getMarkdown()).not.toContain('  - Item 2');

        editor.destroy();
    });

    it('sinks a task list item when indented with sinkListItem', () => {
        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: '- [ ] Task 1\n- [ ] Task 2',
            contentType: 'markdown',
        });

        // Place cursor at Task 2
        editor.commands.setTextSelection(15);

        expect(editor.can().sinkListItem('taskItem')).toBe(true);
        editor.commands.sinkListItem('taskItem');

        const markdown = editor.getMarkdown();
        expect(markdown).toContain('  - [ ] Task 2');

        // Test lifting with liftListItem
        expect(editor.can().liftListItem('taskItem')).toBe(true);
        editor.commands.liftListItem('taskItem');
        expect(editor.getMarkdown()).not.toContain('  - [ ] Task 2');

        editor.destroy();
    });

    it('inserts two spaces when tabbing in paragraph text', () => {
        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: 'Hello world',
            contentType: 'markdown',
        });

        editor.commands.setTextSelection(1);
        editor.commands.command(({ tr, dispatch }) => {
            if (dispatch) {
                tr.insertText('  ');
            }
            return true;
        });

        expect(editor.getMarkdown()).toBe('  Hello world');

        editor.destroy();
    });
});
