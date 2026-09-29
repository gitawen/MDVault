import { afterEach, beforeEach, describe, expect, it } from 'vite-plus/test';
import { assessMarkdown } from '../../../resources/js/lib/markdown/assess';
import {
    createMarkdownConverter,
    type MarkdownConverter,
} from '../../../resources/js/lib/markdown/converter';

describe('assessMarkdown', () => {
    let converter: MarkdownConverter;

    beforeEach(() => {
        converter = createMarkdownConverter();
    });

    afterEach(() => {
        converter.destroy();
    });

    it('reports the first differing 1-based line for a reformat', () => {
        const body = 'Heading\n=======\n\nSecond line stays.\n';
        const assessment = assessMarkdown(body, converter);

        expect(assessment.status).toBe('reformat');
        if (assessment.status === 'reformat') {
            expect(assessment.firstDifference.line).toBe(1);
            expect(assessment.firstDifference.before).toBe('Heading');
            expect(assessment.firstDifference.after).toBe('# Heading');
        }
    });

    it('treats an unsupported image even surrounded by exact content as unsupported', () => {
        const body = [
            '# A heading',
            '',
            '![alt text](https://example.com/image.png)',
            '',
            'A closing paragraph.',
        ].join('\n');

        const assessment = assessMarkdown(body, converter);

        expect(assessment.status).toBe('unsupported');
        if (assessment.status === 'unsupported') {
            expect(assessment.reasons).toContain('images');
        }
    });

    it('classifies a note whose round trip is untouched as exact, even if it contains wiki-link syntax (documented order)', () => {
        // In practice `@tiptap/markdown` always backslash-escapes literal
        // "[" and "]", so a real "[[wiki link]]" body never reaches step 2
        // as an exact match (see `unsupported/wiki-link.md`, which fails
        // the exact check and only then is caught by the step-3 text
        // pattern). This stub isolates the order itself: step 2 (exact)
        // must short-circuit before step 3 (the text-pattern check) ever
        // runs, so a hypothetically untouched round trip is "exact" even
        // though the body contains "[[...]]".
        const identityConverter: MarkdownConverter = {
            parse: (markdown: string) => converter.parse(markdown),
            serialize: (doc) => converter.serialize(doc),
            roundTrip: (markdown: string) => markdown,
            destroy: () => {},
        };

        const body = 'See [[Other note]] for more.';
        const assessment = assessMarkdown(body, identityConverter);

        expect(assessment.status).toBe('exact');
    });

    it('classifies a wiki link as unsupported once the note also reformats', () => {
        const body = '_See_ [[Other note]] for more.\n';
        const assessment = assessMarkdown(body, converter);

        expect(assessment.status).toBe('unsupported');
        if (assessment.status === 'unsupported') {
            expect(assessment.reasons).toContain('wiki-links');
        }
    });

    it("gives 'unstable' when the round trip never settles", () => {
        let calls = 0;
        const flakyConverter: MarkdownConverter = {
            parse: (markdown: string) => converter.parse(markdown),
            serialize: (doc) => converter.serialize(doc),
            roundTrip: (markdown: string) => {
                calls++;

                // Every call returns something different, so it can never
                // be idempotent.
                return `${markdown}-${calls}`;
            },
            destroy: () => converter.destroy(),
        };

        const assessment = assessMarkdown('_a_', flakyConverter);

        expect(assessment.status).toBe('unsupported');
        if (assessment.status === 'unsupported') {
            expect(assessment.reasons).toEqual(['unstable']);
        }
    });

    it("gives 'exact' for an empty body (Revision 4: a new note with an empty body)", () => {
        expect(assessMarkdown('', converter)).toEqual({ status: 'exact' });
    });
});
