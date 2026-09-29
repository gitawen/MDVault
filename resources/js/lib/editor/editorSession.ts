export type EditorMode = 'rich' | 'source';

/**
 * Session-only state, keyed by note UUID: the mode last used for a note,
 * and whether the user has consented to reformatting a `reformat` note.
 * Backed by module-level maps; nothing here is persisted.
 */
const modes = new Map<string, EditorMode>();
const acceptedReformats = new Set<string>();

export function getMode(uuid: string): EditorMode | undefined {
    return modes.get(uuid);
}

export function setMode(uuid: string, mode: EditorMode): void {
    modes.set(uuid, mode);
}

export function hasAcceptedReformat(uuid: string): boolean {
    return acceptedReformats.has(uuid);
}

export function acceptReformat(uuid: string): void {
    acceptedReformats.add(uuid);
}
