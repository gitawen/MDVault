import { LanguageDescription } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { classHighlighter, highlightCode } from '@lezer/highlight';
import { Marked, type Tokens } from 'marked';
import { COPY_ICON_SVG } from './copyButton';
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

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

const languageLoadListeners = new Set<() => void>();

/**
 * Subscribes to "a code language finished loading" events so the preview can
 * re-render with highlighting. Returns an unsubscribe function.
 */
export function onPreviewLanguageLoaded(listener: () => void): () => void {
    languageLoadListeners.add(listener);

    return () => {
        languageLoadListeners.delete(listener);
    };
}

/** Descriptions whose load started (or failed); never retried. */
const pendingLanguageLoads = new WeakSet<LanguageDescription>();

/**
 * Returns HTML for the code body: highlighted `tok-*` spans when the Lezer
 * language is already loaded, otherwise `null` (and a background load is
 * started that notifies subscribers). Every text chunk is HTML-escaped.
 */
function highlightFenceBody(code: string, lang: string): string | null {
    if (!lang) {
        return null;
    }

    const desc = LanguageDescription.matchLanguageName(languages, lang, true);
    if (!desc) {
        return null;
    }

    if (!desc.support) {
        if (!pendingLanguageLoads.has(desc)) {
            pendingLanguageLoads.add(desc);
            desc.load().then(
                () => {
                    languageLoadListeners.forEach((listener) => listener());
                },
                () => {
                    // Stays in `pendingLanguageLoads`: a failed load is not retried.
                },
            );
        }

        return null;
    }

    try {
        const tree = desc.support.language.parser.parse(code);
        let html = '';
        highlightCode(
            code,
            tree,
            classHighlighter,
            (text, classes) => {
                const safe = escapeHtml(text);
                html += classes
                    ? `<span class="${escapeAttribute(classes)}">${safe}</span>`
                    : safe;
            },
            () => {
                html += '\n';
            },
        );

        return html;
    } catch {
        return null;
    }
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
        code({ text, lang, escaped }) {
            const langClass = (lang || '').match(/^\S*/)?.[0];
            const classAttr = langClass
                ? ` class="language-${escapeAttribute(langClass)}"`
                : '';
            const codeContent = (text || '').replace(/\r?\n$/, '') + '\n';
            const highlighted = escaped
                ? null
                : highlightFenceBody(codeContent, langClass ?? '');
            const escapedCode =
                highlighted ??
                (escaped ? codeContent : escapeHtml(codeContent));
            return `<div class="code-block-wrapper relative group my-4"><button type="button" class="code-copy-btn" aria-label="Copy code" title="Copy code" data-code-copy>${COPY_ICON_SVG}</button><pre><code${classAttr}>${escapedCode}</code></pre></div>\n`;
        },
        codespan({ text }: Tokens.Codespan) {
            return `<code class="inline-code-wrapper group"><span class="inline-code-text">${escapeHtml(text)}</span><button type="button" class="inline-code-copy-btn" aria-label="Copy code" title="Copy code" data-inline-code-copy>${COPY_ICON_SVG}</button></code>`;
        },
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
