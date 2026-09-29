export type NoteTreeFolder = {
    type: 'folder';
    name: string;
    path: string;
    open: boolean;
    children: NoteTreeNode[];
};

export type NoteTreeNote = {
    type: 'note';
    uuid: string;
    title: string;
    path: string;
    folder: string;
};

export type NoteTreeNode = NoteTreeFolder | NoteTreeNote;

export type NotePreviewState = 'ok' | 'missing' | 'too_large' | 'unreadable';

export type NoteReadOnlyReason = 'too_large' | 'invalid_utf8' | null;

export type NoteDetail = {
    uuid: string;
    title: string;
    filename: string;
    relative_path: string;
    folder: string;
    file_size: number;
    file_hash: string;
    updated_at: string | null;
    content: string | null;
    body: string | null;
    frontmatter: string | null;
    frontmatter_yaml: string | null;
    base_hash: string | null;
    state: NotePreviewState;
    is_valid_utf8: boolean;
    editable: boolean;
    read_only_reason: NoteReadOnlyReason;
};

export type NoteSaveResponse = {
    saved: boolean;
    file_hash: string;
    file_size: number;
    updated_at: string | null;
};

export type NoteSaveConflictResponse = {
    reason: 'changed' | 'missing';
    message: string;
    current_hash: string | null;
};
