import { HighlightStyle } from '@codemirror/language';
import type { Extension } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { tags } from '@lezer/highlight';

/**
 * CodeMirror's own editor chrome (background, gutters, caret, selection),
 * built on the app's CSS variables instead of `@codemirror/theme-one-dark`'s
 * hard-coded palette, so dark mode matches the card it sits in and light
 * mode is no longer entirely unstyled (FR-18). Backgrounds stay transparent
 * so `bg-card` on the surrounding `SourceEditor.vue` wrapper shows through.
 */
export function appEditorTheme(isDark: boolean): Extension {
    return EditorView.theme(
        {
            '&': {
                backgroundColor: 'transparent',
                color: 'var(--card-foreground)',
            },
            '.cm-gutters': {
                backgroundColor: 'transparent',
                color: 'var(--muted-foreground)',
                borderRight: '1px solid var(--border)',
            },
            '.cm-activeLine': {
                backgroundColor: 'var(--muted)',
            },
            '.cm-activeLineGutter': {
                backgroundColor: 'var(--muted)',
            },
            '.cm-content': {
                caretColor: 'var(--primary)',
            },
            '.cm-cursor, .cm-dropCursor': {
                borderLeftColor: 'var(--primary)',
            },
            '.cm-selectionBackground, .cm-content ::selection': {
                backgroundColor:
                    'color-mix(in srgb, var(--primary) 30%, transparent)',
            },
            '.cm-panels': {
                backgroundColor: 'var(--card)',
                color: 'var(--card-foreground)',
            },
        },
        { dark: isDark },
    );
}

/**
 * Syntax colours for Markdown tags. Light mode returns an empty style so
 * `defaultHighlightStyle` (registered with `{ fallback: true }` in
 * `codemirror.ts`) keeps governing light-mode token colours unchanged —
 * only dark mode needed a replacement for `oneDark`'s colours.
 */
export function appHighlightStyle(isDark: boolean): HighlightStyle {
    if (!isDark) {
        return HighlightStyle.define([]);
    }

    return HighlightStyle.define(
        [
            { tag: tags.heading, color: 'var(--primary)', fontWeight: '700' },
            { tag: tags.strong, fontWeight: '700' },
            { tag: tags.emphasis, fontStyle: 'italic' },
            { tag: tags.strikethrough, textDecoration: 'line-through' },
            {
                tag: tags.link,
                color: 'var(--primary)',
                textDecoration: 'underline',
            },
            { tag: tags.url, color: 'var(--primary)' },
            { tag: tags.monospace, color: 'var(--muted-foreground)' },
            { tag: tags.quote, color: 'var(--muted-foreground)' },
            { tag: tags.contentSeparator, color: 'var(--border)' },
            {
                tag: tags.processingInstruction,
                color: 'var(--muted-foreground)',
            },
            { tag: tags.meta, color: 'var(--muted-foreground)' },
        ],
        { themeType: 'dark' },
    );
}
