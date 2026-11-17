import { useHttp } from '@inertiajs/vue3';
import { store } from '@/routes/vaults/media';
import type { MediaUploadResponse } from '@/types';

export const MAX_MEDIA_UPLOAD_BYTES = 10 * 1024 * 1024;

export const ALLOWED_IMAGE_MIME_TYPES = new Set([
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
]);

const ALLOWED_IMAGE_EXTENSIONS = /\.(png|jpe?g|gif|webp)$/i;

const GENERIC_UPLOAD_ERROR = "MDVault couldn't upload this image.";
const TOO_LARGE_ERROR = 'Images must be 10 MB or smaller.';
const INVALID_IMAGE_ERROR = 'Upload a valid PNG, JPEG, GIF, or WEBP image.';
const LOCKED_ERROR = 'This vault is locked. Unlock it to continue.';
const NETWORK_ERROR =
    "MDVault couldn't reach its local server to upload this image.";

export type RawMediaUploadResult =
    | { kind: 'success'; response: MediaUploadResponse }
    | {
          kind: 'validation';
          errors: Record<string, string | string[] | undefined>;
      }
    | { kind: 'httpException'; status: number; data: unknown }
    | { kind: 'network' };

export type MediaUploadOutcome =
    | { kind: 'uploaded'; media: MediaUploadResponse }
    | { kind: 'error'; message: string };

/**
 * Client-side pre-validation before uploading a file to `vaults.media.store`.
 * Returns a user-facing error message, or `null` when valid.
 */
export function validateImageFile(file: File): string | null {
    if (file.size <= 0) {
        return 'The selected image file is empty.';
    }

    if (file.size > MAX_MEDIA_UPLOAD_BYTES) {
        return TOO_LARGE_ERROR;
    }

    const mime = file.type.trim().toLowerCase();

    if (mime !== '') {
        return ALLOWED_IMAGE_MIME_TYPES.has(mime) ? null : INVALID_IMAGE_ERROR;
    }

    if (ALLOWED_IMAGE_EXTENSIONS.test(file.name.trim())) {
        return null;
    }

    return INVALID_IMAGE_ERROR;
}

/**
 * Derives a clean alt-text label from an image file, stripping extensions
 * from descriptive filenames and falling back to `'screenshot'` or `'image'`.
 */
export function deriveAltText(file: File): string {
    const raw = file.name.trim();

    if (raw === '') {
        return 'image';
    }

    const stem = raw
        .replace(/\.[^.]+$/, '')
        .replace(/[\r\n]+/g, ' ')
        .replace(/[[\]]/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    if (stem === '') {
        return 'image';
    }

    if (/^(image|blob|clipboard|pasted[-_ ]?image)$/i.test(stem)) {
        return 'screenshot';
    }

    return stem;
}

function firstValidationMessage(
    errors: Record<string, string | string[] | undefined>,
): string {
    const first = Object.values(errors)[0];

    if (Array.isArray(first)) {
        return first[0] ?? GENERIC_UPLOAD_ERROR;
    }

    return first ?? GENERIC_UPLOAD_ERROR;
}

function tryParseJson(data: unknown): unknown {
    if (typeof data !== 'string') {
        return data;
    }

    try {
        return JSON.parse(data);
    } catch {
        return null;
    }
}

function extractHttpMessage(data: unknown): string | null {
    const parsed = tryParseJson(data);

    if (typeof parsed !== 'object' || parsed === null) {
        return null;
    }

    if (
        'errors' in parsed &&
        typeof (parsed as { errors: unknown }).errors === 'object' &&
        (parsed as { errors: unknown }).errors !== null
    ) {
        return firstValidationMessage(
            (
                parsed as {
                    errors: Record<string, string | string[] | undefined>;
                }
            ).errors,
        );
    }

    if (
        'message' in parsed &&
        typeof (parsed as { message: unknown }).message === 'string' &&
        (parsed as { message: string }).message.trim() !== ''
    ) {
        return (parsed as { message: string }).message;
    }

    return null;
}

/**
 * Pure mapper converting a raw `useHttp` upload result into a typed outcome.
 */
export function mapMediaUploadResult(
    result: RawMediaUploadResult,
): MediaUploadOutcome {
    switch (result.kind) {
        case 'success':
            return { kind: 'uploaded', media: result.response };

        case 'validation':
            return {
                kind: 'error',
                message: firstValidationMessage(result.errors),
            };

        case 'httpException': {
            if (result.status === 413) {
                return { kind: 'error', message: TOO_LARGE_ERROR };
            }

            if (result.status === 423) {
                return { kind: 'error', message: LOCKED_ERROR };
            }

            if (result.status === 422) {
                return {
                    kind: 'error',
                    message:
                        extractHttpMessage(result.data) ?? GENERIC_UPLOAD_ERROR,
                };
            }

            return { kind: 'error', message: GENERIC_UPLOAD_ERROR };
        }

        case 'network':
            return { kind: 'error', message: NETWORK_ERROR };
    }
}

/**
 * Uploads an image file to `POST /vaults/{vault:uuid}/media` using Inertia's
 * `useHttp` client (automatically multipart-encoded and carrying `X-MDVault-Unlock`).
 */
export function uploadVaultMedia(
    vaultUuid: string,
    file: File,
): Promise<MediaUploadOutcome> {
    const validationError = validateImageFile(file);

    if (validationError !== null) {
        return Promise.resolve({ kind: 'error', message: validationError });
    }

    return new Promise((resolve) => {
        void useHttp<{ image: File }, MediaUploadResponse>({ image: file })
            .post(store.url(vaultUuid), {
                onSuccess: (response) => {
                    resolve(
                        mapMediaUploadResult({ kind: 'success', response }),
                    );
                },
                onError: (errors) => {
                    resolve(
                        mapMediaUploadResult({ kind: 'validation', errors }),
                    );
                },
                onHttpException: (httpResponse) => {
                    resolve(
                        mapMediaUploadResult({
                            kind: 'httpException',
                            status: httpResponse.status,
                            data: httpResponse.data,
                        }),
                    );
                },
                onNetworkError: () => {
                    resolve(mapMediaUploadResult({ kind: 'network' }));
                },
            })
            .catch(() => {
                resolve(mapMediaUploadResult({ kind: 'network' }));
            });
    });
}
