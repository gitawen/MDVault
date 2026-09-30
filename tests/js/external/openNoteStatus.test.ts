import { describe, expect, it } from 'vite-plus/test';
import {
    classifyOpenNote,
    decideOpenNoteAction,
    type EditorConflictState,
    type LocalOpenNote,
    type OpenNoteAction,
    type OpenNoteChange,
    type RemoteOpenNote,
} from '../../../resources/js/lib/external/openNoteStatus';

const local: LocalOpenNote = {
    uuid: 'note-1',
    relativePath: 'Projects/A.md',
    baseHash: 'hash-a',
};

function remote(overrides: Partial<RemoteOpenNote> = {}): RemoteOpenNote {
    return {
        uuid: 'note-1',
        exists: true,
        relative_path: 'Projects/A.md',
        file_hash: 'hash-a',
        ...overrides,
    };
}

describe('classifyOpenNote', () => {
    it('is unchanged when the remote uuid does not match', () => {
        expect(classifyOpenNote(local, remote({ uuid: 'note-2' }))).toEqual({
            kind: 'unchanged',
        });
    });

    it('is deleted when the remote note no longer exists', () => {
        expect(
            classifyOpenNote(
                local,
                remote({
                    exists: false,
                    relative_path: null,
                    file_hash: null,
                }),
            ),
        ).toEqual({ kind: 'deleted' });
    });

    it('is unchanged when the remote hash is null (not yet reconciled)', () => {
        expect(classifyOpenNote(local, remote({ file_hash: null }))).toEqual({
            kind: 'unchanged',
        });
    });

    it('is changed (not moved) when the hash differs at the same path', () => {
        expect(
            classifyOpenNote(local, remote({ file_hash: 'hash-b' })),
        ).toEqual({
            kind: 'changed',
            relativePath: 'Projects/A.md',
            currentHash: 'hash-b',
            moved: false,
        });
    });

    it('is changed and moved when both the hash and the path differ', () => {
        expect(
            classifyOpenNote(
                local,
                remote({ file_hash: 'hash-b', relative_path: 'Archive/A.md' }),
            ),
        ).toEqual({
            kind: 'changed',
            relativePath: 'Archive/A.md',
            currentHash: 'hash-b',
            moved: true,
        });
    });

    it('is moved when only the path differs', () => {
        expect(
            classifyOpenNote(local, remote({ relative_path: 'Archive/A.md' })),
        ).toEqual({ kind: 'moved', relativePath: 'Archive/A.md' });
    });

    it('is unchanged when nothing differs', () => {
        expect(classifyOpenNote(local, remote())).toEqual({
            kind: 'unchanged',
        });
    });
});

describe('decideOpenNoteAction', () => {
    const clean: EditorConflictState = { dirty: false, conflict: null };
    const dirty: EditorConflictState = { dirty: true, conflict: null };
    const conflictChanged: EditorConflictState = {
        dirty: true,
        conflict: 'changed',
    };
    const conflictMissing: EditorConflictState = {
        dirty: true,
        conflict: 'missing',
    };

    const unchanged: OpenNoteChange = { kind: 'unchanged' };
    const moved: OpenNoteChange = { kind: 'moved', relativePath: 'B.md' };
    const changed: OpenNoteChange = {
        kind: 'changed',
        relativePath: 'A.md',
        currentHash: 'h',
        moved: false,
    };
    const deleted: OpenNoteChange = { kind: 'deleted' };

    it.each<[OpenNoteChange, EditorConflictState, OpenNoteAction]>([
        [unchanged, clean, 'none'],
        [unchanged, dirty, 'none'],
        [unchanged, conflictChanged, 'resume'],
        [unchanged, conflictMissing, 'resume'],

        [moved, clean, 'refresh'],
        [moved, dirty, 'refresh'],
        [moved, conflictChanged, 'resume'],
        [moved, conflictMissing, 'resume'],

        [changed, clean, 'reload'],
        [changed, dirty, 'conflict-changed'],
        [changed, conflictChanged, 'conflict-changed'],
        [changed, conflictMissing, 'conflict-changed'],

        [deleted, clean, 'leave'],
        [deleted, dirty, 'conflict-missing'],
        [deleted, conflictChanged, 'conflict-missing'],
        [deleted, conflictMissing, 'none'],
    ])('%o with editor %o decides %s', (change, editor, expected) => {
        expect(decideOpenNoteAction(change, editor)).toBe(expected);
    });
});
