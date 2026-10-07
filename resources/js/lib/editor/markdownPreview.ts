import { Marked } from 'marked';
import { ALLOWED_LINK_PROTOCOLS, isAllowedHref } from './linkProtocols';

export { ALLOWED_LINK_PROTOCOLS, isAllowedHref };

/**
 * Matches a leading `---\n ... \n---` YAML frontmatter block. Requires an
 * actual closing delimiter, so a note whose body legitimately opens with a
 * horizontal rule (`---` with no closing `---` anywhere) is left alone.
 */
const FRONTMATTER_PATTERN = /^---\r?\n[\s\S]*?\r?\n(?:---|\.\.\.)\r?\n?/;

/**
 * Strips a leading YAML frontmatter block so it is never rendered as body
 * Markdown (FR-19). A body-initial `---` with no closing delimiter is left
 * untouched so it still renders as a horizontal rule.
 */
export function stripFrontmatter(markdown: string): string {
    if (!markdown.startsWith('---')) {
        return markdown;
    }

    const match = markdown.match(FRONTMATTER_PATTERN);
    if (!match) {
        return markdown;
    }

    return markdown.slice(match[0].length);
}

function escapeAttribute(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Isolated `Marked` instance for the rendered preview (FR-20). The link and
 * image renderers HTML-escape `href`/`title` (untrusted note content is
 * interpolated straight into `v-html`) and emit no anchor/`src` at all for a
 * disallowed protocol.
 */
const markdownParser = new Marked({ gfm: true, breaks: false });

markdownParser.use({
    renderer: {
        link({ href, title, text }) {
            if (!isAllowedHref(href)) {
                return text;
            }
            const titleAttr = title ? ` title="${escapeAttribute(title)}"` : '';
            return `<a href="${escapeAttribute(href)}"${titleAttr} target="_blank" rel="noopener noreferrer">${text}</a>`;
        },
        image({ href, title, text }) {
            if (!isAllowedHref(href)) {
                return text;
            }
            const titleAttr = title ? ` title="${escapeAttribute(title)}"` : '';
            return `<img src="${escapeAttribute(href)}" alt="${escapeAttribute(text)}"${titleAttr} />`;
        },
    },
});

/**
 * Renders Markdown to HTML for the Preview/Split panes.
 */
export function renderMarkdownPreview(markdown: string): string {
    return markdownParser.parse(markdown, { async: false }) as string;
}
