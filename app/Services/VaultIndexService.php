<?php

namespace App\Services;

use App\Exceptions\NoteOperationException;
use App\Models\Note;
use App\Models\Vault;
use App\Support\IndexResult;
use Illuminate\Database\DatabaseManager;

/**
 * Scans a vault's Markdown files and reconciles the `notes` registry with
 * them, preserving UUIDs across external renames and moves whenever
 * possible, and lists the note tree for the Workspace.
 *
 * See ADR `note-registry-and-indexing`: the filesystem is the source of
 * truth, the registry is a rebuildable index, and matching is exact path,
 * then a unique case-insensitive path, then a unique identical hash.
 */
final class VaultIndexService
{
    public function __construct(
        private readonly FileStorageService $files,
        private readonly FileHashService $hashes,
        private readonly DatabaseManager $database,
    ) {}

    public function isIgnoredName(string $name, bool $isDirectory): bool
    {
        return str_starts_with($name, '.') || ($isDirectory && strcasecmp($name, 'node_modules') === 0);
    }

    public function isIndexableFileName(string $name): bool
    {
        return ! $this->isIgnoredName($name, false)
            && strcasecmp(pathinfo($name, PATHINFO_EXTENSION), 'md') === 0;
    }

    /**
     * @throws NoteOperationException
     */
    public function reindex(Vault $vault): IndexResult
    {
        if (! $this->files->isDirectory($vault->path)) {
            throw NoteOperationException::vaultUnavailable($vault->path);
        }

        $scan = $this->files->scan(
            $vault->path,
            fn (string $relative, string $name, bool $isDir): bool => $isDir
                ? ! $this->isIgnoredName($name, true)
                : $this->isIndexableFileName($name),
        );

        if (in_array('', $scan['unreadable'], true)) {
            throw NoteOperationException::vaultUnavailable($vault->path);
        }

        $skipped = 0;

        /** @var array<string, array{size: int, hash: string}> $remainingFiles */
        $remainingFiles = [];

        /** @var list<string> $unreadableFilePaths */
        $unreadableFilePaths = [];

        foreach ($scan['files'] as $file) {
            $hash = $this->hashes->hashFile($this->files->joinRelative($vault->path, $file['path']));

            if ($hash === null) {
                $skipped++;
                $unreadableFilePaths[] = $file['path'];

                continue;
            }

            $remainingFiles[$file['path']] = ['size' => $file['size'], 'hash' => $hash];
        }

        /** @var array<string, Note> $remainingRows */
        $remainingRows = $vault->notes()->get()->keyBy('relative_path')->all();

        foreach ($unreadableFilePaths as $path) {
            // A record at this exact path (if any) is kept untouched: it is
            // excluded from every matching step below.
            unset($remainingRows[$path]);
        }

        $updates = [];
        $moves = [];
        $deletes = [];
        $inserts = [];
        $unchanged = 0;

        // Step 5: exact path match.
        foreach (array_keys($remainingFiles) as $path) {
            if (! array_key_exists($path, $remainingRows)) {
                continue;
            }

            $note = $remainingRows[$path];
            $file = $remainingFiles[$path];

            if ($note->file_hash !== $file['hash'] || $note->file_size !== $file['size']) {
                $updates[] = ['note' => $note, 'attributes' => [
                    'file_hash' => $file['hash'],
                    'file_size' => $file['size'],
                ]];
            } else {
                $unchanged++;
            }

            unset($remainingFiles[$path], $remainingRows[$path]);
        }

        // Step 6: unique case-insensitive path match.
        $rowsByLowerPath = [];
        foreach ($remainingRows as $path => $note) {
            $rowsByLowerPath[mb_strtolower($path)][] = $path;
        }

        $filesByLowerPath = [];
        foreach (array_keys($remainingFiles) as $path) {
            $filesByLowerPath[mb_strtolower($path)][] = $path;
        }

        foreach ($rowsByLowerPath as $lower => $rowPaths) {
            if (count($rowPaths) !== 1 || ! isset($filesByLowerPath[$lower]) || count($filesByLowerPath[$lower]) !== 1) {
                continue;
            }

            $oldPath = $rowPaths[0];
            $newPath = $filesByLowerPath[$lower][0];
            $note = $remainingRows[$oldPath];
            $file = $remainingFiles[$newPath];

            $moves[] = ['note' => $note, 'attributes' => $this->moveAttributes($newPath, $file)];

            unset($remainingRows[$oldPath], $remainingFiles[$newPath]);
        }

        // Step 7: unique identical hash match.
        $rowsByHash = [];
        foreach ($remainingRows as $path => $note) {
            $rowsByHash[$note->file_hash][] = $path;
        }

        $filesByHash = [];
        foreach ($remainingFiles as $path => $file) {
            $filesByHash[$file['hash']][] = $path;
        }

        foreach ($rowsByHash as $hash => $rowPaths) {
            if (count($rowPaths) !== 1 || ! isset($filesByHash[$hash]) || count($filesByHash[$hash]) !== 1) {
                continue;
            }

            $oldPath = $rowPaths[0];
            $newPath = $filesByHash[$hash][0];
            $note = $remainingRows[$oldPath];
            $file = $remainingFiles[$newPath];

            $moves[] = ['note' => $note, 'attributes' => $this->moveAttributes($newPath, $file)];

            unset($remainingRows[$oldPath], $remainingFiles[$newPath]);
        }

        // Step 8: leftover rows are deleted, unless they sit under a
        // directory the scan couldn't read.
        foreach ($remainingRows as $path => $note) {
            $underUnreadableDir = false;

            foreach ($scan['unreadable'] as $dir) {
                if (str_starts_with($path, $dir.'/')) {
                    $underUnreadableDir = true;

                    break;
                }
            }

            if ($underUnreadableDir) {
                $skipped++;
            } else {
                $deletes[] = $note->id;
            }
        }

        // Step 9: leftover files are inserted.
        foreach ($remainingFiles as $path => $file) {
            $inserts[] = $this->insertAttributes($path, $file);
        }

        $this->database->connection()->transaction(function () use ($vault, $deletes, $updates, $moves, $inserts): void {
            if ($deletes !== []) {
                Note::query()->whereIn('id', $deletes)->delete();
            }

            foreach ([...$updates, ...$moves] as $change) {
                $change['note']->update($change['attributes']);
            }

            foreach ($inserts as $attributes) {
                $vault->notes()->create($attributes);
            }
        });

        return new IndexResult(
            added: count($inserts),
            updated: count($updates),
            moved: count($moves),
            removed: count($deletes),
            unchanged: $unchanged,
            skipped: $skipped,
        );
    }

    /**
     * @return array{tree: list<array<string, mixed>>, folders: list<string>}
     */
    public function browse(Vault $vault, ?string $openPath = null): array
    {
        if (! $this->files->isDirectory($vault->path)) {
            return ['tree' => [], 'folders' => ['']];
        }

        $scan = $this->files->scan(
            $vault->path,
            fn (string $relative, string $name, bool $isDir): bool => $isDir && ! $this->isIgnoredName($name, true),
        );

        $notes = $vault->notes()->get(['uuid', 'title', 'relative_path']);

        $folderSet = array_fill_keys($scan['directories'], true);

        foreach ($notes as $note) {
            $dir = $this->parentFolder($note->relative_path);

            while ($dir !== '') {
                $folderSet[$dir] = true;
                $dir = $this->parentFolder($dir);
            }
        }

        $folders = array_keys($folderSet);
        usort($folders, 'strnatcasecmp');

        $notesByFolder = [];
        foreach ($notes as $note) {
            $notesByFolder[$this->parentFolder($note->relative_path)][] = $note;
        }

        $tree = $this->buildFolderChildren('', $folders, $notesByFolder, $openPath);

        return [
            'tree' => $tree,
            'folders' => ['', ...$folders],
        ];
    }

    /**
     * @param  list<string>  $allFolders
     * @param  array<string, list<Note>>  $notesByFolder
     * @return list<array<string, mixed>>
     */
    private function buildFolderChildren(string $parent, array $allFolders, array $notesByFolder, ?string $openPath): array
    {
        $children = [];

        foreach ($allFolders as $folder) {
            if ($this->parentFolder($folder) !== $parent) {
                continue;
            }

            $name = basename($folder);
            $open = $openPath !== null && str_starts_with($openPath, $folder.'/');

            $children[] = [
                'type' => 'folder',
                'name' => $name,
                'path' => $folder,
                'open' => $open,
                'children' => $this->buildFolderChildren($folder, $allFolders, $notesByFolder, $openPath),
            ];
        }

        foreach ($notesByFolder[$parent] ?? [] as $note) {
            $children[] = [
                'type' => 'note',
                'uuid' => $note->uuid,
                'title' => $note->title,
                'path' => $note->relative_path,
                'folder' => $parent,
            ];
        }

        usort($children, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'folder' ? -1 : 1;
            }

            $aName = $a['type'] === 'folder' ? $a['name'] : $a['title'];
            $bName = $b['type'] === 'folder' ? $b['name'] : $b['title'];

            return strnatcasecmp($aName, $bName);
        });

        return $children;
    }

    private function parentFolder(string $relativePath): string
    {
        $position = strrpos($relativePath, '/');

        return $position === false ? '' : substr($relativePath, 0, $position);
    }

    /**
     * @param  array{size: int, hash: string}  $file
     * @return array<string, mixed>
     */
    private function moveAttributes(string $path, array $file): array
    {
        $filename = basename($path);

        return [
            'relative_path' => $path,
            'filename' => $filename,
            'title' => pathinfo($filename, PATHINFO_FILENAME),
            'extension' => mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
            'file_hash' => $file['hash'],
            'file_size' => $file['size'],
        ];
    }

    /**
     * @param  array{size: int, hash: string}  $file
     * @return array<string, mixed>
     */
    private function insertAttributes(string $path, array $file): array
    {
        return [
            ...$this->moveAttributes($path, $file),
            'mime_type' => Note::MIME_TYPE,
            'is_encrypted' => false,
        ];
    }
}
