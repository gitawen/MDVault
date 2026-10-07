/**
 * Protocols the note editor and the rendered Markdown preview will ever
 * navigate to. Anything else (`javascript:`, `data:`, `vbscript:`, ...) is
 * rejected — this is the single source of truth shared by the Link dialog
 * (`LinkDialog.vue`) and the rendered preview (`markdownPreview.ts`).
 */
export const ALLOWED_LINK_PROTOCOLS = new Set(['http:', 'https:', 'mailto:']);

/**
 * Whether `href` is safe to insert as a link or navigate to. A relative
 * reference (no `scheme:` prefix, e.g. an in-vault relative path or a `#`
 * anchor) is allowed — it can never invoke a protocol handler. Anything
 * with an explicit scheme must be in `ALLOWED_LINK_PROTOCOLS`.
 */
export function isAllowedHref(href: string): boolean {
    const trimmed = href.trim();
    if (trimmed === '') {
        return false;
    }

    const match = trimmed.match(/^([a-zA-Z][a-zA-Z0-9+.-]*):/);
    if (!match) {
        return true;
    }

    return ALLOWED_LINK_PROTOCOLS.has(`${match[1].toLowerCase()}:`);
}
