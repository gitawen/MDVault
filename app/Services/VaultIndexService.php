<?php

namespace App\Services;

use App\Enums\IndexMode;
use App\Exceptions\NoteOperationException;
use App\Models\Note;
use App\Models\Vault;
use App\Support\IndexResult;
use App\Support\ReconcilePlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * Scans a vault's Markdown files and reconciles the `notes` registry with
 * them, preserving UUIDs across external renames and moves whenever
 * possible, and lists the note tree for the Workspace.
 *
 * See ADR `note-registry-and-indexing` and ADR
 * `external-change-reconciliation`: the filesystem is the source of truth,
 * the registry is a rebuildable index, and matching is exact path, then a
 * unique case-insensitive path, then a unique identical hash, then a
 * unique file name. `reconcile()` (`plan()` then `apply()`) is used by both
 * the manual Full re-index and the automatic Quick checks; `apply()` never
 * writes files and aborts as `stale` if the registry changed since `plan()`
 * ran.
 */
final class VaultIndexService
{
    /**
     * A file's mtime within this many seconds of the scan start is never
     * trusted (stored as null): FAT has 2 s resolution and PHP's mtime has
     * 1 s resolution, so an edit landing in the same window as a scan must
     * never be masked by the quick shortcut on a later check.
     */
    public const RACY_WINDOW_SECONDS = 2;

    /**
     * A `.mdvault-save-*` temp file is only reported as an orphan once it
     * is at least this old, so a file mid-`replaceFile()` is never flagged.
     */
    public const ORPHAN_MIN_AGE_SECONDS = 60;

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
     * A full re-index (manual action). Unchanged contract.
     *
     * @throws NoteOperationException
     */
    public function reindex(Vault $vault): IndexResult
    {
        return $this->reconcile($vault, IndexMode::Full);
    }

    /**
     * Plans and applies a reconcile, retrying once if the apply reports
     * `stale` (the registry changed between `plan()` and `apply()`).
     *
     * @param  list<string>  $verifyPaths  vault-relative paths always hashed (the open note)
     *
     * @throws NoteOperationException
     */
    public function reconcile(Vault $vault, IndexMode $mode = IndexMode::Full, array $verifyPaths = []): IndexResult
    {
        $result = $this->apply($vault, $this->plan($vault, $mode, $verifyPaths));

        if ($result->stale) {
            $result = $this->apply($vault, $this->plan($vault, $mode, $verifyPaths));
        }

        return $result;
    }

    /**
     * Scans the vault, selectively hashes changed files, and pairs records
     * to files without writing anything.
     *
     * @param  list<string>  $verifyPaths
     *
     * @throws NoteOperationException
     */
    public function plan(Vault $vault, IndexMode $mode, array $verifyPaths = []): ReconcilePlan
    {
        if (! $this->files->isDirectory($vault->path)) {
            throw NoteOperationException::vaultUnavailable($vault->path);
        }

        $startedAt = CarbonImmutable::now()->getTimestamp();

        /** @var list<string> $orphanCandidates */
        $orphanCandidates = [];

        $scan = $this->files->scan(
            $vault->path,
            function (string $relative, string $name, bool $isDir) use (&$orphanCandidates): bool {
                if ($isDir) {
                    return ! $this->isIgnoredName($name, true);
                }

                if (str_starts_with($name, FileStorageService::SAVE_TEMP_PREFIX)) {
                    $orphanCandidates[] = $relative;

                    return false;
                }

                return $this->isIndexableFileName($name);
            },
        );

        if (in_array('', $scan['unreadable'], true)) {
            throw NoteOperationException::vaultUnavailable($vault->path);
        }

        $allNotes = $vault->notes()->get();

        /** @var array<int, string> $snapshot */
        $snapshot = [];
        foreach ($allNotes as $note) {
            $snapshot[$note->id] = $this->noteFingerprint($note);
        }

        /** @var array<string, Note> $remainingRows */
        $remainingRows = $allNotes->keyBy('relative_path')->all();

        $verify = array_flip($verifyPaths);

        $skipped = 0;
        $unchanged = 0;

        /** @var array<string, array{size: int, hash: string, mtime: ?int}> $remainingFiles */
        $remainingFiles = [];

        foreach ($scan['files'] as $file) {
            $path = $file['path'];
            $row = $remainingRows[$path] ?? null;

            if (
                $mode === IndexMode::Quick
                && $row !== null
                && ! isset($verify[$path])
                && $row->file_mtime !== null
                && $row->file_mtime === $file['mtime']
                && $row->file_size === $file['size']
            ) {
                $unchanged++;
                unset($remainingRows[$path]);

                continue;
            }

            $hash = $this->hashes->hashFile($this->files->joinRelative($vault->path, $path));

            if ($hash === null) {
                $skipped++;
                // A record at this exact path (if any) is kept untouched:
                // it is excluded from every matching step below.
                unset($remainingRows[$path]);

                continue;
            }

            $trustedMtime = ($file['mtime'] === null || $file['mtime'] >= $startedAt - self::RACY_WINDOW_SECONDS)
                ? null
                : $file['mtime'];

            $remainingFiles[$path] = ['size' => $file['size'], 'hash' => $hash, 'mtime' => $trustedMtime];
        }

        $updates = [];
        $moves = [];
        $touches = [];

        // Step: exact path match.
        foreach (array_keys($remainingFiles) as $path) {
            if (! array_key_exists($path, $remainingRows)) {
                continue;
            }

            $note = $remainingRows[$path];
            $file = $remainingFiles[$path];

            if ($note->file_hash !== $file['hash'] || $note->file_size !== $file['size']) {
                $updates[] = ['id' => $note->id, 'attributes' => [
                    'file_hash' => $file['hash'],
                    'file_size' => $file['size'],
                    'file_mtime' => $file['mtime'],
                ]];
            } elseif ($note->file_mtime !== $file['mtime']) {
                $touches[] = ['id' => $note->id, 'file_mtime' => $file['mtime']];
            } else {
                $unchanged++;
            }

            unset($remainingFiles[$path], $remainingRows[$path]);
        }

        // Step: unique case-insensitive path match.
        $moves = [...$moves, ...$this->pairByKey(
            $remainingRows,
            $remainingFiles,
            fn (string $path): string => mb_strtolower($path),
            fn (string $path): string => mb_strtolower($path),
        )];

        // Step: unique identical hash match.
        $moves = [...$moves, ...$this->pairByKey(
            $remainingRows,
            $remainingFiles,
            fn (string $path, Note $note): string => $note->file_hash,
            fn (string $path, array $file): string => $file['hash'],
        )];

        // Step: unique file name match (G3).
        $moves = [...$moves, ...$this->pairByKey(
            $remainingRows,
            $remainingFiles,
            fn (string $path): string => mb_strtolower(basename($path)),
            fn (string $path): string => mb_strtolower(basename($path)),
        )];

        // Leftover rows are deleted, unless they sit under a directory the
        // scan couldn't read.
        $deletes = [];
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
                $deletes[] = ['id' => $note->id, 'uuid' => $note->uuid, 'path' => $note->relative_path];
            }
        }

        // Leftover files are inserted.
        $inserts = [];
        foreach ($remainingFiles as $path => $file) {
            $inserts[] = $this->insertAttributes($path, $file);
        }

        // Orphans: only `.mdvault-save-*` files old enough not to be a
        // save currently in progress.
        $orphans = [];
        foreach ($orphanCandidates as $relative) {
            $mtime = $this->files->modifiedTime($this->files->joinRelative($vault->path, $relative));

            if ($mtime !== null && $mtime < $startedAt - self::ORPHAN_MIN_AGE_SECONDS) {
                $orphans[] = $relative;
            }
        }

        return new ReconcilePlan(
            snapshot: $snapshot,
            deletes: $deletes,
            updates: $updates,
            moves: $moves,
            touches: $touches,
            inserts: $inserts,
            unchanged: $unchanged,
            skipped: $skipped,
            directories: $scan['directories'],
            orphans: $orphans,
        );
    }

    /**
     * Applies a plan in one transaction, aborting as `stale` (with nothing
     * changed) if the vault's rows no longer match the plan's snapshot, or
     * on a `QueryException`. Never touches the filesystem.
     */
    public function apply(Vault $vault, ReconcilePlan $plan): IndexResult
    {
        $stale = new IndexResult(added: 0, updated: 0, moved: 0, removed: 0, unchanged: 0, skipped: 0, stale: true);

        try {
            /** @var array{changes: list<array{type: 'created'|'modified'|'moved'|'deleted', uuid: string, path: string, from: ?string, content_changed: bool}>, treeSignature: string}|null $outcome */
            $outcome = $this->database->connection()->transaction(function () use ($vault, $plan): ?array {
                // Every row is re-fetched fresh here (never a model carried
                // over from plan()), so a write below always lands on the
                // current row, and the fingerprint check below is against
                // the true current state.
                $freshNotes = $vault->notes()->get();

                $current = $freshNotes
                    ->mapWithKeys(fn (Note $note): array => [$note->id => $this->noteFingerprint($note)])
                    ->all();

                if ($current != $plan->snapshot) {
                    return null;
                }

                /** @var Collection<int, Note> $byId */
                $byId = $freshNotes->keyBy('id');

                $deleteIds = array_column($plan->deletes, 'id');

                if ($deleteIds !== []) {
                    Note::query()->whereIn('id', $deleteIds)->delete();
                }

                /** @var list<array{type: 'created'|'modified'|'moved'|'deleted', uuid: string, path: string, from: ?string, content_changed: bool}> $changes */
                $changes = [];

                foreach ($plan->updates as $change) {
                    $note = $byId[$change['id']];
                    $note->update($change['attributes']);
                    $changes[] = ['type' => 'modified', 'uuid' => $note->uuid, 'path' => $note->relative_path, 'from' => null, 'content_changed' => true];
                }

                foreach ($plan->moves as $change) {
                    $note = $byId[$change['id']];
                    $note->update($change['attributes']);
                    $changes[] = ['type' => 'moved', 'uuid' => $note->uuid, 'path' => $note->relative_path, 'from' => $change['from'], 'content_changed' => $change['content_changed']];
                }

                foreach ($plan->touches as $touch) {
                    $note = $byId[$touch['id']];
                    Note::withoutTimestamps(fn () => $note->update(['file_mtime' => $touch['file_mtime']]));
                }

                foreach ($plan->inserts as $attributes) {
                    $note = $vault->notes()->create($attributes);
                    $changes[] = ['type' => 'created', 'uuid' => $note->uuid, 'path' => $note->relative_path, 'from' => null, 'content_changed' => true];
                }

                foreach ($plan->deletes as $delete) {
                    $changes[] = ['type' => 'deleted', 'uuid' => $delete['uuid'], 'path' => $delete['path'], 'from' => null, 'content_changed' => false];
                }

                return [
                    'changes' => $changes,
                    'treeSignature' => $this->treeSignature($plan->directories, $vault->notes()->pluck('relative_path', 'uuid')->all()),
                ];
            });
        } catch (QueryException $e) {
            report($e);

            return $stale;
        }

        if ($outcome === null) {
            return $stale;
        }

        return new IndexResult(
            added: count($plan->inserts),
            updated: count($plan->updates),
            moved: count($plan->moves),
            removed: count($plan->deletes),
            unchanged: $plan->unchanged,
            skipped: $plan->skipped,
            touched: count($plan->touches),
            changes: $outcome['changes'],
            stale: false,
            orphanTempFiles: $plan->orphans,
            treeSignature: $outcome['treeSignature'],
        );
    }

    /**
     * SHA-256 of the sorted directories plus the sorted "uuid:path" pairs.
     * Computed identically here and in `browse()` so the client can decide
     * whether the tree changed with one string comparison.
     *
     * @param  list<string>  $directories
     * @param  array<string, string>  $notePathsByUuid  uuid => relative_path
     */
    public function treeSignature(array $directories, array $notePathsByUuid): string
    {
        $dirs = $directories;
        usort($dirs, 'strcmp');

        $pairs = [];
        foreach ($notePathsByUuid as $uuid => $path) {
            $pairs[] = "{$uuid}:{$path}";
        }
        usort($pairs, 'strcmp');

        return hash('sha256', json_encode([$dirs, $pairs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array{tree: list<array<string, mixed>>, folders: list<string>, signature: ?string}
     */
    public function browse(Vault $vault, ?string $openPath = null): array
    {
        if (! $this->files->isDirectory($vault->path)) {
            return ['tree' => [], 'folders' => [''], 'signature' => null];
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
            'signature' => $this->treeSignature($scan['directories'], $notes->pluck('relative_path', 'uuid')->all()),
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
     * @param  array{size: int, hash: string, mtime: ?int}  $file
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
            'file_mtime' => $file['mtime'],
        ];
    }

    /**
     * The attributes for a new note row at $relativePath, for direct
     * insertion (e.g. `BackupService::restore()`). `file_mtime` is always
     * null: a restored note's timestamp is never trusted (ADR
     * `external-change-reconciliation`).
     *
     * @return array<string, mixed>
     */
    public function newNoteAttributes(string $relativePath, int $size, string $hash): array
    {
        return $this->insertAttributes($relativePath, ['size' => $size, 'hash' => $hash, 'mtime' => null]);
    }

    /**
     * @param  array{size: int, hash: string, mtime: ?int}  $file
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

    private function noteFingerprint(Note $note): string
    {
        return $note->relative_path."\0".$note->file_hash."\0".$note->file_size;
    }

    /**
     * Pairs leftover rows to leftover files by a shared key, keeping only
     * 1:1 pairs (both sides have exactly one member for that key), and
     * removes each paired side from $remainingRows/$remainingFiles.
     *
     * @param  array<string, Note>  $remainingRows
     * @param  array<string, array{size: int, hash: string, mtime: ?int}>  $remainingFiles
     * @param  callable(string, Note): string  $rowKey
     * @param  callable(string, array{size: int, hash: string, mtime: ?int}): string  $fileKey
     * @return list<array{id: int, attributes: array<string, mixed>, from: string, content_changed: bool}>
     */
    private function pairByKey(array &$remainingRows, array &$remainingFiles, callable $rowKey, callable $fileKey): array
    {
        $rowsByKey = [];
        foreach ($remainingRows as $path => $note) {
            $rowsByKey[$rowKey($path, $note)][] = $path;
        }

        $filesByKey = [];
        foreach ($remainingFiles as $path => $file) {
            $filesByKey[$fileKey($path, $file)][] = $path;
        }

        $moves = [];

        foreach ($rowsByKey as $key => $rowPaths) {
            if (count($rowPaths) !== 1 || ! isset($filesByKey[$key]) || count($filesByKey[$key]) !== 1) {
                continue;
            }

            $oldPath = $rowPaths[0];
            $newPath = $filesByKey[$key][0];
            $note = $remainingRows[$oldPath];
            $file = $remainingFiles[$newPath];

            $moves[] = [
                'id' => $note->id,
                'attributes' => $this->moveAttributes($newPath, $file),
                'from' => $oldPath,
                'content_changed' => $file['hash'] !== $note->file_hash,
            ];

            unset($remainingRows[$oldPath], $remainingFiles[$newPath]);
        }

        return $moves;
    }
}
