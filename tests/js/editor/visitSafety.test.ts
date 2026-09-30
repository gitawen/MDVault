import { describe, expect, it } from 'vite-plus/test';
import { isEditorSafeVisit } from '../../../resources/js/lib/editor/visitSafety';

const CURRENT_URL = 'https://mdvault.test/notes/abc-123';

describe('isEditorSafeVisit', () => {
    it('is safe for a same-URL GET tree-only partial reload', () => {
        const visit = {
            url: new URL(CURRENT_URL),
            method: 'get',
            only: ['tree', 'folders', 'treeSignature'],
        };

        expect(isEditorSafeVisit(visit, CURRENT_URL)).toBe(true);
    });

    it('is not safe when only includes note', () => {
        const visit = {
            url: new URL(CURRENT_URL),
            method: 'get',
            only: ['tree', 'note'],
        };

        expect(isEditorSafeVisit(visit, CURRENT_URL)).toBe(false);
    });

    it('is not safe when only is empty (a full reload)', () => {
        const visit = {
            url: new URL(CURRENT_URL),
            method: 'get',
            only: [],
        };

        expect(isEditorSafeVisit(visit, CURRENT_URL)).toBe(false);
    });

    it('is not safe for a different path', () => {
        const visit = {
            url: new URL('https://mdvault.test/workspace'),
            method: 'get',
            only: ['tree'],
        };

        expect(isEditorSafeVisit(visit, CURRENT_URL)).toBe(false);
    });

    it('is not safe for a non-GET method', () => {
        const visit = {
            url: new URL(CURRENT_URL),
            method: 'post',
            only: ['tree'],
        };

        expect(isEditorSafeVisit(visit, CURRENT_URL)).toBe(false);
    });
});
