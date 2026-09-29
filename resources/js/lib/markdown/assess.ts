import { marked } from 'marked';
import type { MarkdownConverter } from './converter';

export type UnsupportedReason =
    | 'images'
    | 'html'
    | 'reference-links'
    | 'footnotes'
    | 'wiki-links'
    | 'unstable';

export type MarkdownAssessment =
    | { status: 'exact' }
    | {
          status: 'reformat';
          firstDifference: { line: number; before: string; after: string };
      }
    | { status: 'unsupported'; reasons: UnsupportedReason[] };

/**
 * Human labels for {@link UnsupportedReason}, used in the "This note uses
 * Markdown the rich-text editor can't preserve yet ({labels})" notice.
 */
export const UNSUPPORTED_LABELS: Record<UnsupportedReason, string> = {
    images: 'images',
    html: 'HTML',
    'reference-links': 'reference-style links',
    footnotes: 'footnotes',
    'wiki-links': 'wiki links ([[…]])',
    unstable: "formatting the editor can't reproduce exactly",
};

const trimEndNewlines = (s: string): string => s.replace(/\n+$/, '');

const FOOTNOTE_PATTERN = /\[\^[^\]\s]+\]/;
const WIKI_LINK_PATTERN = /\[\[[^\]\n]+\]\]/;

type MarkedToken = {
    type: string;
    tokens?: MarkedToken[];
    items?: MarkedToken[];
    header?: { tokens: MarkedToken[] }[];
    rows?: { tokens: MarkedToken[] }[][];
};

/**
 * Recursively walks a `marked` lexer token tree (top-level tokens, inline
 * `tokens`, list `items`, and table `header`/`rows` cells) collecting the
 * structural constructs the rich editor's schema has no node for.
 */
function collectStructuralReasons(body: string): Set<UnsupportedReason> {
    const reasons = new Set<UnsupportedReason>();
    const tokens = marked.lexer(body, {
        gfm: true,
    }) as unknown as MarkedToken[] & {
        links?: Record<string, unknown>;
    };

    function walk(list: MarkedToken[] | undefined): void {
        if (!list) {
            return;
        }

        for (const token of list) {
            switch (token.type) {
                case 'html':
                case 'tag':
                    reasons.add('html');
                    break;
                case 'image':
                    reasons.add('images');
                    break;
                case 'def':
                    reasons.add('reference-links');
                    break;
                default:
                    break;
            }

            walk(token.tokens);
            walk(token.items);

            for (const cell of token.header ?? []) {
                walk(cell.tokens);
            }

            for (const row of token.rows ?? []) {
                for (const cell of row) {
                    walk(cell.tokens);
                }
            }
        }
    }

    walk(tokens);

    if (tokens.links && Object.keys(tokens.links).length > 0) {
        reasons.add('reference-links');
    }

    return reasons;
}

function firstDifferenceOf(
    before: string,
    after: string,
): { line: number; before: string; after: string } {
    const beforeLines = before.split('\n');
    const afterLines = after.split('\n');
    const max = Math.max(beforeLines.length, afterLines.length);

    for (let i = 0; i < max; i++) {
        const beforeLine = beforeLines[i] ?? '';
        const afterLine = afterLines[i] ?? '';

        if (beforeLine !== afterLine) {
            return { line: i + 1, before: beforeLine, after: afterLine };
        }
    }

    return { line: max + 1, before: '', after: '' };
}

/**
 * Classifies a note body on open (ADR `markdown-conversion-and-fidelity`),
 * in order:
 *   1. structural tokens the schema can't hold (`table`, `html`/`tag`,
 *      `image`, `def`, or a non-empty reference-link map) -> `unsupported`;
 *   2. the round trip equals the body (ignoring trailing newlines) ->
 *      `exact`;
 *   3. footnote or wiki-link text patterns -> `unsupported`;
 *   4. an idempotent, document-preserving round trip -> `reformat`;
 *      otherwise -> `unsupported('unstable')`.
 */
export function assessMarkdown(
    body: string,
    converter: MarkdownConverter,
): MarkdownAssessment {
    const structural = collectStructuralReasons(body);

    if (structural.size > 0) {
        return { status: 'unsupported', reasons: [...structural] };
    }

    const r1 = converter.roundTrip(body);

    if (trimEndNewlines(r1) === trimEndNewlines(body)) {
        return { status: 'exact' };
    }

    const textReasons = new Set<UnsupportedReason>();

    if (FOOTNOTE_PATTERN.test(body)) {
        textReasons.add('footnotes');
    }

    if (WIKI_LINK_PATTERN.test(body)) {
        textReasons.add('wiki-links');
    }

    if (textReasons.size > 0) {
        return { status: 'unsupported', reasons: [...textReasons] };
    }

    const r2 = converter.roundTrip(r1);
    const idempotent = r2 === r1;
    const sameDocument =
        idempotent &&
        JSON.stringify(converter.parse(r1)) ===
            JSON.stringify(converter.parse(body));

    if (idempotent && sameDocument) {
        return {
            status: 'reformat',
            firstDifference: firstDifferenceOf(body, r1),
        };
    }

    return { status: 'unsupported', reasons: ['unstable'] };
}
