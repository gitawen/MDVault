import { history } from '@codemirror/commands';
import { EditorState, type TransactionSpec } from '@codemirror/state';
import { describe, expect, it } from 'vite-plus/test';
import type { CommandTarget } from '../../../resources/js/lib/editor/codemirrorCommands';
import {
    toolbarCommands,
    type ToolbarCommandId,
} from '../../../resources/js/lib/editor/toolbarCommands';

/**
 * Drives the single CodeMirror command table — this is the direct
 * regression test for FR-04/FR-05/FR-06 (Paragraph/Divider/Clear formatting
 * were a no-op, destructive, and a no-op respectively in the old TipTap
 * branch) and for FR-08/FR-11 (one code path, plus the new Table command).
 */
function createCodeMirrorTarget(
    initialText: string,
    from = 0,
    to = initialText.length,
): CommandTarget {
    let state = EditorState.create({
        doc: initialText,
        selection: { anchor: from, head: to },
    });
    return {
        get state() {
            return state;
        },
        dispatch(tr: TransactionSpec) {
            state = state.update(tr).state;
        },
    };
}

const expectedCodeMirrorMarkdown: Record<ToolbarCommandId, string> = {
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
    horizontalRule: 'para text\n\n---',
    clearFormatting: 'para text',
    table: 'para text\n\n| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |',
};

describe('toolbarCommands', () => {
    for (const [id, command] of Object.entries(toolbarCommands) as [
        ToolbarCommandId,
        (typeof toolbarCommands)[ToolbarCommandId],
    ][]) {
        it(`${id} produces the expected Markdown`, () => {
            const target = createCodeMirrorTarget('para text');

            command.run(target);
            const markdown = target.state.doc.toString().replace(/\n+$/, '');

            expect(markdown).toBe(expectedCodeMirrorMarkdown[id]);
        });
    }

    it('has no link command in the table (handled by LinkDialog)', () => {
        expect(Object.keys(toolbarCommands)).not.toContain('link');
    });

    it('undo/redo canRun is driven by history depth, not just editor presence (FR-02)', () => {
        let state = EditorState.create({
            doc: 'hello',
            extensions: [history()],
        });
        const target: CommandTarget = {
            get state() {
                return state;
            },
            dispatch(tr: TransactionSpec) {
                state = state.update(tr).state;
            },
        };

        // Freshly created state: nothing to undo or redo yet.
        expect(toolbarCommands.undo.canRun(target)).toBe(false);
        expect(toolbarCommands.redo.canRun(target)).toBe(false);

        target.dispatch({ changes: { from: 5, insert: '!' } });
        expect(toolbarCommands.undo.canRun(target)).toBe(true);
        expect(toolbarCommands.redo.canRun(target)).toBe(false);
    });
});
