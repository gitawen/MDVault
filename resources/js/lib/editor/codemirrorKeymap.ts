import { Prec, type Extension } from '@codemirror/state';
import { keymap } from '@codemirror/view';
import {
    clearFormatting,
    insertCodeBlock,
    setHeading,
    toggleBlockquote,
    toggleBold,
    toggleBulletList,
    toggleInlineCode,
    toggleItalic,
    toggleOrderedList,
    toggleStrike,
    toggleTaskList,
} from './codemirrorCommands';

/**
 * One keyboard binding per shortcut `toolbarCommands.ts` advertises in its
 * button tooltips (FR-10). `Mod-z`/`Mod-Shift-z` (undo/redo) are already
 * bound by `@codemirror/commands`' `historyKeymap` and are not repeated
 * here. Every handler returns `true` so the browser's native
 * `contenteditable` formatting never runs instead.
 */
export function markdownFormattingKeymap(options: {
    onLink: () => void;
}): Extension {
    const { onLink } = options;

    return Prec.high(
        keymap.of([
            { key: 'Mod-b', run: (view) => toggleBold(view) },
            { key: 'Mod-i', run: (view) => toggleItalic(view) },
            { key: 'Mod-e', run: (view) => toggleInlineCode(view) },
            { key: 'Mod-Shift-x', run: (view) => toggleStrike(view) },
            { key: 'Mod-Alt-1', run: (view) => setHeading(view, 1) },
            { key: 'Mod-Alt-2', run: (view) => setHeading(view, 2) },
            { key: 'Mod-Alt-3', run: (view) => setHeading(view, 3) },
            { key: 'Mod-Shift-8', run: (view) => toggleBulletList(view) },
            { key: 'Mod-Shift-7', run: (view) => toggleOrderedList(view) },
            { key: 'Mod-Shift-9', run: (view) => toggleTaskList(view) },
            { key: 'Mod-Shift-b', run: (view) => toggleBlockquote(view) },
            { key: 'Mod-Alt-c', run: (view) => insertCodeBlock(view) },
            { key: 'Mod-\\', run: (view) => clearFormatting(view) },
            {
                key: 'Mod-k',
                run: () => {
                    onLink();
                    return true;
                },
            },
        ]),
    );
}
