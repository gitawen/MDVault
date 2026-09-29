import { Editor } from '@tiptap/core';
import { describe, expect, it } from 'vite-plus/test';
import { markdownExtensions } from '../../../resources/js/lib/markdown/extensions';

/**
 * T2 go/no-go gate (ADR `markdown-conversion-and-fidelity`): the core
 * Markdown set must round-trip idempotently through the official
 * `@tiptap/markdown` extension before the rest of Phase 4 is built on it.
 */

function roundTrip(markdown: string): string {
    const editor = new Editor({
        element: null,
        extensions: markdownExtensions(),
        content: markdown,
        contentType: 'markdown',
    });

    try {
        return editor.getMarkdown();
    } finally {
        editor.destroy();
    }
}

const trimEndNewlines = (s: string) => s.replace(/\n+$/, '');

describe('tiptap markdown spike', () => {
    it('creates a headless editor with no DOM element', () => {
        const editor = new Editor({
            element: null,
            extensions: markdownExtensions(),
            content: '# Hello',
            contentType: 'markdown',
        });

        expect(editor.getMarkdown()).toContain('Hello');
        editor.destroy();
    });

    it('round-trips the core supported set idempotently', () => {
        const fixture = [
            '# H1',
            '',
            '## H2',
            '',
            '### H3',
            '',
            'A paragraph with **bold**, *italic*, ~~strike~~ and `code`.',
            '',
            'A [link](https://example.com/x.y).',
            '',
            '- one',
            '- two',
            '  - nested',
            '',
            '1. first',
            '2. second',
            '',
            '- [ ] todo',
            '- [x] done',
            '',
            '> a quote',
            '',
            '```php',
            "echo 'hi';",
            '```',
            '',
            '---',
            '',
        ].join('\n');

        const r1 = roundTrip(fixture);
        const r2 = roundTrip(r1);

        expect(trimEndNewlines(r2)).toBe(trimEndNewlines(r1));

        // Every construct must have actually survived, not been dropped.
        expect(r1).toContain('# H1');
        expect(r1).toContain('## H2');
        expect(r1).toContain('### H3');
        expect(r1).toMatch(/\*\*bold\*\*/);
        expect(r1).toMatch(/(^|[^*])\*italic\*([^*]|$)/);
        expect(r1).toContain('~~strike~~');
        expect(r1).toContain('`code`');
        expect(r1).toContain('[link](https://example.com/x.y)');
        expect(r1).toMatch(/^-\s+one/m);
        expect(r1).toMatch(/nested/);
        expect(r1).toMatch(/^1\.\s+first/m);
        expect(r1).toMatch(/- \[ \] todo/);
        expect(r1).toMatch(/- \[x\] done/i);
        expect(r1).toContain('> a quote');
        expect(r1).toContain('```php');
        expect(r1).toContain("echo 'hi';");
        expect(r1).toContain('---');
    });

    it('round-trips tables idempotently', () => {
        const fixture = ['| A | B |', '| --- | --- |', '| 1 | 2 |'].join('\n');

        const r1 = roundTrip(fixture);
        const r2 = roundTrip(r1);

        expect(trimEndNewlines(r2)).toBe(trimEndNewlines(r1));
    });
});
