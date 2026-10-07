import {
    defaultKeymap,
    history,
    historyKeymap,
    indentWithTab,
} from '@codemirror/commands';
import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import {
    defaultHighlightStyle,
    indentUnit,
    syntaxHighlighting,
} from '@codemirror/language';
import { Compartment, EditorState, type Extension } from '@codemirror/state';
import {
    EditorView,
    highlightActiveLine,
    highlightActiveLineGutter,
    keymap,
    lineNumbers,
} from '@codemirror/view';
import type { EditorPreferences } from '@/types';

import { markdownFormattingKeymap } from './codemirrorKeymap';
import { livePreview } from './codemirrorLivePreview';
import { appEditorTheme, appHighlightStyle } from './codemirrorTheme';

export type CodeMirrorCompartments = {
    lineNumbers: Compartment;
    wordWrap: Compartment;
    indentUnit: Compartment;
    editable: Compartment;
    theme: Compartment;
    typography: Compartment;
};

export function createCodeMirrorCompartments(): CodeMirrorCompartments {
    return {
        lineNumbers: new Compartment(),
        wordWrap: new Compartment(),
        indentUnit: new Compartment(),
        editable: new Compartment(),
        theme: new Compartment(),
        typography: new Compartment(),
    };
}

export function getFontFamilyString(
    family: EditorPreferences['font_family'],
): string {
    switch (family) {
        case 'serif':
            return 'ui-serif, Georgia, Cambria, "Times New Roman", Times, serif';
        case 'mono':
            return 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace';
        default:
            return 'ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji"';
    }
}

export function buildTypographyTheme(
    preferences: EditorPreferences,
): Extension {
    return EditorView.theme({
        '&': {
            height: '100%',
            fontSize: `${preferences.font_size}px`,
            lineHeight: String(preferences.line_height),
        },
        '.cm-scroller': {
            fontFamily: getFontFamilyString(preferences.font_family),
            overflow: 'auto',
        },
        '.cm-content': {
            padding: '1rem',
        },
        '.cm-gutters': {
            fontSize: '0.85em',
        },
        '.cm-line': {
            paddingLeft: '4px',
            paddingRight: '4px',
        },
        '&.cm-focused': {
            outline: 'none',
        },
    });
}

export function buildCodeMirrorExtensions(options: {
    compartments: CodeMirrorCompartments;
    preferences: EditorPreferences;
    editable: boolean;
    readonly: boolean;
    isDark: boolean;
    onChange: (text: string) => void;
    onUpdate?: (view: EditorView) => void;
    onLink?: () => void;
}): Extension[] {
    const {
        compartments,
        preferences,
        editable,
        readonly,
        isDark,
        onChange,
        onUpdate,
        onLink,
    } = options;

    const isEditable = editable && !readonly;

    return [
        history(),
        markdown({ base: markdownLanguage }),
        livePreview(),
        syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
        highlightActiveLine(),
        highlightActiveLineGutter(),
        markdownFormattingKeymap({ onLink: onLink ?? (() => {}) }),
        keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),

        compartments.lineNumbers.of(
            preferences.show_line_numbers ? lineNumbers() : [],
        ),
        compartments.wordWrap.of(
            preferences.word_wrap !== false ? EditorView.lineWrapping : [],
        ),
        compartments.indentUnit.of(
            indentUnit.of(' '.repeat(preferences.indent_size ?? 4)),
        ),
        compartments.editable.of([
            EditorView.editable.of(isEditable),
            EditorState.readOnly.of(!isEditable),
            EditorView.theme({
                '&': {
                    cursor: isEditable ? 'text' : 'default',
                },
            }),
        ]),
        compartments.typography.of(buildTypographyTheme(preferences)),
        // Registered after `typography` so it keeps winning the precedence
        // fight for properties both touch (FR-18); the theme only sets
        // colour, `typography` only sets font/size/padding.
        compartments.theme.of([
            appEditorTheme(isDark),
            syntaxHighlighting(appHighlightStyle(isDark)),
        ]),

        EditorView.updateListener.of((update) => {
            if (update.docChanged) {
                onChange(update.state.doc.toString());
            }
            if (
                onUpdate &&
                (update.docChanged ||
                    update.selectionSet ||
                    update.focusChanged)
            ) {
                onUpdate(update.view);
            }
        }),
    ];
}
