/**
 * The open note's remote state, as returned by the `vaults.changes.check`
 * endpoint's `open_note` key. Declared locally (not imported from
 * `@/types`) because `resources/js/lib/**` only uses relative imports; the
 * shape is structurally identical to `RemoteOpenNote` in
 * `resources/js/types/notes.ts`.
 */
export type RemoteOpenNote = {
    uuid: string;
    exists: boolean;
    relative_path: string | null;
    file_hash: string | null;
};

export type LocalOpenNote = {
    uuid: string;
    relativePath: string;
    baseHash: string;
};

export type OpenNoteChange =
    | { kind: 'unchanged' }
    | { kind: 'moved'; relativePath: string }
    | {
          kind: 'changed';
          relativePath: string;
          currentHash: string;
          moved: boolean;
      }
    | { kind: 'deleted' };

/**
 * Classifies the open note's remote state against what the editor last
 * knew (ADR `open-note-external-conflicts`). A uuid mismatch (the result
 * arrived for a note that is no longer open) is treated as `unchanged`: the
 * caller ignores stale results by its own save-epoch token, so this is a
 * defensive fallback, not the primary guard.
 */
export function classifyOpenNote(
    local: LocalOpenNote,
    remote: RemoteOpenNote,
): OpenNoteChange {
    if (remote.uuid !== local.uuid) {
        return { kind: 'unchanged' };
    }

    if (!remote.exists) {
        return { kind: 'deleted' };
    }

    if (remote.file_hash === null) {
        return { kind: 'unchanged' };
    }

    const relativePath = remote.relative_path ?? local.relativePath;
    const moved = relativePath !== local.relativePath;

    if (remote.file_hash !== local.baseHash) {
        return {
            kind: 'changed',
            relativePath,
            currentHash: remote.file_hash,
            moved,
        };
    }

    if (moved) {
        return { kind: 'moved', relativePath };
    }

    return { kind: 'unchanged' };
}

export type OpenNoteAction =
    | 'none'
    | 'refresh'
    | 'resume'
    | 'reload'
    | 'leave'
    | 'conflict-changed'
    | 'conflict-missing';

export type EditorConflictState = {
    dirty: boolean;
    conflict: 'changed' | 'missing' | null;
};

/**
 * Decides what the editor should do about a classified change, given its
 * own dirty/conflict state (ADR `open-note-external-conflicts`'s decision
 * table).
 */
export function decideOpenNoteAction(
    change: OpenNoteChange,
    editor: EditorConflictState,
): OpenNoteAction {
    switch (change.kind) {
        case 'unchanged':
            return editor.conflict !== null ? 'resume' : 'none';

        case 'moved':
            return editor.conflict !== null ? 'resume' : 'refresh';

        case 'changed':
            return editor.dirty || editor.conflict !== null
                ? 'conflict-changed'
                : 'reload';

        case 'deleted':
            if (editor.conflict === 'missing') {
                return 'none';
            }

            return editor.dirty || editor.conflict !== null
                ? 'conflict-missing'
                : 'leave';
    }
}
