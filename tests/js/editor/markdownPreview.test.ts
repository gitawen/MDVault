import { defaultHighlightStyle } from '@codemirror/language';
import { tags } from '@lezer/highlight';
import { describe, expect, it } from 'vite-plus/test';
import { appHighlightStyle } from '../../../resources/js/lib/editor/codemirrorTheme';
import {
    isAllowedHref,
    onPreviewLanguageLoaded,
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

    it('wraps code blocks in .code-block-wrapper with a copy button (FR-01)', () => {
        const markdown = '```typescript\nconst message: string = "hello";\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain(
            '<div class="code-block-wrapper relative group my-4">',
        );
        expect(html).toContain(
            '<button type="button" class="code-copy-btn" aria-label="Copy code" title="Copy code" data-code-copy>',
        );
        expect(html).toContain('<pre><code class="language-typescript">');
        expect(html).toContain(
            'const message: string = &quot;hello&quot;;\n</code></pre>',
        );
    });

    it('renders code blocks without language class when no info string is specified', () => {
        const markdown = '```\nplain text code\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain(
            '<div class="code-block-wrapper relative group my-4">',
        );
        expect(html).toContain('data-code-copy');
        expect(html).toContain('<pre><code>plain text code\n</code></pre>');
        expect(html).not.toContain('class="language-');
    });

    it('escapes special characters in code content and language tag', () => {
        const markdown = '```js"<script>\n<div>Foo & "Bar" < 1</div>\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('class="language-js&quot;&lt;script&gt;"');
        expect(html).toContain(
            '&lt;div&gt;Foo &amp; &quot;Bar&quot; &lt; 1&lt;/div&gt;',
        );
    });

    it('wraps inline code spans in .inline-code-wrapper with a compact copy button (FR-07)', () => {
        const markdown = 'Use `npm install` to install dependencies.';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('<code class="inline-code-wrapper group">');
        expect(html).toContain(
            '<span class="inline-code-text">npm install</span>',
        );
        expect(html).toContain(
            '<button type="button" class="inline-code-copy-btn" aria-label="Copy code" title="Copy code" data-inline-code-copy>',
        );
    });

    it('escapes special characters in inline code text (FR-07)', () => {
        const markdown =
            'Here is `<Component prop="value" & count=\'5\'>` code.';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain(
            '&lt;Component prop=&quot;value&quot; &amp; count=&#39;5&#39;&gt;',
        );
        expect(html).not.toContain('<Component');
    });
});

describe('renderMarkdownPreview code highlighting', () => {
    it('highlights a loaded known language with tok- spans', async () => {
        const { LanguageDescription } = await import('@codemirror/language');
        const { languages } = await import('@codemirror/language-data');
        await LanguageDescription.matchLanguageName(
            languages,
            'javascript',
            true,
        )!.load();

        const html = renderMarkdownPreview('```javascript\nconst x = 1;\n```');
        expect(html).toContain('class="language-javascript"');
        expect(html).toContain('tok-keyword');
        expect(html).toContain('data-code-copy');
    });

    it('escapes malicious code in a highlighted language', async () => {
        const html = renderMarkdownPreview(
            '```javascript\n<img src=x onerror=alert(1)>\n```',
        );
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;');
    });

    it('escapes malicious code in an unknown language and does not highlight', () => {
        const html = renderMarkdownPreview(
            '```notalanguage\n<img src=x onerror=alert(1)>\n```',
        );
        expect(html).not.toContain('<img');
        expect(html).toContain('&lt;img src=x onerror=alert(1)&gt;');
        expect(html).not.toContain('tok-');
    });
});

describe('renderMarkdownPreview extras', () => {
    it('keeps a hostile info string escaped', () => {
        const html = renderMarkdownPreview(
            '```javascript" onmouseover="x\nlet a;\n```',
        );
        expect(html).not.toContain('" onmouseover="');
        expect(html).not.toMatch(/<code[^>]* onmouseover=/);
    });

    it('notifies language-load subscribers until unsubscribed', async () => {
        let calls = 0;
        const stop = onPreviewLanguageLoaded(() => {
            calls++;
        });
        renderMarkdownPreview('```python\nx = 1\n```');
        await new Promise((resolve) => setTimeout(resolve, 200));
        expect(calls).toBeGreaterThan(0);

        stop();
        const before = calls;
        renderMarkdownPreview('```ruby\nx = 1\n```');
        await new Promise((resolve) => setTimeout(resolve, 200));
        expect(calls).toBe(before);
    });
});

describe('appHighlightStyle', () => {
    it('uses defaultHighlightStyle in light mode', () => {
        expect(appHighlightStyle(false)).toBe(defaultHighlightStyle);
    });

    it('styles code tokens in dark mode', () => {
        expect(appHighlightStyle(true).style([tags.keyword])).toBeTruthy();
    });
});
