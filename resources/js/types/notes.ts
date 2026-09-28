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
};

export type NoteTreeNode = NoteTreeFolder | NoteTreeNote;

export type NotePreviewState = 'ok' | 'missing' | 'too_large' | 'unreadable';

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
    state: NotePreviewState;
    is_valid_utf8: boolean;
};
