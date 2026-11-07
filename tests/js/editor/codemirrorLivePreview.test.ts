import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import { syntaxTree } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import type { SyntaxNode } from '@lezer/common';
import { describe, expect, it } from 'vite-plus/test';
import {
    CodeBlockCopyWidget,
    extractFencedCodeContent,
    extractInlineCodeContent,
    InlineCodeCopyWidget,
    livePreview,
    markdownLineDecorations,
} from '../../../resources/js/lib/editor/codemirrorLivePreview';

const TAILWIND_UTILITY_PATTERN =
    /\b(text-|bg-|border-|p[btlrxy]?-|m[btlrxy]?-|font-|leading-)/;

type DecorationEntry = {
    from: number;
    to: number;
    class?: string;
    isWidget: boolean;
    widget?: unknown;
};

function buildState(doc: string): EditorState {
    return EditorState.create({
        doc,
        extensions: [markdown({ base: markdownLanguage })],
    });
}

function findFencedCodeNode(state: EditorState): SyntaxNode | null {
    const tree = syntaxTree(state);
    let fenced: SyntaxNode | null = null;
    tree.iterate({
        enter(node) {
            if (node.name === 'FencedCode') {
                fenced = node.node;
                return false;
            }
        },
    });
    return fenced;
}

function findInlineCodeNodes(state: EditorState): SyntaxNode[] {
    const tree = syntaxTree(state);
    const nodes: SyntaxNode[] = [];
    tree.iterate({
        enter(node) {
            if (node.name === 'InlineCode') {
                nodes.push(node.node);
                return false;
            }
        },
    });
    return nodes;
}

function decorate(
    state: EditorState,
    ranges?: readonly { from: number; to: number }[],
): DecorationEntry[] {
    const set = markdownLineDecorations(
        state,
        ranges ?? [{ from: 0, to: state.doc.length }],
    );
    const entries: DecorationEntry[] = [];
    set.between(0, state.doc.length, (from, to, value) => {
        entries.push({
            from,
            to,
            class: (value.spec as { class?: string }).class,
            isWidget: Boolean(value.spec.widget),
            widget: (value.spec as { widget?: unknown }).widget,
        });
    });
    return entries;
}

describe('markdownLineDecorations', () => {
    it('initializes the livePreview extension without errors', () => {
        const state = EditorState.create({
            doc: '# Heading 1\n- [ ] Todo item\n> Blockquote\n```\ncode\n```\n---',
            extensions: [livePreview()],
        });

        expect(state.doc.lines).toBe(7);
        expect(state.doc.line(1).text).toBe('# Heading 1');
        expect(state.doc.line(2).text).toBe('- [ ] Todo item');
    });

    it('decorates a heading outside a fence but not the same text inside one (FR-11)', () => {
        const doc = '# comment\n\n```\n# comment\n```\n';
        const state = buildState(doc);
        const entries = decorate(state);

        const line1Heading = entries.find(
            (e) => e.from === state.doc.line(1).from && e.class === 'cm-md-h1',
        );
        expect(line1Heading).toBeDefined();

        const fenceBodyLineFrom = state.doc.line(4).from;
        const headingInsideFence = entries.find(
            (e) => e.from === fenceBodyLineFrom && e.class === 'cm-md-h1',
        );
        expect(headingInsideFence).toBeUndefined();

        const fenceBodyDecoration = entries.find(
            (e) => e.from === fenceBodyLineFrom,
        );
        expect(fenceBodyDecoration?.class).toBe('cm-md-fence-body');
    });

    it('excludes YAML frontmatter delimiters from rule decoration, but decorates a body rule (FR-11)', () => {
        const doc = '---\ntitle: Foo\ntags: []\n---\n\n# Real heading\n\n---\n';
        const state = buildState(doc);
        const entries = decorate(state);

        // Lines 1-4 are the frontmatter block: no decoration at all.
        for (let i = 1; i <= 4; i++) {
            const line = state.doc.line(i);
            expect(entries.some((e) => e.from === line.from)).toBe(false);
        }

        const heading = entries.find((e) => e.from === state.doc.line(6).from);
        expect(heading?.class).toBe('cm-md-h1');

        const bodyRule = entries.find((e) => e.from === state.doc.line(8).from);
        expect(bodyRule?.class).toBe('cm-md-rule');
    });

    it('decorates a checked task with a single replace range and marks the trailing text (FR-17)', () => {
        const doc = '- [x] done';
        const state = buildState(doc);
        const entries = decorate(state);

        const replaceEntries = entries.filter((e) => e.isWidget);
        expect(replaceEntries).toHaveLength(1);
        expect(replaceEntries[0].from).toBe(2);
        expect(replaceEntries[0].to).toBe(5); // exactly "[x]" (3 chars)

        const strike = entries.find((e) => e.class === 'cm-md-task-done');
        expect(strike).toBeDefined();
        expect(strike?.from).toBe(5);
    });

    it('decorates an unchecked task without a strike-through mark', () => {
        const doc = '- [ ] todo';
        const state = buildState(doc);
        const entries = decorate(state);

        const replaceEntries = entries.filter((e) => e.isWidget);
        expect(replaceEntries).toHaveLength(1);
        expect(entries.some((e) => e.class === 'cm-md-task-done')).toBe(false);
    });

    it('does not throw when two ranges both fall inside one long line (FR-12)', () => {
        const longLine = `# ${'word '.repeat(400)}`.trimEnd();
        const doc = `${longLine}\nsecond line\n`;
        const state = buildState(doc);

        const splitPoint = Math.floor(longLine.length / 2);
        const ranges = [
            { from: 0, to: splitPoint },
            { from: splitPoint - 5, to: state.doc.length },
        ];

        expect(() => markdownLineDecorations(state, ranges)).not.toThrow();

        const entries = decorate(state, ranges);
        // The heading line decoration must appear exactly once even though
        // it was covered by both (overlapping) ranges.
        const headingEntries = entries.filter(
            (e) => e.from === 0 && e.class === 'cm-md-h1',
        );
        expect(headingEntries).toHaveLength(1);
    });

    it('marks the fenced block body distinctly from its open/close delimiters (FR-16)', () => {
        const doc = '```js\nline one\nline two\n```\n';
        const state = buildState(doc);
        const entries = decorate(state);

        expect(
            entries.find((e) => e.from === state.doc.line(1).from)?.class,
        ).toBe('cm-md-fence-open');
        expect(
            entries.find((e) => e.from === state.doc.line(2).from)?.class,
        ).toBe('cm-md-fence-body');
        expect(
            entries.find((e) => e.from === state.doc.line(3).from)?.class,
        ).toBe('cm-md-fence-body');
        expect(
            entries.find((e) => e.from === state.doc.line(4).from)?.class,
        ).toBe('cm-md-fence-close');
    });

    it('never emits a Tailwind utility class (FR-13, FR-14, FR-15)', () => {
        const doc = [
            '# H1',
            '## H2',
            '### H3',
            '#### H4',
            '##### H5',
            '###### H6',
            '> quote',
            '---',
            '- [ ] todo',
            '- [x] done',
            '```js',
            'code',
            '```',
        ].join('\n');
        const state = buildState(doc);
        const entries = decorate(state);

        expect(entries.length).toBeGreaterThan(0);
        for (const entry of entries) {
            if (entry.class) {
                expect(entry.class).not.toMatch(TAILWIND_UTILITY_PATTERN);
            }
        }
    });

    it('emits a copy widget decoration on the opening fence line at line.to (FR-03, FR-05)', () => {
        const doc = '```js\nconsole.log("hello");\n```\n';
        const state = buildState(doc);
        const entries = decorate(state);

        const openLine = state.doc.line(1);

        // Line decoration at line.from
        const lineDeco = entries.find(
            (e) => e.from === openLine.from && e.class === 'cm-md-fence-open',
        );
        expect(lineDeco).toBeDefined();

        // Widget decoration at line.to
        const widgetDeco = entries.find(
            (e) => e.from === openLine.to && e.to === openLine.to && e.isWidget,
        );
        expect(widgetDeco).toBeDefined();
    });

    it('emits an inline copy widget decoration at inlineCode.to (FR-08, FR-09)', () => {
        const doc = 'Run `npm test` now.';
        const state = buildState(doc);
        const entries = decorate(state);

        const nodes = findInlineCodeNodes(state);
        expect(nodes).toHaveLength(1);
        const inlineNode = nodes[0];

        const widgetDeco = entries.find(
            (e) =>
                e.from === inlineNode.to &&
                e.to === inlineNode.to &&
                e.isWidget,
        );
        expect(widgetDeco).toBeDefined();
        expect(widgetDeco?.widget).toBeInstanceOf(InlineCodeCopyWidget);
        expect((widgetDeco?.widget as InlineCodeCopyWidget).code).toBe(
            'npm test',
        );
    });

    it('emits multiple inline copy widgets in ascending position order on the same line', () => {
        const doc = 'Compare `foo` and `bar` here.';
        const state = buildState(doc);
        const entries = decorate(state);

        const widgets = entries.filter(
            (e) => e.isWidget && e.widget instanceof InlineCodeCopyWidget,
        );
        expect(widgets).toHaveLength(2);
        expect(widgets[0].from).toBeLessThan(widgets[1].from);
        expect((widgets[0].widget as InlineCodeCopyWidget).code).toBe('foo');
        expect((widgets[1].widget as InlineCodeCopyWidget).code).toBe('bar');
    });

    it('emits both heading line decoration and inline code copy widget', () => {
        const doc = '# Guide for `MDVault`';
        const state = buildState(doc);
        const entries = decorate(state);

        const heading = entries.find((e) => e.class === 'cm-md-h1');
        expect(heading).toBeDefined();
        expect(heading?.from).toBe(0);

        const widgets = entries.filter(
            (e) => e.isWidget && e.widget instanceof InlineCodeCopyWidget,
        );
        expect(widgets).toHaveLength(1);
        expect((widgets[0].widget as InlineCodeCopyWidget).code).toBe('MDVault');
    });

    it('decorates lines with task checkbox, task-done mark, and inline code copy widget monotonically', () => {
        const doc = '- [x] Complete `vitest` suite';
        const state = buildState(doc);

        expect(() => decorate(state)).not.toThrow();

        const entries = decorate(state);
        const checkbox = entries.find(
            (e) => e.isWidget && !(e.widget instanceof InlineCodeCopyWidget),
        );
        expect(checkbox).toBeDefined();
        expect(checkbox?.from).toBe(2);

        const strike = entries.find((e) => e.class === 'cm-md-task-done');
        expect(strike).toBeDefined();

        const inlineWidget = entries.find(
            (e) => e.isWidget && e.widget instanceof InlineCodeCopyWidget,
        );
        expect(inlineWidget).toBeDefined();
        expect((inlineWidget?.widget as InlineCodeCopyWidget).code).toBe(
            'vitest',
        );
    });
});

describe('extractFencedCodeContent', () => {
    it('extracts multi-line code content excluding fence delimiters (FR-04)', () => {
        const doc = '```js\nconst a = 1;\nconst b = 2;\n```\n';
        const state = buildState(doc);
        const node = findFencedCodeNode(state);
        expect(node).not.toBeNull();
        expect(extractFencedCodeContent(state, node!)).toBe(
            'const a = 1;\nconst b = 2;',
        );
    });

    it('returns empty string for an empty fenced code block (FR-04)', () => {
        const doc = '```\n```\n';
        const state = buildState(doc);
        const node = findFencedCodeNode(state);
        expect(node).not.toBeNull();
        expect(extractFencedCodeContent(state, node!)).toBe('');
    });

    it('preserves blank lines within the code block content', () => {
        const doc = '```python\ndef foo():\n\n    return 42\n```\n';
        const state = buildState(doc);
        const node = findFencedCodeNode(state);
        expect(node).not.toBeNull();
        expect(extractFencedCodeContent(state, node!)).toBe(
            'def foo():\n\n    return 42',
        );
    });

    it('extracts content for an unclosed fenced code block at end of document', () => {
        const doc = '```ts\nlet x = 10;\nconsole.log(x);';
        const state = buildState(doc);
        const node = findFencedCodeNode(state);
        expect(node).not.toBeNull();
        expect(extractFencedCodeContent(state, node!)).toBe(
            'let x = 10;\nconsole.log(x);',
        );
    });

    it('returns empty string for a single-line opening fence with no closing fence', () => {
        const doc = '```ts';
        const state = buildState(doc);
        const node = findFencedCodeNode(state);
        expect(node).not.toBeNull();
        expect(extractFencedCodeContent(state, node!)).toBe('');
    });
});

describe('extractInlineCodeContent', () => {
    it('extracts content between single backticks omitting delimiters (FR-09)', () => {
        const doc = '`const x = 1;`';
        const state = buildState(doc);
        const nodes = findInlineCodeNodes(state);
        expect(nodes).toHaveLength(1);
        expect(extractInlineCodeContent(state, nodes[0])).toBe('const x = 1;');
    });

    it('extracts content with multiple backticks (FR-09)', () => {
        const doc = '``hello ` world``';
        const state = buildState(doc);
        const nodes = findInlineCodeNodes(state);
        expect(nodes).toHaveLength(1);
        expect(extractInlineCodeContent(state, nodes[0])).toBe('hello ` world');
    });

    it('preserves inner whitespace inside inline code (FR-09)', () => {
        const doc = '` foo `';
        const state = buildState(doc);
        const nodes = findInlineCodeNodes(state);
        expect(nodes).toHaveLength(1);
        expect(extractInlineCodeContent(state, nodes[0])).toBe(' foo ');
    });

    it('returns empty string for empty inline code (FR-09)', () => {
        const doc = '``';
        const state = buildState(doc);
        const nodes = findInlineCodeNodes(state);
        if (nodes.length > 0) {
            expect(extractInlineCodeContent(state, nodes[0])).toBe('');
        }
    });
});
