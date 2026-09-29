import type { Editor } from '@tiptap/core';

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
    | 'clearFormatting';

export type ToolbarCommand = {
    label: string;
    shortcut: string | null;
    run(editor: Editor): boolean;
    isActive(editor: Editor): boolean;
    canRun(editor: Editor): boolean;
};

/**
 * The §20/§53 toolbar, minus underline and alignment (F3). Links are
 * handled separately by `LinkDialog`, not through this table.
 */
export const toolbarCommands: Record<ToolbarCommandId, ToolbarCommand> = {
    undo: {
        label: 'Undo',
        shortcut: 'Ctrl+Z',
        run: (editor) => editor.chain().focus().undo().run(),
        isActive: () => false,
        canRun: (editor) => editor.can().undo(),
    },
    redo: {
        label: 'Redo',
        shortcut: 'Ctrl+Shift+Z',
        run: (editor) => editor.chain().focus().redo().run(),
        isActive: () => false,
        canRun: (editor) => editor.can().redo(),
    },
    bold: {
        label: 'Bold',
        shortcut: 'Ctrl+B',
        run: (editor) => editor.chain().focus().toggleBold().run(),
        isActive: (editor) => editor.isActive('bold'),
        canRun: (editor) => editor.can().chain().focus().toggleBold().run(),
    },
    italic: {
        label: 'Italic',
        shortcut: 'Ctrl+I',
        run: (editor) => editor.chain().focus().toggleItalic().run(),
        isActive: (editor) => editor.isActive('italic'),
        canRun: (editor) => editor.can().chain().focus().toggleItalic().run(),
    },
    strike: {
        label: 'Strikethrough',
        shortcut: 'Ctrl+Shift+X',
        run: (editor) => editor.chain().focus().toggleStrike().run(),
        isActive: (editor) => editor.isActive('strike'),
        canRun: (editor) => editor.can().chain().focus().toggleStrike().run(),
    },
    code: {
        label: 'Inline code',
        shortcut: 'Ctrl+E',
        run: (editor) => editor.chain().focus().toggleCode().run(),
        isActive: (editor) => editor.isActive('code'),
        canRun: (editor) => editor.can().chain().focus().toggleCode().run(),
    },
    paragraph: {
        label: 'Paragraph',
        shortcut: null,
        run: (editor) => editor.chain().focus().setParagraph().run(),
        isActive: (editor) => editor.isActive('paragraph'),
        canRun: (editor) => editor.can().chain().focus().setParagraph().run(),
    },
    h1: {
        label: 'Heading 1',
        shortcut: 'Ctrl+Alt+1',
        run: (editor) =>
            editor.chain().focus().toggleHeading({ level: 1 }).run(),
        isActive: (editor) => editor.isActive('heading', { level: 1 }),
        canRun: (editor) =>
            editor.can().chain().focus().toggleHeading({ level: 1 }).run(),
    },
    h2: {
        label: 'Heading 2',
        shortcut: 'Ctrl+Alt+2',
        run: (editor) =>
            editor.chain().focus().toggleHeading({ level: 2 }).run(),
        isActive: (editor) => editor.isActive('heading', { level: 2 }),
        canRun: (editor) =>
            editor.can().chain().focus().toggleHeading({ level: 2 }).run(),
    },
    h3: {
        label: 'Heading 3',
        shortcut: 'Ctrl+Alt+3',
        run: (editor) =>
            editor.chain().focus().toggleHeading({ level: 3 }).run(),
        isActive: (editor) => editor.isActive('heading', { level: 3 }),
        canRun: (editor) =>
            editor.can().chain().focus().toggleHeading({ level: 3 }).run(),
    },
    bulletList: {
        label: 'Bulleted list',
        shortcut: 'Ctrl+Shift+8',
        run: (editor) => editor.chain().focus().toggleBulletList().run(),
        isActive: (editor) => editor.isActive('bulletList'),
        canRun: (editor) =>
            editor.can().chain().focus().toggleBulletList().run(),
    },
    orderedList: {
        label: 'Numbered list',
        shortcut: 'Ctrl+Shift+7',
        run: (editor) => editor.chain().focus().toggleOrderedList().run(),
        isActive: (editor) => editor.isActive('orderedList'),
        canRun: (editor) =>
            editor.can().chain().focus().toggleOrderedList().run(),
    },
    taskList: {
        label: 'Task list',
        shortcut: 'Ctrl+Shift+9',
        run: (editor) => editor.chain().focus().toggleTaskList().run(),
        isActive: (editor) => editor.isActive('taskList'),
        canRun: (editor) => editor.can().chain().focus().toggleTaskList().run(),
    },
    blockquote: {
        label: 'Blockquote',
        shortcut: 'Ctrl+Shift+B',
        run: (editor) => editor.chain().focus().toggleBlockquote().run(),
        isActive: (editor) => editor.isActive('blockquote'),
        canRun: (editor) =>
            editor.can().chain().focus().toggleBlockquote().run(),
    },
    codeBlock: {
        label: 'Code block',
        shortcut: 'Ctrl+Alt+C',
        run: (editor) => editor.chain().focus().toggleCodeBlock().run(),
        isActive: (editor) => editor.isActive('codeBlock'),
        canRun: (editor) =>
            editor.can().chain().focus().toggleCodeBlock().run(),
    },
    horizontalRule: {
        label: 'Horizontal rule',
        shortcut: null,
        run: (editor) => editor.chain().focus().setHorizontalRule().run(),
        isActive: () => false,
        canRun: (editor) =>
            editor.can().chain().focus().setHorizontalRule().run(),
    },
    clearFormatting: {
        label: 'Clear formatting',
        shortcut: 'Ctrl+\\',
        run: (editor) =>
            editor.chain().focus().unsetAllMarks().clearNodes().run(),
        isActive: () => false,
        canRun: (editor) =>
            editor.can().chain().focus().unsetAllMarks().clearNodes().run(),
    },
};
