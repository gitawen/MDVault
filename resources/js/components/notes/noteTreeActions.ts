import type { InjectionKey } from 'vue';
import type { NoteTreeFolder, NoteTreeNote } from '@/types';

export type NoteTreeActions = {
    newNote(folder: string): void;
    newFolder(parent: string): void;
    rename(note: NoteTreeNote): void;
    move(note: NoteTreeNote): void;
    remove(note: NoteTreeNote): void;
    removeFolder(folder: NoteTreeFolder): void;
    noteSelected(): void;
};

export const noteTreeActionsKey: InjectionKey<NoteTreeActions> =
    Symbol('noteTreeActions');
