import { Editor } from '@tiptap/core';
import { describe, expect, it } from 'vite-plus/test';
import { markdownExtensions } from '../../../resources/js/lib/markdown/extensions';
import {
    toolbarCommands,
    type ToolbarCommandId,
} from '../../../resources/js/lib/editor/toolbarCommands';

const HTML_TAG_PATTERN = /<[a-z][^>]*>/i;

const expectedMarkdown: Record<ToolbarCommandId, string> = {
    undo: 'para text',
    redo: 'para text',
    bold: '**para text**',
    italic: '*para text*',
    strike: '~~para text~~',
    code: '`para text`',
    paragraph: 'para text',
    h1: '# para text',
    h2: '## para text',
    h3: '### para text',
    bulletList: '- para text',
    orderedList: '1. para text',
    taskList: '- [ ] para text',
    blockquote: '> para text',
    codeBlock: '```\npara text\n```',
    horizontalRule: '---',
    clearFormatting: 'para text',
};

function editorWithSelectedText(): Editor {
    const editor = new Editor({
        element: null,
        extensions: markdownExtensions(),
        content: 'para text',
        contentType: 'markdown',
    });

    editor.commands.selectAll();

    return editor;
}

describe('toolbarCommands', () => {
    for (const [id, command] of Object.entries(toolbarCommands) as [
        ToolbarCommandId,
        (typeof toolbarCommands)[ToolbarCommandId],
    ][]) {
        it(`${id} produces the expected Markdown with no raw HTML`, () => {
            const editor = editorWithSelectedText();

            command.run(editor);
            const markdown = editor.getMarkdown().replace(/\n+$/, '');

            expect(markdown).toBe(expectedMarkdown[id]);
            expect(markdown).not.toMatch(HTML_TAG_PATTERN);

            editor.destroy();
        });
    }

    it('has no link command in the table (handled by LinkDialog)', () => {
        expect(Object.keys(toolbarCommands)).not.toContain('link');
    });

    it('setLink serialises a link around the selected text', () => {
        const editor = editorWithSelectedText();

        editor
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href: 'https://example.com' })
            .run();

        expect(editor.getMarkdown()).toBe('[para text](https://example.com)');

        editor.destroy();
    });

    it('rejects an unsafe protocol and creates no link', () => {
        const editor = editorWithSelectedText();

        editor
            .chain()
            .focus()
            .extendMarkRange('link')
            .setLink({ href: 'javascript:alert(1)' })
            .run();

        expect(editor.getMarkdown()).toBe('para text');

        editor.destroy();
    });
});
