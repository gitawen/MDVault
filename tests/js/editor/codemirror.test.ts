import { indentUnit } from '@codemirror/language';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap } from '@codemirror/view';
import { describe, expect, it } from 'vite-plus/test';
import {
    buildCodeMirrorExtensions,
    buildTypographyTheme,
    createCodeMirrorCompartments,
    getFontFamilyString,
} from '../../../resources/js/lib/editor/codemirror';
import type { EditorPreferences } from '../../../resources/js/types';

describe('CodeMirror 6 helper and extensions', () => {
    const mockPreferences: EditorPreferences = {
        font_size: 14,
        font_family: 'mono',
        line_height: 1.6,
        word_wrap: true,
        show_line_numbers: true,
        indent_size: 4,
        new_note_template_enabled: false,
        new_note_template: '',
    };

    it('returns appropriate CSS font families', () => {
        expect(getFontFamilyString('mono')).toContain('monospace');
        expect(getFontFamilyString('serif')).toContain('serif');
        expect(getFontFamilyString('sans')).toContain('sans-serif');
    });

    it('creates all required compartments', () => {
        const compartments = createCodeMirrorCompartments();
        expect(compartments.lineNumbers).toBeDefined();
        expect(compartments.wordWrap).toBeDefined();
        expect(compartments.indentUnit).toBeDefined();
        expect(compartments.editable).toBeDefined();
        expect(compartments.theme).toBeDefined();
        expect(compartments.typography).toBeDefined();
    });

    it('builds extensions and initializes an EditorState cleanly', () => {
        const compartments = createCodeMirrorCompartments();
        let changedText = '';

        const extensions = buildCodeMirrorExtensions({
            compartments,
            preferences: mockPreferences,
            editable: true,
            readonly: false,
            isDark: false,
            onChange: (text) => {
                changedText = text;
            },
        });

        const state = EditorState.create({
            doc: '# Hello CodeMirror',
            extensions,
        });

        expect(state.doc.toString()).toBe('# Hello CodeMirror');
        expect(state.doc.lines).toBe(1);

        // Transaction test
        const tr = state.update({
            changes: { from: 18, insert: '\nLine 2' },
        });
        const nextState = tr.state;
        expect(nextState.doc.toString()).toBe('# Hello CodeMirror\nLine 2');
        expect(nextState.doc.lines).toBe(2);
    });

    it('builds typography theme extension without errors', () => {
        const ext = buildTypographyTheme(mockPreferences);
        expect(ext).toBeDefined();
    });

    it('marks the EditorState read-only when editable is false or readonly is true (QA-01a / FR-17b)', () => {
        const compartments = createCodeMirrorCompartments();

        const frozenExtensions = buildCodeMirrorExtensions({
            compartments,
            preferences: mockPreferences,
            editable: true,
            readonly: true,
            isDark: false,
            onChange: () => {},
        });
        const frozenState = EditorState.create({
            doc: 'text',
            extensions: frozenExtensions,
        });
        expect(frozenState.readOnly).toBe(true);

        const uneditableExtensions = buildCodeMirrorExtensions({
            compartments: createCodeMirrorCompartments(),
            preferences: mockPreferences,
            editable: false,
            readonly: false,
            isDark: false,
            onChange: () => {},
        });
        const uneditableState = EditorState.create({
            doc: 'text',
            extensions: uneditableExtensions,
        });
        expect(uneditableState.readOnly).toBe(true);

        const editableExtensions = buildCodeMirrorExtensions({
            compartments: createCodeMirrorCompartments(),
            preferences: mockPreferences,
            editable: true,
            readonly: false,
            isDark: false,
            onChange: () => {},
        });
        const editableState = EditorState.create({
            doc: 'text',
            extensions: editableExtensions,
        });
        expect(editableState.readOnly).toBe(false);
    });

    it('accepts an onUpdate callback and registers the formatting keymap (QA-01b / FR-01/FR-10)', () => {
        const compartments = createCodeMirrorCompartments();
        let updateCount = 0;

        const extensions = buildCodeMirrorExtensions({
            compartments,
            preferences: mockPreferences,
            editable: true,
            readonly: false,
            isDark: false,
            onChange: () => {},
            onUpdate: () => {
                updateCount += 1;
            },
        });

        const state = EditorState.create({ doc: 'text', extensions });

        // Doesn't throw, and the option is accepted without changing the
        // returned extension shape.
        expect(updateCount).toBe(0);

        // The formatting keymap (`codemirrorKeymap.ts`) must be present in
        // the built extension list, registered alongside CodeMirror's own
        // `defaultKeymap`/`historyKeymap`.
        const bindingGroups = state.facet(keymap);
        const allKeys = bindingGroups
            .flat()
            .map((binding) => binding.key)
            .filter((key): key is string => Boolean(key));

        expect(allKeys).toContain('Mod-b');
        expect(allKeys).toContain('Mod-i');
        expect(allKeys).toContain('Mod-k');
        // Still has the defaults the formatting keymap must not replace.
        expect(allKeys).toContain('Mod-a');
    });

    it('drives the indentUnit facet from the indent_size preference, and registers a Tab binding (replaces the TipTap-era tabIndent.test.ts)', () => {
        const twoSpaceExtensions = buildCodeMirrorExtensions({
            compartments: createCodeMirrorCompartments(),
            preferences: { ...mockPreferences, indent_size: 2 },
            editable: true,
            readonly: false,
            isDark: false,
            onChange: () => {},
        });
        const twoSpaceState = EditorState.create({
            doc: '',
            extensions: twoSpaceExtensions,
        });
        expect(twoSpaceState.facet(indentUnit)).toBe('  ');

        const fourSpaceExtensions = buildCodeMirrorExtensions({
            compartments: createCodeMirrorCompartments(),
            preferences: { ...mockPreferences, indent_size: 4 },
            editable: true,
            readonly: false,
            isDark: false,
            onChange: () => {},
        });
        const fourSpaceState = EditorState.create({
            doc: '',
            extensions: fourSpaceExtensions,
        });
        expect(fourSpaceState.facet(indentUnit)).toBe('    ');

        const bindingGroups = fourSpaceState.facet(keymap);
        const allKeys = bindingGroups
            .flat()
            .map((binding) => binding.key)
            .filter((key): key is string => Boolean(key));
        expect(allKeys).toContain('Tab');
    });

    it('buildTypographyTheme no longer emits a padding shorthand for .cm-line (QA-01c / FR-14/FR-17)', () => {
        const ext = buildTypographyTheme(mockPreferences);
        const state = EditorState.create({ doc: '', extensions: [ext] });

        const modules = state.facet(EditorView.styleModule);
        const css = modules.map((module) => module.getRules()).join('\n');
        const cmLineRule = css
            .split('\n')
            .find((line) => /\.cm-line\s*\{/.test(line));

        expect(cmLineRule).toBeDefined();
        expect(cmLineRule).toMatch(/padding-left/);
        expect(cmLineRule).toMatch(/padding-right/);
        // No bare `padding:` shorthand (which would zero out the left/right
        // values set above, or re-introduce the FR-14/FR-17 regression).
        expect(cmLineRule).not.toMatch(/[^-]padding:/);
    });
});
