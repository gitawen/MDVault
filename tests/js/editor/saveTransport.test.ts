import { describe, expect, it } from 'vite-plus/test';
import { mapSaveResult } from '../../../resources/js/lib/editor/saveTransport';

describe('mapSaveResult', () => {
    it('maps a successful save', () => {
        const outcome = mapSaveResult({
            kind: 'success',
            response: {
                saved: true,
                file_hash: 'abc123',
                file_size: 42,
                updated_at: '2026-09-29T00:00:00Z',
            },
        });

        expect(outcome).toEqual({
            kind: 'saved',
            saved: true,
            fileHash: 'abc123',
            fileSize: 42,
            updatedAt: '2026-09-29T00:00:00Z',
        });
    });

    it('maps a no-op save (saved: false)', () => {
        const outcome = mapSaveResult({
            kind: 'success',
            response: {
                saved: false,
                file_hash: 'abc123',
                file_size: 42,
                updated_at: null,
            },
        });

        expect(outcome).toEqual({
            kind: 'saved',
            saved: false,
            fileHash: 'abc123',
            fileSize: 42,
            updatedAt: null,
        });
    });

    it('maps a 409 conflict with an already-parsed JSON body', () => {
        const outcome = mapSaveResult({
            kind: 'httpException',
            status: 409,
            data: {
                reason: 'changed',
                message: '“a.md” was changed outside MDVault.',
                current_hash: 'disk-hash',
            },
        });

        expect(outcome).toEqual({
            kind: 'conflict',
            reason: 'changed',
            currentHash: 'disk-hash',
            message: '“a.md” was changed outside MDVault.',
        });
    });

    it('maps a 409 conflict with a raw JSON string body', () => {
        const outcome = mapSaveResult({
            kind: 'httpException',
            status: 409,
            data: JSON.stringify({
                reason: 'missing',
                message: 'The file for this note is no longer at a.md.',
                current_hash: null,
            }),
        });

        expect(outcome).toEqual({
            kind: 'conflict',
            reason: 'missing',
            currentHash: null,
            message: 'The file for this note is no longer at a.md.',
        });
    });

    it('falls back to a generic error when a 409 body cannot be parsed', () => {
        const outcome = mapSaveResult({
            kind: 'httpException',
            status: 409,
            data: 'not json at all',
        });

        expect(outcome).toEqual({
            kind: 'error',
            message: "MDVault couldn't save this note.",
        });
    });

    it('maps a 422 validation error (content too large) to its first message', () => {
        const outcome = mapSaveResult({
            kind: 'validation',
            errors: {
                content:
                    "This note is larger than 1 MB, which the editor can't save. The file on disk wasn't changed.",
            },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "This note is larger than 1 MB, which the editor can't save. The file on disk wasn't changed.",
        });
    });

    it('maps a 422 validation error (not editable / invalid encoding) to its first message', () => {
        const outcome = mapSaveResult({
            kind: 'validation',
            errors: {
                content:
                    '“a.md” can’t be edited in MDVault (it is larger than 1 MB, isn’t valid UTF-8, or isn’t a regular file). Nothing was changed.',
            },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                '“a.md” can’t be edited in MDVault (it is larger than 1 MB, isn’t valid UTF-8, or isn’t a regular file). Nothing was changed.',
        });
    });

    it('takes the first message when a field error is an array', () => {
        const outcome = mapSaveResult({
            kind: 'validation',
            errors: {
                base_hash: ['The base hash field is required.', 'second'],
            },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message: 'The base hash field is required.',
        });
    });

    it('falls back to a generic message when the errors bag is empty', () => {
        const outcome = mapSaveResult({ kind: 'validation', errors: {} });

        expect(outcome).toEqual({
            kind: 'error',
            message: "MDVault couldn't save this note.",
        });
    });

    it('maps any other HTTP exception status to a generic error', () => {
        const outcome = mapSaveResult({
            kind: 'httpException',
            status: 500,
            data: 'Internal Server Error',
        });

        expect(outcome).toEqual({
            kind: 'error',
            message: "MDVault couldn't save this note.",
        });
    });

    it('maps a 423 (the vault was locked) to a locked message, never a conflict', () => {
        const outcome = mapSaveResult({
            kind: 'httpException',
            status: 423,
            data: { message: 'This vault is locked.', reason: 'locked' },
        });

        expect(outcome).toEqual({
            kind: 'error',
            message: 'This vault is locked. Unlock it to keep editing.',
        });
    });

    it('maps a network error to a message that keeps the text safe', () => {
        const outcome = mapSaveResult({ kind: 'network' });

        expect(outcome).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't reach its local server. Your text is still here.",
        });
    });
});
