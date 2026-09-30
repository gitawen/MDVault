import { describe, expect, it } from 'vite-plus/test';
import { mapCopyResult } from '../../../resources/js/lib/editor/copyTransport';

describe('mapCopyResult', () => {
    it('maps a successful copy', () => {
        const outcome = mapCopyResult({
            kind: 'success',
            response: {
                uuid: 'note-uuid',
                title: 'X (my version)',
                relative_path: 'X (my version).md',
            },
        });

        expect(outcome).toEqual({
            kind: 'saved',
            response: {
                uuid: 'note-uuid',
                title: 'X (my version)',
                relative_path: 'X (my version).md',
            },
        });
    });

    it('maps a 422 validation error to its first message', () => {
        const outcome = mapCopyResult({
            kind: 'validation',
            errors: {
                content:
                    "MDVault couldn't find a free name for a copy of “X.md”. Rename or move some notes and try again. Your text is still in the editor.",
            },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't find a free name for a copy of “X.md”. Rename or move some notes and try again. Your text is still in the editor.",
        });
    });

    it('takes the first message when a field error is an array', () => {
        const outcome = mapCopyResult({
            kind: 'validation',
            errors: {
                source_path: ['The source path field is required.', 'second'],
            },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message: 'The source path field is required.',
        });
    });

    it('falls back to a generic message when the errors bag is empty', () => {
        const outcome = mapCopyResult({ kind: 'validation', errors: {} });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't save your version as a new note. Your text is still in the editor.",
        });
    });

    it('maps any HTTP exception to a generic error that keeps the text safe', () => {
        const outcome = mapCopyResult({ kind: 'httpException' });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't save your version as a new note. Your text is still in the editor.",
        });
    });

    it('maps a network error to a message that keeps the text safe', () => {
        const outcome = mapCopyResult({ kind: 'network' });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't reach its local server. Your text is still in the editor.",
        });
    });
});
