import { Editor } from '@tiptap/core';
import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { assessMarkdown } from '../../../resources/js/lib/markdown/assess';
import {
    createMarkdownConverter,
    type MarkdownConverter,
} from '../../../resources/js/lib/markdown/converter';
import {
    markdownExtensions,
    serializeEditor,
} from '../../../resources/js/lib/markdown/extensions';

const exactFixtures = import.meta.glob('../fixtures/markdown/exact/**/*.md', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

const reformatAll = import.meta.glob('../fixtures/markdown/reformat/*.md', {
    query: '?raw',
    import: 'default',
    eager: true,
}) as Record<string, string>;

const reformatSources = Object.fromEntries(
    Object.entries(reformatAll).filter(
        ([path]) => !path.endsWith('.expected.md'),
    ),
);

const reformatExpected = import.meta.glob(
    '../fixtures/markdown/reformat/*.expected.md',
    { query: '?raw', import: 'default', eager: true },
) as Record<string, string>;

const unsupportedFixtures = import.meta.glob(
    '../fixtures/markdown/unsupported/**/*.md',
    { query: '?raw', import: 'default', eager: true },
) as Record<string, string>;

const trimEndNewlines = (s: string): string => s.replace(/\n+$/, '');

function nameOf(path: string): string {
    return path.split('/').pop()!.replace(/\.md$/, '');
}

const unsupportedReasonByStem: Record<string, string> = {
    table: 'tables',
    image: 'images',
    'html-block': 'html',
    'inline-html': 'html',
    'reference-link': 'reference-links',
    footnote: 'footnotes',
    'wiki-link': 'wiki-links',
};

describe('exact fixtures round-trip byte-for-byte', () => {
    let converter: MarkdownConverter;

    beforeEach(() => {
        converter = createMarkdownConverter();

        return () => converter.destroy();
    });

    for (const [path, body] of Object.entries(exactFixtures)) {
        it(`round-trips ${nameOf(path)}`, () => {
            const assessment = assessMarkdown(body, converter);
            expect(assessment.status).toBe('exact');

            const r1 = converter.roundTrip(body);
            expect(trimEndNewlines(r1)).toBe(trimEndNewlines(body));

            const r2 = converter.roundTrip(r1);
            expect(trimEndNewlines(r2)).toBe(trimEndNewlines(r1));
        });
    }
});

describe('reformat fixtures reach the reviewed expected output', () => {
    let converter: MarkdownConverter;

    beforeEach(() => {
        converter = createMarkdownConverter();

        return () => converter.destroy();
    });

    for (const [path, body] of Object.entries(reformatSources)) {
        const stem = nameOf(path);

        it(`reformats ${stem} to the expected output`, () => {
            const expectedPath = Object.keys(reformatExpected).find((p) =>
                p.endsWith(`${stem}.expected.md`),
            );
            expect(expectedPath).toBeDefined();
            const expected = reformatExpected[expectedPath!];

            const assessment = assessMarkdown(body, converter);
            expect(assessment.status).toBe('reformat');

            const r1 = converter.roundTrip(body);
            expect(trimEndNewlines(r1)).toBe(trimEndNewlines(expected));

            const r2 = converter.roundTrip(expected);
            expect(trimEndNewlines(r2)).toBe(trimEndNewlines(expected));

            expect(JSON.stringify(converter.parse(body))).toBe(
                JSON.stringify(converter.parse(expected)),
            );
        });
    }
});

describe('unsupported fixtures are classified with the named reason', () => {
    let converter: MarkdownConverter;

    beforeEach(() => {
        converter = createMarkdownConverter();

        return () => converter.destroy();
    });

    for (const [path, body] of Object.entries(unsupportedFixtures)) {
        const stem = nameOf(path);

        it(`classifies ${stem} as unsupported`, () => {
            const assessment = assessMarkdown(body, converter);
            expect(assessment.status).toBe('unsupported');

            if (assessment.status === 'unsupported') {
                expect(assessment.reasons).toContain(
                    unsupportedReasonByStem[stem],
                );
            }
        });
    }
});

describe('serializeEditor applies overrides on the visible-editor path (A2)', () => {
    it('keeps a bare URL bare after an unrelated edit, in a visible-style editor', () => {
        const body = exactFixtures['../fixtures/markdown/exact/links.md'];
        expect(body).toContain('A bare URL: https://example.com/bare');

        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: body,
            contentType: 'markdown',
        });

        try {
            // A real edit unrelated to the links, exactly as the user
            // typing in the visible editor would produce.
            editor.commands.insertContentAt(editor.state.doc.content.size, {
                type: 'paragraph',
                content: [{ type: 'text', text: 'Appended paragraph.' }],
            });

            const serialized = serializeEditor(editor);

            expect(serialized).toContain(
                'A bare URL: https://example.com/bare',
            );
            expect(serialized).not.toContain(
                '[https://example.com/bare](https://example.com/bare)',
            );
            expect(serialized).toContain('Appended paragraph.');
        } finally {
            editor.destroy();
        }
    });
});

describe('extension list boundaries', () => {
    function flattenedExtensions() {
        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: '# x',
            contentType: 'markdown',
        });
        const extensions = editor.extensionManager.extensions;
        editor.destroy();

        return extensions;
    }

    it('has no underline or textAlign extension', () => {
        const names = flattenedExtensions().map((extension) => extension.name);
        expect(names).not.toContain('underline');
        expect(names).not.toContain('textAlign');
    });

    it('configures the link extension with openOnClick disabled', () => {
        const link = flattenedExtensions().find(
            (extension) => extension.name === 'link',
        );
        expect(link).toBeDefined();
        expect(link?.options?.openOnClick).toBe(false);
    });
});
