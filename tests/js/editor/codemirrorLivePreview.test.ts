import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import { EditorState } from '@codemirror/state';
import { describe, expect, it } from 'vite-plus/test';
import {
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
};

function buildState(doc: string): EditorState {
    return EditorState.create({
        doc,
        extensions: [markdown({ base: markdownLanguage })],
    });
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
});
