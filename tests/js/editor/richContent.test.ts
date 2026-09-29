import { describe, expect, it } from 'vite-plus/test';
import {
    decodeRichContent,
    encodeRichContent,
    frontmatterProblem,
    richContentAsText,
    richSavePayload,
    type RichContent,
} from '../../../resources/js/lib/editor/richContent';

describe('encodeRichContent / decodeRichContent', () => {
    it('round-trips a note with no frontmatter', () => {
        const content: RichContent = { frontmatter: null, body: '# H\n' };

        expect(decodeRichContent(encodeRichContent(content))).toEqual(content);
    });

    it('round-trips an empty frontmatter block', () => {
        const content: RichContent = { frontmatter: '', body: '# H\n' };

        expect(decodeRichContent(encodeRichContent(content))).toEqual(content);
    });

    it('round-trips values with trailing newlines and unicode', () => {
        const content: RichContent = {
            frontmatter: 'title: café\ntags: [你好]\n',
            body: 'CJK: 你好世界\n\nEmoji: \u{1F600}\n',
        };

        expect(decodeRichContent(encodeRichContent(content))).toEqual(content);
    });

    it('is deterministic: equal content encodes to equal strings', () => {
        const a: RichContent = { frontmatter: 'a: 1', body: 'x' };
        const b: RichContent = { frontmatter: 'a: 1', body: 'x' };

        expect(encodeRichContent(a)).toBe(encodeRichContent(b));
    });
});

describe('richSavePayload', () => {
    it('maps a null frontmatter to has_frontmatter: false and an empty string', () => {
        expect(richSavePayload({ frontmatter: null, body: '# H\n' })).toEqual({
            content: '# H\n',
            has_frontmatter: false,
            frontmatter: '',
        });
    });

    it('maps a string frontmatter to has_frontmatter: true', () => {
        expect(richSavePayload({ frontmatter: 'a: 1', body: '# H\n' })).toEqual(
            {
                content: '# H\n',
                has_frontmatter: true,
                frontmatter: 'a: 1',
            },
        );
    });

    it('maps an empty-string frontmatter to has_frontmatter: true', () => {
        expect(richSavePayload({ frontmatter: '', body: '# H\n' })).toEqual({
            content: '# H\n',
            has_frontmatter: true,
            frontmatter: '',
        });
    });
});

describe('richContentAsText', () => {
    it('returns the body alone when there is no frontmatter', () => {
        expect(richContentAsText({ frontmatter: null, body: '# H\n' })).toBe(
            '# H\n',
        );
    });

    it('renders an empty frontmatter block with no blank line before the closing delimiter', () => {
        expect(richContentAsText({ frontmatter: '', body: '# H\n' })).toBe(
            '---\n---\n\n# H\n',
        );
    });

    it('renders a non-empty frontmatter block', () => {
        expect(richContentAsText({ frontmatter: 'a: 1', body: '# H\n' })).toBe(
            '---\na: 1\n---\n\n# H\n',
        );
    });
});

describe('frontmatterProblem', () => {
    it('flags a bare --- line', () => {
        expect(frontmatterProblem('a\n---\nb')).not.toBeNull();
    });

    it('flags three dashes with trailing spaces', () => {
        expect(frontmatterProblem('--- ')).not.toBeNull();
    });

    it('allows yaml with no standalone --- line', () => {
        expect(frontmatterProblem('a: 1\nb: 2')).toBeNull();
    });

    it('allows an empty string', () => {
        expect(frontmatterProblem('')).toBeNull();
    });
});
