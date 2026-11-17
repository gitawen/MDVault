import { defaultHighlightStyle } from '@codemirror/language';
import { tags } from '@lezer/highlight';
import { describe, expect, it } from 'vite-plus/test';
import { appHighlightStyle } from '../../../resources/js/lib/editor/codemirrorTheme';
import {
    isAllowedHref,
    onPreviewLanguageLoaded,
    renderMarkdownPreview,
    stripFrontmatter,
    toggleTaskItem,
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

    it('renders /media/ and https:// images with escaped attributes and blocks disallowed protocols', () => {
        const mediaHtml = renderMarkdownPreview(
            '![Diagram "1"](/media/Work-pic.png "Title <1>")',
        );
        expect(mediaHtml).toContain(
            '<img src="/media/Work-pic.png" alt="Diagram &quot;1&quot;" title="Title &lt;1&gt;" />',
        );

        const httpsHtml = renderMarkdownPreview(
            '![Remote](https://example.com/pic.png)',
        );
        expect(httpsHtml).toContain(
            '<img src="https://example.com/pic.png" alt="Remote" />',
        );

        for (const bad of [
            '![xss](javascript:alert(1))',
            '![xss](data:text/html,<script>alert(1)</script>)',
            '![xss](vbscript:msgbox(1))',
        ]) {
            const badHtml = renderMarkdownPreview(bad);
            expect(badHtml).not.toContain('<img');
        }
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

    it('renders GFM task list checkboxes with data-task-index and aria-label', () => {
        const html = renderMarkdownPreview(
            '- [ ] first\n- [x] second\n  - [ ] nested\n',
        );
        expect(html).toContain('data-task-index="0"');
        expect(html).toContain('aria-label="Mark task as done"');
        expect(html).toContain('data-task-index="1"');
        expect(html).toContain('aria-label="Mark task as not done"');
        expect(html).toContain('data-task-index="2"');
    });

    it('renders interactive checkboxes without disabled attribute by default', () => {
        const html = renderMarkdownPreview('- [ ] editable task\n');
        expect(html).toContain('type="checkbox"');
        expect(html).not.toContain('disabled');
    });

    it('renders disabled checkboxes when disabled option is true', () => {
        const html = renderMarkdownPreview('- [ ] readonly task\n', {
            disabled: true,
        });
        expect(html).toContain('type="checkbox"');
        expect(html).toContain('disabled=""');
    });

    it('does not render task checkboxes for code blocks or inline code', () => {
        const markdown = '```\n- [ ] code\n```\n\nHere is `- [ ] inline` code.';
        const html = renderMarkdownPreview(markdown);
        expect(html).not.toContain('data-task-index');
        expect(html).not.toContain('type="checkbox"');
    });

    it('wraps code blocks in .code-block-wrapper with a macOS-style header, language label, and copy button (FR-01)', () => {
        const markdown = '```typescript\nconst message: string = "hello";\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('<div class="code-block-wrapper group my-4">');
        expect(html).toContain('<div class="code-block-header">');
        expect(html).toContain(
            '<span class="code-block-dots" aria-hidden="true">',
        );
        expect(html).toContain('bg-[#ff5f56]');
        expect(html).toContain('bg-[#ffbd2e]');
        expect(html).toContain('bg-[#27c93f]');
        expect(html).toContain(
            '<span class="code-block-lang">typescript</span>',
        );
        expect(html).toContain(
            '<button type="button" class="code-copy-btn" aria-label="Copy code" title="Copy code" data-code-copy>',
        );
        expect(html).toContain('<pre><code class="language-typescript">');
        expect(html).toContain(
            'const message: string = &quot;hello&quot;;\n</code></pre>',
        );
    });

    it('renders code blocks without language class or label when no info string is specified', () => {
        const markdown = '```\nplain text code\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('<div class="code-block-wrapper group my-4">');
        expect(html).toContain('<div class="code-block-header">');
        expect(html).toContain(
            '<span class="code-block-dots" aria-hidden="true">',
        );
        expect(html).toContain('data-code-copy');
        expect(html).toContain('<pre><code>plain text code\n</code></pre>');
        expect(html).not.toContain('class="language-');
        expect(html).not.toContain('code-block-lang');
    });

    it('escapes special characters in code content and language tag', () => {
        const markdown = '```js"<script>\n<div>Foo & "Bar" < 1</div>\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('class="language-js&quot;&lt;script&gt;"');
        expect(html).toContain(
            '<span class="code-block-lang">js&quot;&lt;script&gt;</span>',
        );
        expect(html).not.toContain('<script>');
        expect(html).toContain(
            '&lt;div&gt;Foo &amp; &quot;Bar&quot; &lt; 1&lt;/div&gt;',
        );
    });

    it('renders fenced mermaid code blocks in .mermaid-block wrapper with data-mermaid-source (FR-01)', () => {
        const markdown = '```mermaid\ngraph TD\nA-->B\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('class="mermaid-block');
        expect(html).toContain('data-mermaid-source="graph TD\nA--&gt;B"');
        expect(html).toContain('class="mermaid-container');
        expect(html).toContain('class="mermaid-loading');
        expect(html).toContain(
            '<pre class="mermaid-raw hidden"><code>graph TD\nA--&gt;B\n</code></pre>',
        );
    });

    it('escapes special characters and hostile scripts inside mermaid block source (FR-09)', () => {
        const markdown =
            '```mermaid\ngraph TD\nA["<script>alert(1)</script>"] --> B["Foo & "Bar""]\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).not.toContain('<script>alert(1)</script>');
        expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
        expect(html).toContain('&quot;');
        expect(html).toContain('&amp;');
    });

    it('includes pan, zoom-out, zoom-in, reset, copy, copy-svg, and edit action buttons on the mermaid wrapper (FR-03)', () => {
        const markdown = '```mermaid\ngraph TD\nStart --> Stop\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('data-mermaid-pan');
        expect(html).toContain('aria-label="Toggle pan"');
        expect(html).toContain('title="Toggle pan"');
        expect(html).toContain('data-mermaid-zoom-out');
        expect(html).toContain('aria-label="Zoom out"');
        expect(html).toContain('title="Zoom out"');
        expect(html).toContain('data-mermaid-zoom-in');
        expect(html).toContain('aria-label="Zoom in"');
        expect(html).toContain('title="Zoom in"');
        expect(html).toContain('data-mermaid-reset');
        expect(html).toContain('aria-label="Reset view"');
        expect(html).toContain('title="Reset view"');
        expect(html).toContain('data-mermaid-copy');
        expect(html).toContain('aria-label="Copy diagram source"');
        expect(html).toContain('data-mermaid-copy-svg');
        expect(html).toContain('aria-label="Copy SVG"');
        expect(html).toContain('data-mermaid-edit');
        expect(html).toContain('aria-label="Edit diagram"');
    });

    it('handles empty mermaid block gracefully', () => {
        const markdown = '```mermaid\n```';
        const html = renderMarkdownPreview(markdown);

        expect(html).toContain('class="mermaid-block');
        expect(html).toContain('data-mermaid-source=""');
        expect(html).toContain(
            '<pre class="mermaid-raw hidden"><code>\n</code></pre>',
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

describe('toggleTaskItem', () => {
    it('toggles an unchecked task to checked', () => {
        const md = '- [ ] todo item';
        expect(toggleTaskItem(md, 0)).toBe('- [x] todo item');
    });

    it('toggles a checked task to unchecked', () => {
        const md = '- [x] done item';
        expect(toggleTaskItem(md, 0)).toBe('- [ ] done item');
    });

    it('toggles an uppercase checked task [X] to unchecked [ ]', () => {
        const md = '- [X] done item';
        expect(toggleTaskItem(md, 0)).toBe('- [ ] done item');
    });

    it('toggles task items with different bullet markers (*, +, 1., 1))', () => {
        let md = '* [ ] star\n+ [x] plus\n1. [ ] ordered\n1) [x] paren';
        md = toggleTaskItem(md, 0);
        expect(md).toContain('* [x] star');
        md = toggleTaskItem(md, 1);
        expect(md).toContain('+ [ ] plus');
        md = toggleTaskItem(md, 2);
        expect(md).toContain('1. [x] ordered');
        md = toggleTaskItem(md, 3);
        expect(md).toContain('1) [ ] paren');
    });

    it('toggles nested task items at specific index', () => {
        const md =
            '- [ ] parent\n  - [ ] child\n    - [x] grandchild\n- [ ] sibling';
        const toggledGrandchild = toggleTaskItem(md, 2);
        expect(toggledGrandchild).toBe(
            '- [ ] parent\n  - [ ] child\n    - [ ] grandchild\n- [ ] sibling',
        );

        const toggledParent = toggleTaskItem(md, 0);
        expect(toggledParent).toBe(
            '- [x] parent\n  - [ ] child\n    - [x] grandchild\n- [ ] sibling',
        );

        const toggledSibling = toggleTaskItem(md, 3);
        expect(toggledSibling).toBe(
            '- [ ] parent\n  - [ ] child\n    - [x] grandchild\n- [x] sibling',
        );
    });

    it('preserves leading YAML frontmatter and offsets task indices correctly', () => {
        const md =
            '---\ntitle: Foo\ntags: [todo, urgent]\nnotes: [ ] not a task\n---\n\n# Heading\n\n- [ ] first real task\n- [x] second real task\n';
        const toggled = toggleTaskItem(md, 0);
        expect(toggled).toBe(
            '---\ntitle: Foo\ntags: [todo, urgent]\nnotes: [ ] not a task\n---\n\n# Heading\n\n- [x] first real task\n- [x] second real task\n',
        );
        // Verify frontmatter was untouched
        expect(toggled).toContain('notes: [ ] not a task');
        expect(toggled).toContain('tags: [todo, urgent]');
    });

    it('handles CRLF line endings with frontmatter', () => {
        const md =
            '---\r\ntitle: CRLF\r\ntags: [ ]\r\n---\r\n\r\n- [ ] crlf task\r\n';
        const toggled = toggleTaskItem(md, 0);
        expect(toggled).toBe(
            '---\r\ntitle: CRLF\r\ntags: [ ]\r\n---\r\n\r\n- [x] crlf task\r\n',
        );
    });

    it('does not toggle [ ] inside fenced code blocks or inline code', () => {
        const md =
            '# Note\n\n```markdown\n- [ ] fake task in code\n```\n\nHere is `- [ ] inline` and [ ] normal text.\n\n- [ ] genuine task\n';
        // The genuine task is the only task (index 0)
        const toggled = toggleTaskItem(md, 0);
        expect(toggled).toContain('- [x] genuine task');
        expect(toggled).toContain('```markdown\n- [ ] fake task in code\n```');
        expect(toggled).toContain('`- [ ] inline`');
        expect(toggled).toContain('[ ] normal text');
    });

    it('preserves task description with formatting, links, and code', () => {
        const md =
            '- [ ] Do **bold** with `[ ] code` and [link](https://test.com)';
        const toggled = toggleTaskItem(md, 0);
        expect(toggled).toBe(
            '- [x] Do **bold** with `[ ] code` and [link](https://test.com)',
        );
    });

    it('returns unchanged markdown if taskIndex is out of bounds or negative', () => {
        const md = '- [ ] only task\n';
        expect(toggleTaskItem(md, -1)).toBe(md);
        expect(toggleTaskItem(md, 1)).toBe(md);
        expect(toggleTaskItem(md, 99)).toBe(md);
    });

    it('supports multiple sequential toggles', () => {
        let md = '- [ ] A\n- [ ] B\n- [ ] C';
        md = toggleTaskItem(md, 0);
        md = toggleTaskItem(md, 1);
        md = toggleTaskItem(md, 2);
        expect(md).toBe('- [x] A\n- [x] B\n- [x] C');
        md = toggleTaskItem(md, 1);
        expect(md).toBe('- [x] A\n- [ ] B\n- [x] C');
    });
});
