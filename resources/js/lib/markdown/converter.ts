import { Editor } from '@tiptap/core';
import type { JSONContent } from '@tiptap/core';
import { markdownExtensions, serializeEditor } from './extensions';

export type MarkdownConverter = {
    parse(markdown: string): JSONContent;
    serialize(doc: JSONContent): string;
    roundTrip(markdown: string): string;
    destroy(): void;
};

const EMPTY_DOC: JSONContent = { type: 'doc', content: [] };

/**
 * A headless Markdown <-> Tiptap JSON converter (ADR
 * `markdown-conversion-and-fidelity`). Every call creates and destroys its
 * own unmounted (`element: null`) editor, so calls never share undo history
 * or selection state.
 *
 * Blank input is special-cased: `@tiptap/markdown` only substitutes the
 * parsed JSON for the raw markdown string when parsing produced non-empty
 * content (see its `onBeforeCreate`); for a blank string it falls through
 * to treating the empty string as HTML, which needs a DOM `Editor` doesn't
 * have here. An empty note trivially round-trips to itself, so this never
 * needs the editor at all.
 */
export function createMarkdownConverter(): MarkdownConverter {
    return {
        parse(markdown: string): JSONContent {
            if (markdown.trim() === '') {
                return EMPTY_DOC;
            }

            const editor = new Editor({
                element: null,
                extensions: markdownExtensions(),
                content: markdown,
                contentType: 'markdown',
            });

            try {
                return editor.getJSON();
            } finally {
                editor.destroy();
            }
        },
        serialize(doc: JSONContent): string {
            const editor = new Editor({
                element: null,
                extensions: markdownExtensions(),
                content: doc,
                contentType: 'json',
            });

            try {
                return serializeEditor(editor);
            } finally {
                editor.destroy();
            }
        },
        roundTrip(markdown: string): string {
            if (markdown.trim() === '') {
                return '';
            }

            const editor = new Editor({
                element: null,
                extensions: markdownExtensions(),
                content: markdown,
                contentType: 'markdown',
            });

            try {
                return serializeEditor(editor);
            } finally {
                editor.destroy();
            }
        },
        destroy(): void {
            // No persistent state: each operation creates and destroys its
            // own editor. Present for interface symmetry with the browser's
            // module singleton.
        },
    };
}

let singleton: MarkdownConverter | null = null;

/**
 * A lazy, module-level singleton for the browser: a converter shared by
 * every fidelity check call site (it holds no per-call state to leak).
 */
export function getMarkdownConverter(): MarkdownConverter {
    return (singleton ??= createMarkdownConverter());
}
