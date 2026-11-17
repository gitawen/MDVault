import {
    defaultKeymap,
    history,
    historyKeymap,
    indentWithTab,
} from '@codemirror/commands';
import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import { languages } from '@codemirror/language-data';
import { indentUnit, syntaxHighlighting } from '@codemirror/language';
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
    onMermaid?: () => void;
    onImage?: () => void;
    onPasteImage?: (file: File) => void;
    onDropImages?: (files: File[], pos: number | null) => void;
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
        onMermaid,
        onImage,
        onPasteImage,
        onDropImages,
    } = options;

    const isEditable = editable && !readonly;

    return [
        history(),
        markdown({ base: markdownLanguage, codeLanguages: languages }),
        livePreview(),
        highlightActiveLine(),
        highlightActiveLineGutter(),
        markdownFormattingKeymap({
            onLink: onLink ?? (() => {}),
            onMermaid,
            onImage,
        }),
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
        EditorView.domEventHandlers({
            paste(event, view) {
                if (view.state.readOnly || !onPasteImage) {
                    return false;
                }

                const plainText =
                    event.clipboardData?.getData('text/plain') ?? '';
                if (plainText !== '') {
                    return false;
                }

                const files = extractImageFilesFromClipboard(
                    event.clipboardData,
                );
                if (files.length === 0) {
                    return false;
                }

                event.preventDefault();
                onPasteImage(files[0]);
                return true;
            },
            drop(event, view) {
                if (view.state.readOnly || !onDropImages) {
                    return false;
                }

                const files = extractImageFilesFromDataTransfer(
                    event.dataTransfer,
                );
                if (files.length === 0) {
                    return false;
                }

                event.preventDefault();
                const pos = view.posAtCoords({
                    x: event.clientX,
                    y: event.clientY,
                });
                onDropImages(files, pos);
                return true;
            },
        }),
    ];
}

function isImageLikeFile(file: File): boolean {
    const mime = file.type.trim().toLowerCase();
    if (mime.startsWith('image/')) {
        return true;
    }

    return /\.(png|jpe?g|gif|webp)$/i.test(file.name);
}

function extractImageFilesFromClipboard(
    clipboardData: DataTransfer | null,
): File[] {
    if (!clipboardData) {
        return [];
    }

    const files: File[] = [];

    if (clipboardData.items && clipboardData.items.length > 0) {
        for (const item of Array.from(clipboardData.items)) {
            if (item.kind === 'file' && item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (file) {
                    files.push(file);
                }
            }
        }
    }

    if (
        files.length === 0 &&
        clipboardData.files &&
        clipboardData.files.length > 0
    ) {
        for (const file of Array.from(clipboardData.files)) {
            if (isImageLikeFile(file)) {
                files.push(file);
            }
        }
    }

    return files;
}

function extractImageFilesFromDataTransfer(
    dataTransfer: DataTransfer | null,
): File[] {
    if (!dataTransfer?.files || dataTransfer.files.length === 0) {
        return [];
    }

    return Array.from(dataTransfer.files).filter(isImageLikeFile);
}
