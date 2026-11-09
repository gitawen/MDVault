import { defaultHighlightStyle, HighlightStyle } from '@codemirror/language';
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
 * Dark palette for code tokens (also used by the rendered preview's `tok-*`
 * classes in `app.css`, keep the two in sync).
 */
const DARK_CODE_STYLES = [
    {
        tag: [tags.keyword, tags.modifier, tags.operatorKeyword],
        color: '#c678dd',
    },
    { tag: [tags.controlKeyword, tags.moduleKeyword], color: '#c678dd' },
    {
        tag: [tags.string, tags.special(tags.string), tags.inserted],
        color: '#98c379',
    },
    { tag: tags.regexp, color: '#56b6c2' },
    {
        tag: [tags.number, tags.bool, tags.atom, tags.null, tags.self],
        color: '#d19a66',
    },
    {
        tag: [
            tags.comment,
            tags.lineComment,
            tags.blockComment,
            tags.docComment,
        ],
        color: '#7f8aa3',
        fontStyle: 'italic',
    },
    { tag: [tags.variableName, tags.name], color: '#e06c75' },
    {
        tag: [
            tags.definition(tags.variableName),
            tags.definition(tags.propertyName),
        ],
        color: '#61afef',
    },
    {
        tag: [
            tags.function(tags.variableName),
            tags.function(tags.propertyName),
        ],
        color: '#61afef',
    },
    { tag: tags.propertyName, color: '#e5c07b' },
    { tag: [tags.typeName, tags.className, tags.namespace], color: '#e5c07b' },
    {
        tag: [
            tags.operator,
            tags.derefOperator,
            tags.compareOperator,
            tags.logicOperator,
            tags.arithmeticOperator,
            tags.definitionOperator,
            tags.updateOperator,
        ],
        color: '#56b6c2',
    },
    {
        tag: [
            tags.punctuation,
            tags.separator,
            tags.bracket,
            tags.angleBracket,
            tags.squareBracket,
            tags.paren,
            tags.brace,
        ],
        color: '#abb2bf',
    },
    { tag: tags.tagName, color: '#e06c75' },
    { tag: tags.attributeName, color: '#d19a66' },
    { tag: tags.attributeValue, color: '#98c379' },
    {
        tag: [
            tags.labelName,
            tags.escape,
            tags.constant(tags.name),
            tags.standard(tags.name),
        ],
        color: '#d19a66',
    },
    { tag: tags.deleted, color: '#e06c75' },
    { tag: tags.invalid, color: '#ff6b6b' },
];

/**
 * Syntax colours for Markdown tags and fenced-code tokens. Always registered
 * as a non-fallback highlighter by the theme compartment, which disables any
 * `{ fallback: true }` highlighter, so light mode returns
 * `defaultHighlightStyle` itself (unchanged Markdown look plus code-token
 * colours) and dark mode a dark replacement. Code tokens listed after the
 * Markdown rules never touch `tags.monospace`, which only styles Markdown's own
 * code nodes; nested-language tokens carry their own tags and keep their colours.
 */
export function appHighlightStyle(isDark: boolean): HighlightStyle {
    if (!isDark) {
        return defaultHighlightStyle;
    }

    return HighlightStyle.define(
        [
            ...DARK_CODE_STYLES,
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
