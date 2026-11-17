import { LanguageDescription } from '@codemirror/language';
import { languages } from '@codemirror/language-data';
import { markdownLanguage } from '@codemirror/lang-markdown';
import { classHighlighter, highlightCode } from '@lezer/highlight';
import { Marked, type Tokens } from 'marked';
import { COPY_ICON_SVG } from './copyButton';
import { ALLOWED_LINK_PROTOCOLS, isAllowedHref } from './linkProtocols';

export { ALLOWED_LINK_PROTOCOLS, isAllowedHref };

export const MERMAID_PAN_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-move"><path d="M12 2v20"/><path d="m15 19-3 3-3-3"/><path d="m19 9 3 3-3 3"/><path d="M2 12h20"/><path d="m5 9-3 3 3 3"/><path d="m9 5 3-3 3 3"/></svg>';

export const MERMAID_ZOOM_OUT_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zoom-out"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="8" x2="14" y1="11" y2="11"/></svg>';

export const MERMAID_ZOOM_IN_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-zoom-in"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/><line x1="11" x2="11" y1="8" y2="14"/><line x1="8" x2="14" y1="11" y2="11"/></svg>';

export const MERMAID_RESET_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-scan"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/></svg>';

export const MERMAID_SVG_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-image"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';

export const MERMAID_EDIT_ICON =
    '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-pencil"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>';

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

export function escapeAttribute(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

export function escapeHtml(value: string): string {
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

let checkboxIndex = 0;
let currentDisabled = false;

export type RenderMarkdownPreviewOptions = {
    disabled?: boolean;
};

/**
 * Isolated `Marked` instance for the rendered preview (FR-20). The link and
 * image renderers HTML-escape `href`/`title` (untrusted note content is
 * interpolated straight into `v-html`) and emit no anchor/`src` at all for a
 * disallowed protocol.
 */
const markdownParser = new Marked({ gfm: true, breaks: false });

markdownParser.use({
    renderer: {
        checkbox({ checked }: Tokens.Checkbox) {
            const index = checkboxIndex++;
            const disabledAttr = currentDisabled ? ' disabled=""' : '';
            const checkedAttr = checked ? ' checked=""' : '';
            const ariaLabel = checked
                ? 'Mark task as not done'
                : 'Mark task as done';
            return `<input type="checkbox" data-task-index="${index}" aria-label="${ariaLabel}"${checkedAttr}${disabledAttr}> `;
        },
        code({ text, lang, escaped }) {
            const langClass = (lang || '').match(/^\S*/)?.[0];
            if (langClass?.toLowerCase() === 'mermaid') {
                const rawSource = text || '';
                const codeContent = rawSource.replace(/\r?\n$/, '') + '\n';
                return `<div class="mermaid-block relative group my-4 rounded-lg border border-border/70 bg-card/60 p-4 overflow-hidden transition-all" data-mermaid-source="${escapeAttribute(rawSource)}"><div class="mermaid-toolbar absolute top-2.5 right-2.5 flex items-center gap-1 rounded-lg border border-border/70 bg-background/90 p-1 shadow-xs backdrop-blur-xs opacity-0 group-hover:opacity-100 focus-within:opacity-100 transition-opacity z-10"><button type="button" class="mermaid-action-btn" aria-label="Toggle pan" title="Toggle pan" data-mermaid-pan>${MERMAID_PAN_ICON}</button><button type="button" class="mermaid-action-btn" aria-label="Zoom out" title="Zoom out" data-mermaid-zoom-out>${MERMAID_ZOOM_OUT_ICON}</button><button type="button" class="mermaid-action-btn" aria-label="Zoom in" title="Zoom in" data-mermaid-zoom-in>${MERMAID_ZOOM_IN_ICON}</button><button type="button" class="mermaid-action-btn" aria-label="Reset view" title="Reset view" data-mermaid-reset>${MERMAID_RESET_ICON}</button><span class="w-px h-4 bg-border/60 mx-0.5" aria-hidden="true"></span><button type="button" class="mermaid-action-btn" aria-label="Copy diagram source" title="Copy diagram source" data-mermaid-copy>${COPY_ICON_SVG}</button><button type="button" class="mermaid-action-btn" aria-label="Copy SVG" title="Copy SVG" data-mermaid-copy-svg>${MERMAID_SVG_ICON}</button><button type="button" class="mermaid-action-btn" aria-label="Edit diagram" title="Edit diagram" data-mermaid-edit>${MERMAID_EDIT_ICON}</button></div><div class="mermaid-container flex justify-center items-center min-h-[60px] overflow-hidden py-2"><div class="mermaid-loading flex items-center gap-2 text-xs text-muted-foreground"><span class="animate-pulse">Rendering diagram...</span></div></div><pre class="mermaid-raw hidden"><code>${escapeHtml(codeContent)}</code></pre></div>\n`;
            }

            const classAttr = langClass
                ? ` class="language-${escapeAttribute(langClass)}"`
                : '';
            const langLabel = langClass
                ? `<span class="code-block-lang">${escapeHtml(langClass)}</span>`
                : '';
            const codeContent = (text || '').replace(/\r?\n$/, '') + '\n';
            const highlighted = escaped
                ? null
                : highlightFenceBody(codeContent, langClass ?? '');
            const escapedCode =
                highlighted ??
                (escaped ? codeContent : escapeHtml(codeContent));
            return `<div class="code-block-wrapper group my-4"><div class="code-block-header"><div class="code-block-meta"><span class="code-block-dots" aria-hidden="true"><span class="code-block-dot bg-[#ff5f56]"></span><span class="code-block-dot bg-[#ffbd2e]"></span><span class="code-block-dot bg-[#27c93f]"></span></span>${langLabel}</div><button type="button" class="code-copy-btn" aria-label="Copy code" title="Copy code" data-code-copy>${COPY_ICON_SVG}</button></div><pre><code${classAttr}>${escapedCode}</code></pre></div>\n`;
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
export function renderMarkdownPreview(
    markdown: string,
    options?: RenderMarkdownPreviewOptions,
): string {
    checkboxIndex = 0;
    currentDisabled = options?.disabled ?? false;
    try {
        return markdownParser.parse(markdown, { async: false }) as string;
    } finally {
        checkboxIndex = 0;
        currentDisabled = false;
    }
}

/**
 * Toggles a GFM task list item at the given 0-based task index between '[ ]' and '[x]'.
 * Safely ignores YAML frontmatter, code blocks, and inline code.
 */
export function toggleTaskItem(markdown: string, taskIndex: number): string {
    if (taskIndex < 0) {
        return markdown;
    }

    const body = stripFrontmatter(markdown);
    const frontmatterOffset = markdown.length - body.length;

    const tree = markdownLanguage.parser.parse(body);
    let currentIndex = 0;
    const targetRanges: { from: number; to: number }[] = [];

    tree.iterate({
        enter(node) {
            if (node.name === 'TaskMarker') {
                if (currentIndex === taskIndex && targetRanges.length === 0) {
                    targetRanges.push({ from: node.from, to: node.to });
                }
                currentIndex++;
            }
        },
    });

    const targetRange = targetRanges[0];
    if (!targetRange) {
        return markdown;
    }

    const start = frontmatterOffset + targetRange.from;
    const end = frontmatterOffset + targetRange.to;
    const currentMarker = markdown.slice(start, end);

    if (!/^\[([ xX])\]$/.test(currentMarker)) {
        return markdown;
    }

    const isChecked = /x/i.test(currentMarker);
    const nextMarker = isChecked ? '[ ]' : '[x]';

    return markdown.slice(0, start) + nextMarker + markdown.slice(end);
}
