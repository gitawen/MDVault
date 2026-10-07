import { redoDepth, undoDepth } from '@codemirror/commands';
import {
    clearFormatting,
    clearLinePrefix,
    executeRedo,
    executeUndo,
    insertCodeBlock,
    insertHorizontalRule,
    insertTable,
    isInlineMarkActive,
    isLinePrefixActive,
    setHeading,
    toggleBlockquote,
    toggleBold,
    toggleBulletList,
    toggleInlineCode,
    toggleItalic,
    toggleOrderedList,
    toggleStrike,
    toggleTaskList,
    type CommandTarget,
} from './codemirrorCommands';

export type ToolbarCommandId =
    | 'undo'
    | 'redo'
    | 'bold'
    | 'italic'
    | 'strike'
    | 'code'
    | 'paragraph'
    | 'h1'
    | 'h2'
    | 'h3'
    | 'bulletList'
    | 'orderedList'
    | 'taskList'
    | 'blockquote'
    | 'codeBlock'
    | 'horizontalRule'
    | 'clearFormatting'
    | 'table';

export type ToolbarCommand = {
    label: string;
    shortcut: string | null;
    run(editor: CommandTarget): boolean;
    isActive(editor: CommandTarget): boolean;
    canRun(editor: CommandTarget): boolean;
};

/**
 * The §20/§53 toolbar, driven entirely by the CodeMirror 6 `CommandTarget`.
 */
export const toolbarCommands: Record<ToolbarCommandId, ToolbarCommand> = {
    undo: {
        label: 'Undo',
        shortcut: 'Ctrl+Z',
        run: (editor) => executeUndo(editor),
        isActive: () => false,
        canRun: (editor) => undoDepth(editor.state) > 0,
    },
    redo: {
        label: 'Redo',
        shortcut: 'Ctrl+Shift+Z',
        run: (editor) => executeRedo(editor),
        isActive: () => false,
        canRun: (editor) => redoDepth(editor.state) > 0,
    },
    bold: {
        label: 'Bold',
        shortcut: 'Ctrl+B',
        run: (editor) => toggleBold(editor),
        isActive: (editor) => isInlineMarkActive(editor.state, '**'),
        canRun: () => true,
    },
    italic: {
        label: 'Italic',
        shortcut: 'Ctrl+I',
        run: (editor) => toggleItalic(editor),
        isActive: (editor) => isInlineMarkActive(editor.state, '*'),
        canRun: () => true,
    },
    strike: {
        label: 'Strikethrough',
        shortcut: 'Ctrl+Shift+X',
        run: (editor) => toggleStrike(editor),
        isActive: (editor) => isInlineMarkActive(editor.state, '~~'),
        canRun: () => true,
    },
    code: {
        label: 'Inline code',
        shortcut: 'Ctrl+E',
        run: (editor) => toggleInlineCode(editor),
        isActive: (editor) => isInlineMarkActive(editor.state, '`'),
        canRun: () => true,
    },
    paragraph: {
        label: 'Paragraph',
        shortcut: null,
        run: (editor) => clearLinePrefix(editor),
        isActive: () => false,
        canRun: () => true,
    },
    h1: {
        label: 'Heading 1',
        shortcut: 'Ctrl+Alt+1',
        run: (editor) => setHeading(editor, 1),
        isActive: (editor) => isLinePrefixActive(editor.state, '# '),
        canRun: () => true,
    },
    h2: {
        label: 'Heading 2',
        shortcut: 'Ctrl+Alt+2',
        run: (editor) => setHeading(editor, 2),
        isActive: (editor) => isLinePrefixActive(editor.state, '## '),
        canRun: () => true,
    },
    h3: {
        label: 'Heading 3',
        shortcut: 'Ctrl+Alt+3',
        run: (editor) => setHeading(editor, 3),
        isActive: (editor) => isLinePrefixActive(editor.state, '### '),
        canRun: () => true,
    },
    bulletList: {
        label: 'Bullet list',
        shortcut: 'Ctrl+Shift+8',
        run: (editor) => toggleBulletList(editor),
        isActive: (editor) => isLinePrefixActive(editor.state, '- '),
        canRun: () => true,
    },
    orderedList: {
        label: 'Numbered list',
        shortcut: 'Ctrl+Shift+7',
        run: (editor) => toggleOrderedList(editor),
        isActive: (editor) => isLinePrefixActive(editor.state, '1. '),
        canRun: () => true,
    },
    taskList: {
        label: 'Task list',
        shortcut: 'Ctrl+Shift+9',
        run: (editor) => toggleTaskList(editor),
        isActive: (editor) => isLinePrefixActive(editor.state, '- [ ] '),
        canRun: () => true,
    },
    blockquote: {
        label: 'Blockquote',
        shortcut: 'Ctrl+Shift+B',
        run: (editor) => toggleBlockquote(editor),
        isActive: (editor) => isLinePrefixActive(editor.state, '> '),
        canRun: () => true,
    },
    codeBlock: {
        label: 'Code block',
        shortcut: 'Ctrl+Alt+C',
        run: (editor) => insertCodeBlock(editor),
        isActive: () => false,
        canRun: () => true,
    },
    horizontalRule: {
        label: 'Divider',
        shortcut: null,
        run: (editor) => insertHorizontalRule(editor),
        isActive: () => false,
        canRun: () => true,
    },
    clearFormatting: {
        label: 'Clear formatting',
        shortcut: 'Ctrl+\\',
        run: (editor) => clearFormatting(editor),
        isActive: () => false,
        canRun: () => true,
    },
    table: {
        label: 'Table',
        shortcut: null,
        run: (editor) => insertTable(editor),
        isActive: () => false,
        canRun: () => true,
    },
};
