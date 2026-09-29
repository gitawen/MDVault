import type { Editor, Extensions } from '@tiptap/core';
import { TaskItem, TaskList } from '@tiptap/extension-list';
import { Markdown } from '@tiptap/markdown';
import StarterKit from '@tiptap/starter-kit';

const BARE_URL_LINK_PATTERN = /\[(https?:\/\/[^\]\s]+)\]\(\1\)/g;

/**
 * Rewrites a rendered `[url](url)` (a GFM bare-URL autolink) back to the
 * bare `url`, so a note written with a plain URL round-trips exactly. This
 * is the one serializer override in the extension list (ADR
 * `markdown-conversion-and-fidelity`); see the fixture `exact/links.md`.
 *
 * `@tiptap/markdown` renders every mark through a placeholder-substitution
 * pass (the real text is only spliced in afterwards), so a mark-level
 * `renderMarkdown` override sees a placeholder instead of the real text and
 * can never compare it against the href. Doing the rewrite as a string pass
 * over the finished Markdown avoids that; every caller applies it through
 * `serializeEditor()` below, never directly.
 */
export function applyMarkdownOverrides(markdown: string): string {
    return markdown.replace(BARE_URL_LINK_PATTERN, '$1');
}

/**
 * The one place that ever calls `editor.getMarkdown()` (A2, T12 grep): every
 * serialization path — the visible editor and the headless converter alike
 * — must go through the same overrides, or a note assessed `exact` on open
 * can be silently rewritten (e.g. every bare URL turned into `[url](url)`)
 * by its own first edit, with no user consent.
 */
export function serializeEditor(editor: Editor): string {
    return applyMarkdownOverrides(editor.getMarkdown());
}

/**
 * The single Tiptap extension list shared by the visible editor, the
 * headless converter and the tests (ADR `markdown-conversion-and-fidelity`).
 *
 * Underline and alignment are omitted: they have no reliable Markdown
 * representation (§20), and Tiptap's non-standard `++underline++` syntax
 * would corrupt text such as "C++ and C++". Serializer overrides belong
 * only in this file, and each one needs a fixture under
 * `tests/js/fixtures/markdown/`.
 */
export function markdownExtensions(): Extensions {
    return [
        StarterKit.configure({
            underline: false,
            trailingNode: false,
            link: {
                openOnClick: false,
                autolink: true,
                linkOnPaste: true,
                defaultProtocol: 'https',
            },
        }),
        TaskList,
        TaskItem.configure({ nested: true }),
        Markdown.configure({
            markedOptions: { gfm: true },
            indentation: { style: 'space', size: 2 },
        }),
    ];
}
