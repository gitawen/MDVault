import { EditorState, type TransactionSpec } from '@codemirror/state';
import { describe, expect, it } from 'vite-plus/test';
import {
    clearFormatting,
    clearLinePrefix,
    type CommandTarget,
    insertCodeBlock,
    insertHorizontalRule,
    insertLink,
    insertTable,
    isInlineMarkActive,
    isLinePrefixActive,
    linkAt,
    removeLink,
    setHeading,
    toggleBlockquote,
    toggleBold,
    toggleBulletList,
    toggleInlineCode,
    toggleItalic,
    toggleOrderedList,
    toggleStrike,
    toggleTaskList,
} from '../../../resources/js/lib/editor/codemirrorCommands';

function createTestTarget(
    initialText: string,
    from = 0,
    to = from,
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

describe('CodeMirror 6 rich formatting commands', () => {
    it('wraps and unwraps bold text with **', () => {
        const target = createTestTarget('hello world', 0, 5); // select 'hello'
        toggleBold(target);
        expect(target.state.doc.toString()).toBe('**hello** world');

        // Select '**hello**' and toggle to unwrap
        target.dispatch({ selection: { anchor: 0, head: 9 } });
        toggleBold(target);
        expect(target.state.doc.toString()).toBe('hello world');
    });

    it('wraps and unwraps italic text with *', () => {
        const target = createTestTarget('hello world', 6, 11); // select 'world'
        toggleItalic(target);
        expect(target.state.doc.toString()).toBe('hello *world*');
    });

    it('wraps strikethrough with ~~', () => {
        const target = createTestTarget('strike me', 0, 6);
        toggleStrike(target);
        expect(target.state.doc.toString()).toBe('~~strike~~ me');
    });

    it('wraps inline code with `', () => {
        const target = createTestTarget('const x = 1', 6, 7); // select 'x'
        toggleInlineCode(target);
        expect(target.state.doc.toString()).toBe('const `x` = 1');
    });

    it('applies headings of different levels', () => {
        const target = createTestTarget('My Title', 0, 0);
        setHeading(target, 1);
        expect(target.state.doc.toString()).toBe('# My Title');

        setHeading(target, 2);
        expect(target.state.doc.toString()).toBe('## My Title');
    });

    it('toggles bullet lists', () => {
        const target = createTestTarget('Item 1', 0, 0);
        toggleBulletList(target);
        expect(target.state.doc.toString()).toBe('- Item 1');

        toggleBulletList(target);
        expect(target.state.doc.toString()).toBe('Item 1');
    });

    it('toggles ordered lists', () => {
        const target = createTestTarget('Item A', 0, 0);
        toggleOrderedList(target);
        expect(target.state.doc.toString()).toBe('1. Item A');
    });

    it('toggles task lists', () => {
        const target = createTestTarget('Task A', 0, 0);
        toggleTaskList(target);
        expect(target.state.doc.toString()).toBe('- [ ] Task A');
    });

    it('toggles blockquotes', () => {
        const target = createTestTarget('A wise quote', 0, 0);
        toggleBlockquote(target);
        expect(target.state.doc.toString()).toBe('> A wise quote');
    });

    it('inserts fenced code blocks', () => {
        const target = createTestTarget('hello', 0, 5);
        insertCodeBlock(target);
        expect(target.state.doc.toString()).toContain('```\nhello\n```');
    });

    it('inserts links', () => {
        const target = createTestTarget('Google', 0, 6);
        insertLink(target, 'https://google.com');
        expect(target.state.doc.toString()).toBe(
            '[Google](https://google.com)',
        );
    });

    it('italic preserves bold instead of unwrapping it (FR-03)', () => {
        // '**bold**' with 'bold' selected (positions 2-6)
        const target = createTestTarget('**bold**', 2, 6);
        expect(isInlineMarkActive(target.state, '*')).toBe(false);
        expect(isInlineMarkActive(target.state, '**')).toBe(true);

        toggleItalic(target);
        expect(target.state.doc.toString()).toBe('***bold***');
    });

    it('inline code does not mistake a fence delimiter for a wrapping backtick (FR-03)', () => {
        const target = createTestTarget('```code```', 3, 7); // select 'code'
        toggleInlineCode(target);
        // Must not unwrap into '``code``' (stripping a fence backtick); it
        // should wrap the selection with its own backtick pair instead.
        expect(target.state.doc.toString()).toBe('````code````');
    });

    describe('clearLinePrefix', () => {
        it('strips a heading prefix', () => {
            const target = createTestTarget('## Title', 3, 3);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('Title');
        });

        it('strips a bullet list prefix', () => {
            const target = createTestTarget('- item', 2, 2);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('item');
        });

        it('strips an ordered list prefix', () => {
            const target = createTestTarget('2. item', 3, 3);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('item');
        });

        it('strips a task list prefix', () => {
            const target = createTestTarget('- [ ] task', 6, 6);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('task');
        });

        it('strips a blockquote prefix', () => {
            const target = createTestTarget('> quoted', 2, 2);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('quoted');
        });

        it('is a no-op on a plain line', () => {
            const target = createTestTarget('plain text', 0, 0);
            expect(clearLinePrefix(target)).toBe(false);
            expect(target.state.doc.toString()).toBe('plain text');
        });

        it('fully unwraps a blockquoted heading in one call (QA-03)', () => {
            const target = createTestTarget('> ## Quoted', 11, 11);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('Quoted');
        });

        it('fully unwraps a blockquoted task item in one call (QA-03)', () => {
            const target = createTestTarget('> - [ ] task', 12, 12);
            expect(clearLinePrefix(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('task');
        });
    });

    describe('insertHorizontalRule', () => {
        it('inserts a rule after a bullet line without consuming its marker', () => {
            const target = createTestTarget('- item', 6, 6);
            insertHorizontalRule(target);
            expect(target.state.doc.toString()).toBe('- item\n\n---\n');
        });

        it('inserts a rule after a heading line without consuming the heading', () => {
            const target = createTestTarget('## Title', 8, 8);
            insertHorizontalRule(target);
            expect(target.state.doc.toString()).toBe('## Title\n\n---\n');
        });

        it('inserts exactly one rule for a multi-line selection', () => {
            const target = createTestTarget(
                'line one\nline two\nline three',
                0,
                18,
            );
            insertHorizontalRule(target);
            const result = target.state.doc.toString();
            expect(result.match(/---/g)?.length).toBe(1);
            expect(result.startsWith('line one\nline two')).toBe(true);
        });
    });

    describe('insertTable', () => {
        it('inserts a table after a bullet line without consuming its text, with nothing selected', () => {
            const target = createTestTarget('- item', 6, 6);
            insertTable(target);
            expect(target.state.doc.toString()).toBe(
                '- item\n\n| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |\n',
            );
        });

        it('inserts exactly one table for a multi-line selection and preserves the selected text', () => {
            const target = createTestTarget(
                'line one\nline two\nline three',
                0,
                28,
            );
            insertTable(target);
            const result = target.state.doc.toString();
            expect(result.match(/\| Header 1 \| Header 2 \|/g)?.length).toBe(1);
            expect(result.startsWith('line one\nline two\nline three')).toBe(
                true,
            );
        });

        it('inserts at the end of a single-line document', () => {
            const target = createTestTarget('note', 4, 4);
            insertTable(target);
            expect(target.state.doc.toString()).toBe(
                'note\n\n| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |\n',
            );
        });

        it('selects "Header 1" so it can be typed over', () => {
            const target = createTestTarget('', 0, 0);
            insertTable(target);
            const { from, to } = target.state.selection.main;
            expect(target.state.sliceDoc(from, to)).toBe('Header 1');
        });
    });

    describe('clearFormatting', () => {
        it('removes mixed inline marks from the selection', () => {
            const text = '**bold** and *italic* and `code`';
            const target = createTestTarget(text, 0, text.length);
            expect(clearFormatting(target)).toBe(true);
            expect(target.state.doc.toString()).toBe(
                'bold and italic and code',
            );
        });

        it('removes the line prefix along with inline marks', () => {
            const target = createTestTarget('> quoted', 8, 8);
            expect(clearFormatting(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('quoted');
        });

        it('is a no-op when there is nothing to clear', () => {
            const target = createTestTarget('plain text', 0, 10);
            expect(clearFormatting(target)).toBe(false);
            expect(target.state.doc.toString()).toBe('plain text');
        });

        it('fully unwraps a blockquoted heading in one call, consistent with clearLinePrefix (QA-03)', () => {
            const target = createTestTarget('> ## Quoted', 11, 11);
            expect(clearFormatting(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('Quoted');
        });
    });

    describe('insertLink', () => {
        it('falls back to the label "link" with no selection, and selects it', () => {
            const target = createTestTarget('', 0, 0);
            insertLink(target, 'https://example.com');
            expect(target.state.doc.toString()).toBe(
                '[link](https://example.com)',
            );
            expect(
                target.state.sliceDoc(
                    target.state.selection.main.from,
                    target.state.selection.main.to,
                ),
            ).toBe('link');
        });

        it('replaces an explicit range instead of the current selection', () => {
            const text = 'see [old](https://a.test) here';
            const probe = EditorState.create({ doc: text });
            const link = linkAt(probe, 6);
            expect(link).not.toBeNull();

            const target = createTestTarget(text, 0, 0);
            insertLink(target, 'https://b.test', link!.text, {
                from: link!.from,
                to: link!.to,
            });
            expect(target.state.doc.toString()).toBe(
                'see [old](https://b.test) here',
            );
        });
    });

    describe('linkAt / removeLink', () => {
        it('finds a link containing the given position', () => {
            const text = 'see [label](https://a.test) here';
            const state = EditorState.create({ doc: text });
            const link = linkAt(state, 7);
            expect(link).not.toBeNull();
            expect(link?.text).toBe('label');
            expect(link?.href).toBe('https://a.test');
        });

        it('returns null when the position is not inside a link', () => {
            const state = EditorState.create({ doc: 'no links here' });
            expect(linkAt(state, 3)).toBeNull();
        });

        it('replaces the link with its label text', () => {
            const target = createTestTarget(
                'see [label](https://a.test) here',
                7,
                7,
            );
            expect(removeLink(target)).toBe(true);
            expect(target.state.doc.toString()).toBe('see label here');
        });

        it('does nothing when the caret is not inside a link', () => {
            const target = createTestTarget('no links here', 3, 3);
            expect(removeLink(target)).toBe(false);
            expect(target.state.doc.toString()).toBe('no links here');
        });
    });

    describe('isLinePrefixActive', () => {
        it('matches any ordinal for an ordered-list prefix, not just "1. "', () => {
            const state = EditorState.create({
                doc: '2. item',
                selection: { anchor: 3 },
            });
            expect(isLinePrefixActive(state, '1. ')).toBe(true);
        });

        it('does not match a plain line', () => {
            const state = EditorState.create({
                doc: 'item',
                selection: { anchor: 0 },
            });
            expect(isLinePrefixActive(state, '1. ')).toBe(false);
        });
    });
});
