/**
 * Rich mode's combined save unit (Phase 4 Revision 3): the frontmatter
 * panel's raw YAML (`null` when the note has no frontmatter block) plus the
 * Tiptap body. Encoding both together as one string lets `noteSaver` treat
 * a frontmatter-only edit exactly like a body edit — dirty tracking,
 * autosave, the flush loop, the guard, `overwrite` and `discard` all keep
 * working unchanged.
 */
export type RichContent = {
    frontmatter: string | null;
    body: string;
};

/** Deterministic: array order is fixed, so equal content always encodes equal. */
export function encodeRichContent(content: RichContent): string {
    return JSON.stringify([content.frontmatter, content.body]);
}

export function decodeRichContent(encoded: string): RichContent {
    const [frontmatter, body] = JSON.parse(encoded) as [string | null, string];

    return { frontmatter, body };
}

export function richSavePayload(content: RichContent): {
    content: string;
    has_frontmatter: boolean;
    frontmatter: string;
} {
    return {
        content: content.body,
        has_frontmatter: content.frontmatter !== null,
        frontmatter: content.frontmatter ?? '',
    };
}

/** Clipboard only: a full-file preview text, never sent to the server. */
export function richContentAsText(content: RichContent): string {
    if (content.frontmatter === null) {
        return content.body;
    }

    const separator = content.frontmatter === '' ? '' : '\n';

    return `---\n${content.frontmatter}${separator}---\n\n${content.body}`;
}

const FRONTMATTER_DASH_LINE = /^---[ \t]*$/m;

/** The same rule the server refuses a save on; used for an inline hint. */
export function frontmatterProblem(yaml: string): string | null {
    return FRONTMATTER_DASH_LINE.test(yaml)
        ? 'A line of three dashes (---) would end the frontmatter early. Remove it before saving.'
        : null;
}
