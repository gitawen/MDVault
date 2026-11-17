import { describe, expect, it } from 'vite-plus/test';
import {
    deriveAltText,
    MAX_MEDIA_UPLOAD_BYTES,
    mapMediaUploadResult,
    validateImageFile,
} from '../../../resources/js/lib/editor/mediaUpload';

describe('validateImageFile', () => {
    it('accepts valid PNG, JPEG, GIF, and WEBP files within 10 MB', () => {
        for (const [name, type] of [
            ['a.png', 'image/png'],
            ['b.jpg', 'image/jpeg'],
            ['c.gif', 'image/gif'],
            ['d.webp', 'image/webp'],
        ]) {
            const file = new File([new Uint8Array(64)], name, { type });
            expect(validateImageFile(file)).toBeNull();
        }
    });

    it('falls back to extension when browser omits MIME type', () => {
        const valid = new File([new Uint8Array(32)], 'photo.WEBP', {
            type: '',
        });
        const invalid = new File([new Uint8Array(32)], 'vector.svg', {
            type: '',
        });

        expect(validateImageFile(valid)).toBeNull();
        expect(validateImageFile(invalid)).not.toBeNull();
    });

    it('rejects SVG, empty files, and files exceeding 10 MB', () => {
        const svg = new File(['<svg></svg>'], 'icon.svg', {
            type: 'image/svg+xml',
        });
        const empty = new File([], 'empty.png', { type: 'image/png' });
        const huge = new File(
            [new Uint8Array(MAX_MEDIA_UPLOAD_BYTES + 1)],
            'huge.png',
            { type: 'image/png' },
        );

        expect(validateImageFile(svg)).toBe(
            'Upload a valid PNG, JPEG, GIF, or WEBP image.',
        );
        expect(validateImageFile(empty)).toBe(
            'The selected image file is empty.',
        );
        expect(validateImageFile(huge)).toBe(
            'Images must be 10 MB or smaller.',
        );
    });
});

describe('deriveAltText', () => {
    it('strips extensions from descriptive filenames and strips brackets', () => {
        const f1 = new File(['x'], 'Architecture [v2].png', {
            type: 'image/png',
        });
        expect(deriveAltText(f1)).toBe('Architecture v2');
    });

    it('falls back to "screenshot" for generic clipboard names and "image" for empty names', () => {
        expect(
            deriveAltText(new File(['x'], 'image.png', { type: 'image/png' })),
        ).toBe('screenshot');
        expect(
            deriveAltText(new File(['x'], 'blob', { type: 'image/png' })),
        ).toBe('screenshot');
        expect(deriveAltText(new File(['x'], '', { type: 'image/png' }))).toBe(
            'image',
        );
    });
});

describe('mapMediaUploadResult', () => {
    it('maps a 201 success payload', () => {
        const outcome = mapMediaUploadResult({
            kind: 'success',
            response: {
                filename: 'Work-shot-20261117-120000-abcd1234.png',
                url: '/media/Work-shot-20261117-120000-abcd1234.png',
                mime_type: 'image/png',
                size: 1024,
            },
        });

        expect(outcome).toEqual({
            kind: 'uploaded',
            media: {
                filename: 'Work-shot-20261117-120000-abcd1234.png',
                url: '/media/Work-shot-20261117-120000-abcd1234.png',
                mime_type: 'image/png',
                size: 1024,
            },
        });
    });

    it('maps validation errors and HTTP 413/422/423 and network errors', () => {
        expect(
            mapMediaUploadResult({
                kind: 'validation',
                errors: {
                    image: [
                        "Image attachments aren't supported in encrypted vaults yet.",
                    ],
                },
            }),
        ).toEqual({
            kind: 'error',
            message:
                "Image attachments aren't supported in encrypted vaults yet.",
        });

        expect(
            mapMediaUploadResult({
                kind: 'httpException',
                status: 413,
                data: null,
            }),
        ).toEqual({
            kind: 'error',
            message: 'Images must be 10 MB or smaller.',
        });

        expect(
            mapMediaUploadResult({
                kind: 'httpException',
                status: 423,
                data: null,
            }),
        ).toEqual({
            kind: 'error',
            message: 'This vault is locked. Unlock it to continue.',
        });

        expect(
            mapMediaUploadResult({
                kind: 'httpException',
                status: 422,
                data: JSON.stringify({
                    errors: {
                        image: [
                            'Upload a valid PNG, JPEG, GIF, or WEBP image.',
                        ],
                    },
                }),
            }),
        ).toEqual({
            kind: 'error',
            message: 'Upload a valid PNG, JPEG, GIF, or WEBP image.',
        });

        expect(mapMediaUploadResult({ kind: 'network' })).toEqual({
            kind: 'error',
            message:
                "MDVault couldn't reach its local server to upload this image.",
        });
    });
});
