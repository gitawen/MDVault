export type NoteCopyResponseLike = {
    uuid: string;
    title: string;
    relative_path: string;
};

/**
 * The shapes `useHttp`'s callbacks hand back for a "Save mine as a new
 * note" request (`NoteEditor.vue`'s `saveAsNewNote()`), boiled down to what
 * {@link mapCopyResult} needs. Mirrors `saveTransport.ts`'s `RawSaveResult`
 * so both new-in-this-phase `useHttp` call sites in `NoteEditor.vue` are
 * mapped the same way.
 */
export type RawCopyResult =
    | { kind: 'success'; response: NoteCopyResponseLike }
    /** A 422: Laravel's validation error bag, field name -> message(s). */
    | {
          kind: 'validation';
          errors: Record<string, string | string[] | undefined>;
      }
    /** Any other non-2xx status. */
    | { kind: 'httpException' }
    | { kind: 'network' };

export type CopyOutcome =
    | { kind: 'saved'; response: NoteCopyResponseLike }
    | { kind: 'error'; message: string };

const GENERIC_ERROR_MESSAGE =
    "MDVault couldn't save your version as a new note. Your text is still in the editor.";
const NETWORK_ERROR_MESSAGE =
    "MDVault couldn't reach its local server. Your text is still in the editor.";

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
 * Maps a raw `useHttp` result for the copy endpoint to a {@link CopyOutcome}:
 * 201 -> `saved`; 422 -> `error` with the first validation message (the
 * "no free name" and "content too large" cases are both plain field
 * errors); any other HTTP exception or a network failure -> a generic error
 * that reassures the caller the edit itself was never discarded (the editor
 * only calls `discard()` on `saved`, so the text really does stay put).
 */
export function mapCopyResult(result: RawCopyResult): CopyOutcome {
    switch (result.kind) {
        case 'success':
            return { kind: 'saved', response: result.response };

        case 'validation':
            return { kind: 'error', message: firstMessage(result.errors) };

        case 'httpException':
            return { kind: 'error', message: GENERIC_ERROR_MESSAGE };

        case 'network':
            return { kind: 'error', message: NETWORK_ERROR_MESSAGE };
    }
}
