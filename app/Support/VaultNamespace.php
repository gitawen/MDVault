<?php

namespace App\Support;

/**
 * The decrypted name map of one unlocked encrypted vault, built per request
 * by `EncryptedNoteService` (ADR `encrypted-vault-storage-layout`): folders
 * and notes by their logical (decrypted) names, and the lookups from logical
 * to on-disk (opaque) locations. Holds decrypted names, so it is never
 * persisted, logged or serialized.
 */
final class VaultNamespace
{
    /**
     * @var array<string, list<string>> logical folder path => on-disk folder paths
     */
    private array $foldersByLogical = [];

    /**
     * @var array<string, list<string>> lower-cased logical folder path => on-disk folder paths
     */
    private array $foldersByLowerLogical = [];

    /**
     * @var array<string, array<string, true>> on-disk folder => lower-cased note names that are readable
     */
    private array $noteNames = [];

    /**
     * @var array<string, array<string, true>> on-disk parent folder => lower-cased child folder names
     */
    private array $childFolderNames = [];

    /**
     * @param  array<string, array{name: string, logical: string, parent: string, named: bool}>  $folders  on-disk folder path => entry
     * @param  array<string, array{name: string, display: string, logical: string, disk: string, folder: string, file_id: string, state: 'ok'|'unreadable', hash: string, size: int}>  $notes  note uuid => entry; `name` is the stem ('' when unreadable), `display` always shows something, `logical` is the path with `.md`
     * @param  list<string>  $directories  on-disk folder directories (what the registry scan sees)
     */
    public function __construct(
        public readonly array $folders,
        public readonly array $notes,
        public readonly array $directories,
        public readonly string $fingerprint,
    ) {
        foreach ($folders as $disk => $folder) {
            $this->foldersByLogical[$folder['logical']][] = $disk;
            $this->foldersByLowerLogical[mb_strtolower($folder['logical'])][] = $disk;
            $this->childFolderNames[$folder['parent']][mb_strtolower($folder['name'])] = true;
        }

        foreach ($notes as $note) {
            if ($note['state'] === 'ok') {
                $this->noteNames[$note['folder']][mb_strtolower($note['name'])] = true;
            }
        }
    }

    /**
     * The on-disk folder for a logical folder path ('' is the vault root).
     * Null when it is unknown or ambiguous (two folders that differ only by
     * case or are otherwise indistinguishable).
     */
    public function folderDisk(?string $logical): ?string
    {
        if ($logical === null || $logical === '') {
            return '';
        }

        $exact = $this->foldersByLogical[$logical] ?? [];

        if (count($exact) === 1) {
            return $exact[0];
        }

        if (count($exact) > 1) {
            return null;
        }

        $loose = $this->foldersByLowerLogical[mb_strtolower($logical)] ?? [];

        return count($loose) === 1 ? $loose[0] : null;
    }

    /**
     * The logical path of an on-disk folder ('' for the root), or null when
     * the folder isn't known.
     */
    public function logicalFolderOf(string $diskFolder): ?string
    {
        if ($diskFolder === '') {
            return '';
        }

        return $this->folders[$diskFolder]['logical'] ?? null;
    }

    /**
     * Whether a readable note named $stem already exists in $diskFolder
     * (case-insensitive), ignoring the note $exceptUuid.
     */
    public function noteNameTaken(string $diskFolder, string $stem, ?string $exceptUuid): bool
    {
        $lower = mb_strtolower($stem);

        if ($exceptUuid === null) {
            return isset($this->noteNames[$diskFolder][$lower]);
        }

        foreach ($this->notes as $uuid => $note) {
            if ($uuid !== $exceptUuid && $note['state'] === 'ok' && $note['folder'] === $diskFolder && mb_strtolower($note['name']) === $lower) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a sub-folder named $name already exists under $parentDisk
     * (case-insensitive).
     */
    public function folderNameTaken(string $parentDisk, string $name): bool
    {
        return isset($this->childFolderNames[$parentDisk][mb_strtolower($name)]);
    }

    /**
     * The uuid of the note at a logical path (with `.md`), or null.
     */
    public function noteUuidAt(string $logicalPath): ?string
    {
        foreach ($this->notes as $uuid => $note) {
            if ($note['logical'] === $logicalPath) {
                return $uuid;
            }
        }

        return null;
    }
}
