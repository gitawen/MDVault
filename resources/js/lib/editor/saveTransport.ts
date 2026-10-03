import type { SaveOutcome } from './noteSaver';

export type NoteSaveResponseLike = {
    saved: boolean;
    file_hash: string;
    file_size: number;
    updated_at: string | null;
};

/**
 * The shapes `useHttp`'s callbacks hand back for a save request, boiled
 * down to what the mapping actually needs (see `NoteEditor.vue`'s `send()`,
 * which only wires `useHttp` to {@link mapSaveResult}). Kept separate from
 * `@inertiajs/core`'s own types so this stays a plain, dependency-free
 * function that a test can call with a literal object.
 */
export type RawSaveResult =
    | { kind: 'success'; response: NoteSaveResponseLike }
    /** A 422: Laravel's validation error bag, field name -> message(s). */
    | {
          kind: 'validation';
          errors: Record<string, string | string[] | undefined>;
      }
    /** Any other non-2xx status (409 conflicts included). */
    | { kind: 'httpException'; status: number; data: unknown }
    | { kind: 'network' };

const GENERIC_ERROR_MESSAGE = "MDVault couldn't save this note.";
const LOCKED_ERROR_MESSAGE = 'This vault is locked. Unlock it to keep editing.';
const NETWORK_ERROR_MESSAGE =
    "MDVault couldn't reach its local server. Your text is still here.";

type ConflictBody = {
    reason: 'changed' | 'missing';
    message: string;
    current_hash: string | null;
};

function isConflictBody(value: unknown): value is ConflictBody {
    return (
        typeof value === 'object' &&
        value !== null &&
        'reason' in value &&
        ((value as { reason: unknown }).reason === 'changed' ||
            (value as { reason: unknown }).reason === 'missing') &&
        'message' in value &&
        typeof (value as { message: unknown }).message === 'string'
    );
}

function parseConflictBody(data: unknown): ConflictBody | null {
    const parsed: unknown =
        typeof data === 'string' ? tryParseJson(data) : data;

    return isConflictBody(parsed) ? parsed : null;
}

function tryParseJson(text: string): unknown {
    try {
        return JSON.parse(text);
    } catch {
        return null;
    }
}

function firstMessage(
    errors: Record<string, string | string[] | undefined>,
): string {
    const first = Object.values(errors)[0];

    if (Array.isArray(first)) {
        return first[0] ?? GENERIC_ERROR_MESSAGE;
    }

    return first ?? GENERIC_ERROR_MESSAGE;
}

/**
 * Maps a raw `useHttp` result to the `noteSaver` `SaveOutcome` it expects
 * (ADR `note-save-atomic-replace`'s save-response contract): 200 -> `saved`;
 * 423 -> `error` (the vault is locked); 409 -> `conflict` (parsed from the JSON body, whether or not `useHttp`
 * has already parsed it); 422 -> `error` with the first validation message
 * (this covers both the "too large" and the "not editable" / invalid-
 * encoding cases — both are plain field errors on `content`); any other
 * HTTP exception -> a generic error; a network failure -> a message that
 * makes clear the text is still safe in the editor.
 */
export function mapSaveResult(result: RawSaveResult): SaveOutcome {
    switch (result.kind) {
        case 'success':
            return {
                kind: 'saved',
                saved: result.response.saved,
                fileHash: result.response.file_hash,
                fileSize: result.response.file_size,
                updatedAt: result.response.updated_at,
            };

        case 'validation':
            return { kind: 'error', message: firstMessage(result.errors) };

        case 'httpException': {
            if (result.status === 423) {
                // The vault was locked (idle, screen lock or locked elsewhere):
                // the text stays in the editor, nothing was written.
                return { kind: 'error', message: LOCKED_ERROR_MESSAGE };
            }

            if (result.status === 409) {
                const body = parseConflictBody(result.data);

                if (body) {
                    return {
                        kind: 'conflict',
                        reason: body.reason,
                        currentHash: body.current_hash,
                        message: body.message,
                    };
                }
            }

            return { kind: 'error', message: GENERIC_ERROR_MESSAGE };
        }

        case 'network':
            return { kind: 'error', message: NETWORK_ERROR_MESSAGE };
    }
}
