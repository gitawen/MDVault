import { describe, expect, it } from 'vite-plus/test';
import {
    isAllowedHref,
    renderMarkdownPreview,
    stripFrontmatter,
} from '../../../resources/js/lib/editor/markdownPreview';

describe('stripFrontmatter', () => {
    it('removes a leading YAML frontmatter block', () => {
        const markdown = '---\ntitle: Foo\ntags: []\n---\n\n# Body\n';
        expect(stripFrontmatter(markdown)).toBe('\n# Body\n');
    });

    it('leaves a body-initial "---" with no closing delimiter alone', () => {
        const markdown = '---\nJust a horizontal rule, no frontmatter here.\n';
        expect(stripFrontmatter(markdown)).toBe(markdown);
    });

    it('handles CRLF line endings in the frontmatter block', () => {
        const markdown = '---\r\ntitle: Foo\r\n---\r\n\r\n# Body\r\n';
        expect(stripFrontmatter(markdown)).toBe('\r\n# Body\r\n');
    });

    it('leaves markdown with no leading frontmatter unchanged', () => {
        const markdown = '# Just a heading\n';
        expect(stripFrontmatter(markdown)).toBe(markdown);
    });
});

describe('isAllowedHref', () => {
    it('rejects javascript:, data: and vbscript: protocols', () => {
        expect(isAllowedHref('javascript:alert(1)')).toBe(false);
        expect(isAllowedHref('data:text/html,<script>alert(1)</script>')).toBe(
            false,
        );
        expect(isAllowedHref('vbscript:msgbox(1)')).toBe(false);
    });

    it('allows http:, https: and mailto:', () => {
        expect(isAllowedHref('http://example.com')).toBe(true);
        expect(isAllowedHref('https://example.com')).toBe(true);
        expect(isAllowedHref('mailto:a@example.com')).toBe(true);
    });

    it('allows a scheme-less relative reference', () => {
        expect(isAllowedHref('../Projects/HRMIS.md')).toBe(true);
        expect(isAllowedHref('#anchor')).toBe(true);
    });

    it('rejects an empty href', () => {
        expect(isAllowedHref('')).toBe(false);
    });
});

describe('renderMarkdownPreview', () => {
    it('emits no anchor for a disallowed protocol link', () => {
        const html = renderMarkdownPreview('[x](javascript:alert(1))');
        expect(html).not.toContain('<a');
        expect(html).toContain('x');
    });

    it('escapes a double quote in the link title so no extra attribute is injected', () => {
        const html = renderMarkdownPreview(
            '[x](http://a.test "a\\" onmouseover=\\"alert(1)")',
        );
        expect(html).not.toMatch(/onmouseover=(?!&quot;)/);
        expect(html).toContain('&quot;');
    });

    it('escapes a double quote in the href', () => {
        const html = renderMarkdownPreview(
            '[x](http://a.test/"onmouseover="alert(1))',
        );
        expect(html).not.toMatch(/href="http:\/\/a\.test\/"onmouseover/);
    });

    it('preserves http:, https: and mailto: links', () => {
        expect(renderMarkdownPreview('[x](http://a.test)')).toContain(
            'href="http://a.test"',
        );
        expect(renderMarkdownPreview('[x](https://a.test)')).toContain(
            'href="https://a.test"',
        );
        expect(renderMarkdownPreview('[x](mailto:a@example.com)')).toContain(
            'href="mailto:a@example.com"',
        );
    });

    it('opens allowed links in a new tab without a referrer', () => {
        const html = renderMarkdownPreview('[x](https://a.test)');
        expect(html).toContain('target="_blank"');
        expect(html).toContain('rel="noopener noreferrer"');
    });

    it('renders GFM tables unchanged', () => {
        const html = renderMarkdownPreview(
            '| A | B |\n| --- | --- |\n| 1 | 2 |\n',
        );
        expect(html).toContain('<table>');
        expect(html).toContain('<td>1</td>');
    });

    it('renders GFM task lists unchanged', () => {
        const html = renderMarkdownPreview('- [ ] todo\n- [x] done\n');
        expect(html).toContain('type="checkbox"');
        expect(html).toContain('checked');
    });
});
