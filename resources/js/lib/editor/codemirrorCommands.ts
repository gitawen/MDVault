import { redo, undo } from '@codemirror/commands';
import type { EditorState } from '@codemirror/state';

export type CommandTarget = {
    state: EditorState;
    dispatch: (...specs: any[]) => void;
    focus?: () => void;
};

/**
 * Matches the line prefix for a heading, bullet/ordered/task list item or
 * blockquote, so it can be stripped by `clearLinePrefix`/`clearFormatting`.
 */
export const LINE_PREFIX_PATTERN =
    /^(#{1,6}\s+|[-*+]\s+(\[[ xX]\]\s+)?|\d+\.\s+|>+\s*)/;

/**
 * Toggles an inline markdown marker around the current selection or cursor.
 * E.g., `**` for bold, `*` for italic, `~~` for strike, `` ` `` for code.
 */
export function toggleInlineMark(view: CommandTarget, marker: string): boolean {
    const { state, dispatch } = view;
    const { from, to } = state.selection.main;
    const selectedText = state.sliceDoc(from, to);
    const markerLen = marker.length;

    // Check if the selection is already wrapped in this marker
    const beforeFrom = Math.max(0, from - markerLen);
    const afterTo = Math.min(state.doc.length, to + markerLen);
    const outerBefore = state.sliceDoc(beforeFrom, from);
    const outerAfter = state.sliceDoc(to, afterTo);

    // Reject a boundary that is part of a longer run of the same character,
    // e.g. selecting "bold" inside "**bold**" while toggling `*` for italic
    // must not be mistaken for a single-`*` wrap around the selection.
    const extraBefore = state.sliceDoc(Math.max(0, beforeFrom - 1), beforeFrom);
    const extraAfter = state.sliceDoc(
        afterTo,
        Math.min(state.doc.length, afterTo + 1),
    );
    const isPartOfLongerRun =
        extraBefore === marker[0] || extraAfter === marker[marker.length - 1];

    if (outerBefore === marker && outerAfter === marker && !isPartOfLongerRun) {
        // Unwrap: remove outer markers
        dispatch({
            changes: [
                { from: beforeFrom, to: from, insert: '' },
                { from: to, to: afterTo, insert: '' },
            ],
            selection: { anchor: beforeFrom, head: to - markerLen },
            userEvent: 'format.unwrap',
        });
        view.focus?.();
        return true;
    }

    if (
        selectedText.startsWith(marker) &&
        selectedText.endsWith(marker) &&
        selectedText.length >= markerLen * 2
    ) {
        // Selection includes markers, unwrap inside
        const inner = selectedText.slice(
            markerLen,
            selectedText.length - markerLen,
        );
        dispatch({
            changes: { from, to, insert: inner },
            selection: { anchor: from, head: from + inner.length },
            userEvent: 'format.unwrap',
        });
        view.focus?.();
        return true;
    }

    // Wrap selection or insert placeholder
    if (from === to) {
        const placeholder = 'text';
        dispatch({
            changes: { from, to, insert: `${marker}${placeholder}${marker}` },
            selection: {
                anchor: from + markerLen,
                head: from + markerLen + placeholder.length,
            },
            userEvent: 'format.wrap',
        });
    } else {
        dispatch({
            changes: { from, to, insert: `${marker}${selectedText}${marker}` },
            selection: { anchor: from + markerLen, head: to + markerLen },
            userEvent: 'format.wrap',
        });
    }

    view.focus?.();
    return true;
}

/**
 * Toggles line prefix (heading, bullet list, quote, etc.) across all selected lines.
 *
 * `prefix` must be a non-empty, single-line marker. Use `clearLinePrefix` to
 * strip whatever prefix is already present instead of passing `''` here.
 */
export function toggleLinePrefix(view: CommandTarget, prefix: string): boolean {
    const { state, dispatch } = view;
    const { from, to } = state.selection.main;

    const startLine = state.doc.lineAt(from);
    const endLine = state.doc.lineAt(to);

    const changes = [];
    const lines = [];

    for (let i = startLine.number; i <= endLine.number; i++) {
        lines.push(state.doc.line(i));
    }

    // Determine if all lines currently have the prefix
    const allHavePrefix =
        prefix !== '' && lines.every((line) => line.text.startsWith(prefix));

    for (const line of lines) {
        if (allHavePrefix) {
            changes.push({
                from: line.from,
                to: line.from + prefix.length,
                insert: '',
            });
        } else {
            // Remove any other common heading or list prefix if switching
            let cleanFrom = line.from;
            const text = line.text;
            const match = text.match(LINE_PREFIX_PATTERN);
            if (match) {
                cleanFrom = line.from + match[0].length;
            }

            changes.push({
                from: line.from,
                to: cleanFrom,
                insert: prefix,
            });
        }
    }

    dispatch({
        changes,
        userEvent: 'format.prefix',
    });

    view.focus?.();
    return true;
}

/**
 * Counts how many characters of `text`'s start are made up of stacked
 * line-prefix markers (heading/list/quote), fully unwrapping nested
 * prefixes such as `> ## ` (blockquote + heading) or `> - ` (blockquote +
 * bullet) in one pass. Returns `0` when there is no prefix to strip.
 */
function stackedLinePrefixLength(text: string): number {
    let stripped = 0;
    let rest = text;
    let match = rest.match(LINE_PREFIX_PATTERN);
    while (match) {
        stripped += match[0].length;
        rest = rest.slice(match[0].length);
        match = rest.match(LINE_PREFIX_PATTERN);
    }
    return stripped;
}

/**
 * Strips every stacked heading/list/quote prefix present on every selected
 * line (e.g. `> ## Quoted` -> `Quoted` in one call, not `## Quoted`).
 * Unlike `toggleLinePrefix(view, '')`, this is not a no-op: it actually
 * removes the matched prefix text. Returns `false` (no dispatch) when no
 * touched line has a prefix to remove.
 */
export function clearLinePrefix(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const { from, to } = state.selection.main;

    const startLine = state.doc.lineAt(from);
    const endLine = state.doc.lineAt(to);

    const changes: { from: number; to: number; insert: string }[] = [];
    let changed = false;

    for (let i = startLine.number; i <= endLine.number; i++) {
        const line = state.doc.line(i);
        const stripped = stackedLinePrefixLength(line.text);
        if (stripped > 0) {
            changes.push({
                from: line.from,
                to: line.from + stripped,
                insert: '',
            });
            changed = true;
        }
    }

    if (!changed) {
        return false;
    }

    dispatch({ changes, userEvent: 'format.prefix' });
    view.focus?.();
    return true;
}

export function setHeading(
    view: CommandTarget,
    level: 1 | 2 | 3 | 4 | 5 | 6,
): boolean {
    const prefix = `${'#'.repeat(level)} `;
    return toggleLinePrefix(view, prefix);
}

export function toggleBold(view: CommandTarget): boolean {
    return toggleInlineMark(view, '**');
}

export function toggleItalic(view: CommandTarget): boolean {
    return toggleInlineMark(view, '*');
}

export function toggleStrike(view: CommandTarget): boolean {
    return toggleInlineMark(view, '~~');
}

export function toggleInlineCode(view: CommandTarget): boolean {
    return toggleInlineMark(view, '`');
}

export function toggleBulletList(view: CommandTarget): boolean {
    return toggleLinePrefix(view, '- ');
}

export function toggleOrderedList(view: CommandTarget): boolean {
    return toggleLinePrefix(view, '1. ');
}

export function toggleTaskList(view: CommandTarget): boolean {
    return toggleLinePrefix(view, '- [ ] ');
}

export function toggleBlockquote(view: CommandTarget): boolean {
    return toggleLinePrefix(view, '> ');
}

export function insertCodeBlock(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const { from, to } = state.selection.main;
    const selectedText = state.sliceDoc(from, to);

    const insertion = `\`\`\`\n${selectedText || 'code'}\n\`\`\`\n`;
    dispatch({
        changes: { from, to, insert: insertion },
        selection: {
            anchor: from + 4,
            head: from + 4 + (selectedText ? selectedText.length : 4),
        },
        userEvent: 'format.codeblock',
    });

    view.focus?.();
    return true;
}

/**
 * Inserts a GFM table skeleton after the line containing the selection end,
 * following `insertHorizontalRule`'s convention: never touches the current
 * line's own text, never deletes a selection, and inserts exactly once
 * regardless of selection size. The first header cell is left selected so
 * it can be typed over immediately.
 */
export function insertTable(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const { to } = state.selection.main;
    const line = state.doc.lineAt(to);
    const insertPos = line.to;

    const hasTrailingBlankLine =
        line.number < state.doc.lines &&
        state.doc.line(line.number + 1).text.trim() === '';

    const tableBody =
        '| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |';
    const insertion = hasTrailingBlankLine
        ? `\n${tableBody}\n`
        : `\n\n${tableBody}\n`;

    const headerStart = insertPos + insertion.indexOf('Header 1');
    const headerEnd = headerStart + 'Header 1'.length;

    dispatch({
        changes: { from: insertPos, to: insertPos, insert: insertion },
        selection: { anchor: headerStart, head: headerEnd },
        userEvent: 'format.table',
    });

    view.focus?.();
    return true;
}

/**
 * Inserts `\n---\n` after the current line, normalising the blank line
 * that should separate the rule from surrounding content. Never touches the
 * current line's own text (list/heading/quote markers survive), and always
 * inserts exactly one rule regardless of how large the selection is.
 */
export function insertHorizontalRule(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const { to } = state.selection.main;
    const line = state.doc.lineAt(to);
    const insertPos = line.to;

    const hasTrailingBlankLine =
        line.number < state.doc.lines &&
        state.doc.line(line.number + 1).text.trim() === '';

    const insertion = hasTrailingBlankLine ? '\n---\n' : '\n\n---\n';

    dispatch({
        changes: { from: insertPos, to: insertPos, insert: insertion },
        selection: { anchor: insertPos + insertion.length },
        userEvent: 'format.rule',
    });

    view.focus?.();
    return true;
}

function stripInlineMarks(text: string): string {
    return text
        .replace(/\*\*(.+?)\*\*/g, '$1')
        .replace(/__(.+?)__/g, '$1')
        .replace(/~~(.+?)~~/g, '$1')
        .replace(/`(.+?)`/g, '$1')
        .replace(/\*(.+?)\*/g, '$1')
        .replace(/_(.+?)_/g, '$1');
}

/**
 * Removes inline marks (`**`, `__`, `~~`, `*`, `_`, `` ` ``) from the
 * selected text and strips every stacked line prefix from every touched
 * line (consistent with `clearLinePrefix`'s full unwrap), in one
 * transaction. Returns `false` when there was nothing to clear.
 */
export function clearFormatting(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const { from, to } = state.selection.main;

    const startLine = state.doc.lineAt(from);
    const endLine = state.doc.lineAt(to);

    const changes: { from: number; to: number; insert: string }[] = [];
    let changed = false;
    let inlineFrom = from;

    for (let i = startLine.number; i <= endLine.number; i++) {
        const line = state.doc.line(i);
        const stripped = stackedLinePrefixLength(line.text);
        if (stripped > 0) {
            const prefixEnd = line.from + stripped;
            changes.push({ from: line.from, to: prefixEnd, insert: '' });
            changed = true;
            if (i === startLine.number && prefixEnd > inlineFrom) {
                inlineFrom = prefixEnd;
            }
        }
    }

    if (inlineFrom <= to) {
        const selectedText = state.sliceDoc(inlineFrom, to);
        const stripped = stripInlineMarks(selectedText);
        if (stripped !== selectedText) {
            changes.push({ from: inlineFrom, to, insert: stripped });
            changed = true;
        }
    }

    if (!changed) {
        return false;
    }

    dispatch({ changes, userEvent: 'format.clear' });
    view.focus?.();
    return true;
}

/**
 * Inserts a `[text](url)` link. With no selection and no explicit `text`,
 * falls back to the placeholder label `link` (rather than an empty label)
 * and leaves it selected so it can be typed over. Pass `range` to replace
 * an existing link instead of the current selection.
 */
export function insertLink(
    view: CommandTarget,
    url: string,
    text?: string,
    range?: { from: number; to: number },
): boolean {
    const { state, dispatch } = view;
    const { from, to } = range ?? state.selection.main;
    const selectedText = text || state.sliceDoc(from, to) || 'link';

    const insertion = `[${selectedText}](${url})`;
    const labelStart = from + 1;
    const labelEnd = labelStart + selectedText.length;

    dispatch({
        changes: { from, to, insert: insertion },
        selection: { anchor: labelStart, head: labelEnd },
        userEvent: 'format.link',
    });

    view.focus?.();
    return true;
}

const LINK_PATTERN = /\[([^\]]*)\]\(([^)]*)\)/g;

/**
 * Finds a `[text](href)` link on the caret's line that contains `pos`.
 */
export function linkAt(
    state: EditorState,
    pos: number,
): { from: number; to: number; text: string; href: string } | null {
    const line = state.doc.lineAt(pos);
    const regex = new RegExp(LINK_PATTERN);
    let match: RegExpExecArray | null;

    while ((match = regex.exec(line.text)) !== null) {
        const start = line.from + match.index;
        const end = start + match[0].length;
        if (pos >= start && pos <= end) {
            return { from: start, to: end, text: match[1], href: match[2] };
        }
    }

    return null;
}

/**
 * Replaces the link under the caret with its label text, leaving the label
 * selected. Returns `false` when the caret is not inside a link.
 */
export function removeLink(view: CommandTarget): boolean {
    const { state, dispatch } = view;
    const pos = state.selection.main.head;
    const link = linkAt(state, pos);
    if (!link) {
        return false;
    }

    dispatch({
        changes: { from: link.from, to: link.to, insert: link.text },
        selection: { anchor: link.from, head: link.from + link.text.length },
        userEvent: 'format.unlink',
    });

    view.focus?.();
    return true;
}

/**
 * Whether the selection is immediately wrapped by `marker` on both sides,
 * guarding against matching part of a longer run of the same character.
 */
export function isInlineMarkActive(
    state: EditorState,
    marker: string,
): boolean {
    const { from, to } = state.selection.main;
    const markerLen = marker.length;
    const beforeFrom = Math.max(0, from - markerLen);
    const afterTo = Math.min(state.doc.length, to + markerLen);
    const outerBefore = state.sliceDoc(beforeFrom, from);
    const outerAfter = state.sliceDoc(to, afterTo);

    if (outerBefore !== marker || outerAfter !== marker) {
        return false;
    }

    const extraBefore = state.sliceDoc(Math.max(0, beforeFrom - 1), beforeFrom);
    const extraAfter = state.sliceDoc(
        afterTo,
        Math.min(state.doc.length, afterTo + 1),
    );

    return (
        extraBefore !== marker[0] && extraAfter !== marker[marker.length - 1]
    );
}

/**
 * Whether the caret's line starts with `prefix`. Ordered-list prefixes
 * (`'1. '`) match any ordinal (`/^\d+\.\s/`), not the literal digit `1`.
 */
export function isLinePrefixActive(
    state: EditorState,
    prefix: string,
): boolean {
    const { from } = state.selection.main;
    const line = state.doc.lineAt(from);

    if (/^\d+\.\s$/.test(prefix)) {
        return /^\d+\.\s/.test(line.text);
    }

    return line.text.startsWith(prefix);
}

export function executeUndo(view: CommandTarget): boolean {
    return undo(view);
}

export function executeRedo(view: CommandTarget): boolean {
    return redo(view);
}
