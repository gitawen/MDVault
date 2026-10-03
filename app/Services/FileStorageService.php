<?php

namespace App\Services;

use App\Contracts\Trash;
use App\Enums\FileReplaceResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Directory primitives, path comparison and the OS-trash boundary shared by
 * vault operations (the Phase 2 subset of §41's FileStorageService).
 */
final class FileStorageService
{
    /**
     * Prefix of the temp sibling `replaceFile()` writes to before renaming
     * it over its target. Dot-prefixed so the indexer ignores it.
     */
    public const SAVE_TEMP_PREFIX = '.mdvault-save-';

    /**
     * Prefix of the temp sibling `BackupService::create()` writes to before
     * renaming it over the chosen destination. Dot-prefixed so the indexer
     * (and backups themselves) ignore it.
     */
    public const BACKUP_TEMP_PREFIX = '.mdvault-backup-';

    /**
     * Prefix of the hidden staging folder a restore extracts into before
     * moving each vault's folder into place (ADR `backup-restore-semantics`,
     * H9). The only recursive delete in MDVault targets folders with this
     * prefix.
     */
    public const RESTORE_STAGING_PREFIX = '.mdvault-restore-';

    /**
     * Prefixes of the sibling folders a vault conversion uses (ADR
     * `vault-encryption-conversion`): the staging folder it builds, and the
     * replaced original it parks beside the vault until the conversion has
     * committed. `deleteStagingDirectory()` accepts them too.
     */
    public const ENCRYPT_STAGING_PREFIX = '.mdvault-encrypt-';

    public const DECRYPT_STAGING_PREFIX = '.mdvault-decrypt-';

    public const CONVERSION_ORIGINAL_PREFIX = '.mdvault-original-';

    public const REPLACE_ATTEMPTS = 3;

    private const STREAM_CHUNK_BYTES = 1024 * 1024;

    public function __construct(
        private readonly Filesystem $files,
        private readonly Trash $trash,
    ) {}

    public function isDirectory(string $path): bool
    {
        return is_dir($path);
    }

    public function isFile(string $path): bool
    {
        return $this->files->exists($path) && ! is_dir($path);
    }

    /**
     * A directory with no entries at all, including hidden ones.
     */
    public function isEmptyDirectory(string $path): bool
    {
        try {
            $iterator = new \FilesystemIterator($path);

            return ! $iterator->valid();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Create a directory. The parent must already exist (non-recursive).
     */
    public function makeDirectory(string $path): bool
    {
        $this->files->makeDirectory($path, 0755, false, true);

        return is_dir($path);
    }

    /**
     * A real write probe: creates and deletes a temp file inside $path.
     */
    public function isWritableDirectory(string $path): bool
    {
        $probe = $path.DIRECTORY_SEPARATOR.'.mdvault-write-test-'.Str::random(12);

        try {
            return $this->files->put($probe, '') !== false;
        } catch (\Throwable) {
            return false;
        } finally {
            if ($this->files->exists($probe)) {
                $this->files->delete($probe);
            }
        }
    }

    /**
     * Removes a directory only if it is empty. Never deletes a non-empty
     * directory.
     */
    public function deleteEmptyDirectory(string $path): bool
    {
        if (! $this->isEmptyDirectory($path)) {
            return false;
        }

        @rmdir($path);

        return ! is_dir($path);
    }

    public function canonical(string $path): string
    {
        return realpath($path) ?: $path;
    }

    /**
     * Whether a filesystem entry (file or directory) exists at $path.
     */
    public function exists(string $path): bool
    {
        clearstatcache();

        return file_exists($path);
    }

    /**
     * The path with $name as its final segment, in the same parent
     * directory as $path.
     */
    public function siblingPath(string $path, string $name): string
    {
        return dirname($path).DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Whether $a and $b are the same directory: the same normalised path,
     * or (catching a case-insensitive disk that key() doesn't lower-case,
     * such as macOS) the same device and inode.
     */
    public function isSameDirectory(string $a, string $b): bool
    {
        if ($this->samePath($a, $b)) {
            return true;
        }

        if (! is_dir($a) || ! is_dir($b)) {
            return false;
        }

        $statA = @stat($a);
        $statB = @stat($b);

        if ($statA === false || $statB === false) {
            return false;
        }

        return $statA['ino'] !== 0 && $statA['ino'] === $statB['ino'] && $statA['dev'] === $statB['dev'];
    }

    /**
     * Renames/moves a directory from $from to $to, in the same parent
     * folder. Never overwrites anything: a pre-existing target (other than
     * $from itself) makes this return false, since POSIX `rename()` would
     * otherwise silently replace an empty target directory.
     *
     * A case-only change on a case-insensitive filesystem (`Work` ->
     * `work`) goes through a hidden sibling temp name in two steps,
     * restored if the second step fails.
     *
     * The result is checked afterwards (`is_dir($to)`, and $from gone
     * unless this was a case-only change) — the only source of truth,
     * since the underlying move can fail silently. On Windows, a lock held
     * by another program (File Explorer / Finder, VS Code, a terminal, or
     * sync tools such as OneDrive) makes this return false with nothing
     * changed.
     */
    public function renameDirectory(string $from, string $to): bool
    {
        if ($this->exists($to) && ! $this->isSameDirectory($from, $to)) {
            return false;
        }

        $caseOnly = $from !== $to && $this->isSameDirectory($from, $to);

        if ($caseOnly) {
            $tmp = $this->siblingPath($from, '.mdvault-rename-'.Str::random(12));

            if (! $this->attemptMove($from, $tmp)) {
                return false;
            }

            if (! $this->attemptMove($tmp, $to)) {
                $this->attemptMove($tmp, $from);

                return false;
            }
        } elseif (! $this->attemptMove($from, $to)) {
            return false;
        }

        clearstatcache();

        return is_dir($to) && ($caseOnly || ! file_exists($from));
    }

    private function attemptMove(string $from, string $to): bool
    {
        try {
            return $this->files->moveDirectory($from, $to, false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function isSymlink(string $path): bool
    {
        return is_link($path);
    }

    /**
     * Whether $a and $b are the same file: the same normalised path, or
     * (catching a case-insensitive disk that key() doesn't lower-case, such
     * as macOS) both regular files with the same non-zero device and inode.
     */
    public function isSameFile(string $a, string $b): bool
    {
        if ($this->samePath($a, $b)) {
            return true;
        }

        if (! is_file($a) || ! is_file($b)) {
            return false;
        }

        $statA = @stat($a);
        $statB = @stat($b);

        if ($statA === false || $statB === false) {
            return false;
        }

        return $statA['ino'] !== 0 && $statA['ino'] === $statB['ino'] && $statA['dev'] === $statB['dev'];
    }

    /**
     * Exclusive create: never overwrites an existing file. The result is
     * checked afterwards (`is_file`), the only source of truth. A short
     * write (disk full, mid-write) is never left as a partial file: it is
     * truncated to empty and then removed (Phase 4 Revision 4).
     */
    public function createFile(string $path, string $contents = ''): bool
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return false;
        }

        try {
            $written = fwrite($handle, $contents);
        } finally {
            if (($written ?? false) !== strlen($contents)) {
                @ftruncate($handle, 0);
            }

            fclose($handle);
        }

        if ($written !== strlen($contents)) {
            $this->deleteNewEmptyFile($path);

            return false;
        }

        clearstatcache();

        return is_file($path);
    }

    /**
     * Compensation only: removes $path only if it is a regular file whose
     * entire contents still exactly equal $contents, i.e. this call's own
     * write (Phase 4 Revision 4: `createFile`'s template content, not just
     * an empty file). Never deletes anything else.
     */
    public function deleteNewFileWithContents(string $path, string $contents): bool
    {
        clearstatcache();

        if (! is_file($path) || is_link($path) || filesize($path) !== strlen($contents)) {
            return false;
        }

        $actual = $this->read($path);

        if ($actual === null || ! hash_equals($contents, $actual)) {
            return false;
        }

        @unlink($path);

        clearstatcache();

        return ! file_exists($path);
    }

    /**
     * Compensation only: removes $path only if it is a regular file of size
     * 0. Never deletes user content.
     */
    public function deleteNewEmptyFile(string $path): bool
    {
        return $this->deleteNewFileWithContents($path, '');
    }

    public function isWritableFile(string $path): bool
    {
        clearstatcache();

        return is_file($path) && ! is_link($path) && is_writable($path);
    }

    /**
     * The ONLY method that replaces an existing file (ADR
     * `note-save-atomic-replace`). Writes a full temp sibling first, so a
     * crash or a full disk mid-write never truncates the target. $beforeReplace
     * runs after the temp file is complete and immediately before the
     * rename; returning false aborts, leaving the target untouched.
     *
     * A Windows lock held by another program (antivirus, a sync tool, an
     * editor without share-delete) makes the rename fail with the original
     * file intact. A crash between the temp write and the rename may leave
     * a hidden `.mdvault-save-*` orphan holding the user's new text; it is
     * never auto-deleted.
     */
    public function replaceFile(string $path, string $contents, ?callable $beforeReplace = null): FileReplaceResult
    {
        if (! is_file($path) || is_link($path)) {
            return FileReplaceResult::TargetInvalid;
        }

        $tmp = $this->siblingPath($path, self::SAVE_TEMP_PREFIX.Str::random(12));

        if (! $this->createFile($tmp)) {
            return FileReplaceResult::WriteFailed;
        }

        try {
            $written = $this->files->put($tmp, $contents);
        } catch (\Throwable) {
            $written = false;
        }

        if ($written !== strlen($contents) || $this->size($tmp) !== strlen($contents)) {
            $this->discardTempFile($tmp);

            return FileReplaceResult::WriteFailed;
        }

        $this->flushToDisk($tmp);

        $perms = @fileperms($path);

        if ($perms !== false) {
            @chmod($tmp, $perms & 0777);
        }

        if ($beforeReplace !== null && ! $beforeReplace()) {
            $this->discardTempFile($tmp);

            return FileReplaceResult::GuardFailed;
        }

        $moved = false;

        for ($attempt = 1; $attempt <= self::REPLACE_ATTEMPTS; $attempt++) {
            if ($this->attemptFileMove($tmp, $path)) {
                $moved = true;

                break;
            }

            if ($attempt < self::REPLACE_ATTEMPTS) {
                usleep(100_000);
            }
        }

        clearstatcache();

        if (! $moved || file_exists($tmp)) {
            $this->discardTempFile($tmp);

            return FileReplaceResult::ReplaceFailed;
        }

        return FileReplaceResult::Replaced;
    }

    /**
     * Best-effort fsync of a just-written temp file. Failures are ignored:
     * this is a durability improvement, not a correctness requirement.
     */
    private function flushToDisk(string $path): void
    {
        $handle = @fopen($path, 'r+');

        if ($handle === false) {
            return;
        }

        try {
            @fsync($handle);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Compensation/cleanup only: removes $path only if it is a regular,
     * non-symlink file whose basename starts with SAVE_TEMP_PREFIX or
     * BACKUP_TEMP_PREFIX. Never touches a user's file.
     */
    public function discardTempFile(string $path): bool
    {
        $basename = basename($path);
        $allowedPrefix = str_starts_with($basename, self::SAVE_TEMP_PREFIX)
            || str_starts_with($basename, self::BACKUP_TEMP_PREFIX);

        if (! $allowedPrefix || ! is_file($path) || is_link($path)) {
            return false;
        }

        @unlink($path);

        clearstatcache();

        return ! file_exists($path);
    }

    /**
     * Best-effort: discards $prefix temp files (allow-listed prefixes only:
     * SAVE_TEMP_PREFIX or BACKUP_TEMP_PREFIX) directly in $directory whose
     * mtime is older than now - $minAgeSeconds. Returns the count removed.
     */
    public function discardStaleTempFiles(string $directory, string $prefix, int $minAgeSeconds): int
    {
        if (! in_array($prefix, [self::SAVE_TEMP_PREFIX, self::BACKUP_TEMP_PREFIX], true)) {
            return 0;
        }

        try {
            $iterator = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return 0;
        }

        $cutoff = time() - $minAgeSeconds;
        $count = 0;

        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            if ($iterator->isLink() || ! $iterator->isFile()) {
                continue;
            }

            if (! str_starts_with($iterator->getFilename(), $prefix)) {
                continue;
            }

            $pathname = $iterator->getPathname();
            $mtime = @filemtime($pathname);

            if ($mtime !== false && $mtime < $cutoff && $this->discardTempFile($pathname)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The ONLY recursive delete in MDVault (ADR `backup-restore-semantics`,
     * H9). Accepts only a non-symlink directory whose basename starts with
     * RESTORE_STAGING_PREFIX or, since Phase 7 (ADR
     * `vault-encryption-conversion`), a conversion prefix
     * (ENCRYPT_STAGING_PREFIX, DECRYPT_STAGING_PREFIX,
     * CONVERSION_ORIGINAL_PREFIX); uses `Filesystem::deleteDirectory` (does
     * not follow links inside it). The result is checked afterwards.
     */
    public function deleteStagingDirectory(string $path): bool
    {
        $basename = basename($path);
        $allowed = false;

        foreach ([self::RESTORE_STAGING_PREFIX, self::ENCRYPT_STAGING_PREFIX, self::DECRYPT_STAGING_PREFIX, self::CONVERSION_ORIGINAL_PREFIX] as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                $allowed = true;

                break;
            }
        }

        if (! $allowed || ! is_dir($path) || is_link($path)) {
            return false;
        }

        $this->files->deleteDirectory($path);

        clearstatcache();

        return ! is_dir($path);
    }

    /**
     * @return list<string> absolute paths of RESTORE_STAGING_PREFIX
     *                      directories directly in $root older than now - $minAgeSeconds
     */
    public function staleStagingDirectories(string $root, int $minAgeSeconds): array
    {
        try {
            $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return [];
        }

        $cutoff = time() - $minAgeSeconds;

        /** @var list<string> $result */
        $result = [];

        for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
            if ($iterator->isLink() || ! $iterator->isDir()) {
                continue;
            }

            if (! str_starts_with($iterator->getFilename(), self::RESTORE_STAGING_PREFIX)) {
                continue;
            }

            $pathname = $iterator->getPathname();
            $mtime = @filemtime($pathname);

            if ($mtime !== false && $mtime < $cutoff) {
                $result[] = $pathname;
            }
        }

        sort($result);

        return $result;
    }

    /**
     * Recursive mkdir (used only inside a freshly created staging folder).
     * True if $path is a directory afterwards.
     */
    public function makeDirectories(string $path): bool
    {
        if (! is_dir($path)) {
            $this->files->makeDirectory($path, 0755, true, true);
        }

        return is_dir($path);
    }

    /**
     * Best-effort `touch($path, $mtime)`; false on failure.
     */
    public function setModifiedTime(string $path, int $mtime): bool
    {
        return @touch($path, $mtime);
    }

    /**
     * `disk_free_space($directory)` as an int, or null when it can't be
     * determined.
     */
    public function freeSpace(string $directory): ?int
    {
        $space = @disk_free_space($directory);

        return $space === false ? null : (int) $space;
    }

    /**
     * Exclusive-create $path ('x') and copy at most $maxBytes from $stream
     * in 1 MiB chunks. Returns the number of bytes written; null on any
     * failure, including the stream yielding more than $maxBytes. On
     * failure the file this call created is removed. Never overwrites an
     * existing file.
     */
    public function createFileFromStream(string $path, mixed $stream, int $maxBytes): ?int
    {
        $handle = @fopen($path, 'x');

        if ($handle === false) {
            return null;
        }

        $written = 0;
        $failed = false;

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::STREAM_CHUNK_BYTES);

                if ($chunk === false) {
                    $failed = true;

                    break;
                }

                $length = strlen($chunk);

                if ($length === 0) {
                    continue;
                }

                $written += $length;

                if ($written > $maxBytes || fwrite($handle, $chunk) !== $length) {
                    $failed = true;

                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        clearstatcache();

        if ($failed || ! is_file($path) || filesize($path) !== $written) {
            if (is_file($path) && ! is_link($path)) {
                @unlink($path);
                clearstatcache();
            }

            return null;
        }

        return $written;
    }

    public function read(string $path): ?string
    {
        try {
            $contents = file_get_contents($path);
        } catch (\Throwable) {
            return null;
        }

        return $contents === false ? null : $contents;
    }

    public function size(string $path): ?int
    {
        clearstatcache();

        if (! is_file($path)) {
            return null;
        }

        $size = @filesize($path);

        return $size === false ? null : $size;
    }

    /**
     * The file's Unix modification time, or null if it can't be read.
     */
    public function modifiedTime(string $path): ?int
    {
        clearstatcache();

        $time = @filemtime($path);

        return $time === false ? null : $time;
    }

    /**
     * $root joined with a '/'-separated relative path, using
     * DIRECTORY_SEPARATOR. An empty relative path returns $root unchanged.
     * No validation is performed.
     */
    public function joinRelative(string $root, string $relative): string
    {
        if ($relative === '') {
            return $root;
        }

        $native = str_replace('/', DIRECTORY_SEPARATOR, $relative);

        return rtrim($root, '\\/').DIRECTORY_SEPARATOR.$native;
    }

    /**
     * Renames/moves a file from $from to $to, never overwriting an existing
     * target. A case-only change on a case-insensitive filesystem goes
     * through a hidden sibling temp name in two steps, restored if the
     * second step fails. The result is checked afterwards — PHP `rename()`
     * silently **replaces** an existing target file on both POSIX and
     * Windows, so the pre-check is mandatory. A Windows lock held by
     * another program makes this return false with nothing changed.
     */
    public function renameFile(string $from, string $to): bool
    {
        if ($this->exists($to) && ! $this->isSameFile($from, $to)) {
            return false;
        }

        $caseOnly = $from !== $to && $this->isSameFile($from, $to);

        if ($caseOnly) {
            $tmp = $this->siblingPath($from, '.mdvault-rename-'.Str::random(12));

            if (! $this->attemptFileMove($from, $tmp)) {
                return false;
            }

            if (! $this->attemptFileMove($tmp, $to)) {
                $this->attemptFileMove($tmp, $from);

                return false;
            }
        } elseif (! $this->attemptFileMove($from, $to)) {
            return false;
        }

        clearstatcache();

        return is_file($to) && ($caseOnly || ! file_exists($from));
    }

    private function attemptFileMove(string $from, string $to): bool
    {
        try {
            return $this->files->move($from, $to);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Walks $root without following symlinks/junctions. $accept decides
     * whether a directory or file is included; rejected directories are
     * not descended into. Every returned list is '/'-separated relative to
     * $root and sorted with strcmp. `unreadable` lists relative directories
     * that could not be listed ('' means the root itself). Each file's
     * `mtime` is its Unix modification time, or null if it couldn't be
     * read.
     *
     * @param  callable(string $relativePath, string $name, bool $isDirectory): bool  $accept
     * @return array{files: list<array{path: string, size: int, mtime: ?int}>, directories: list<string>, unreadable: list<string>}
     */
    public function scan(string $root, callable $accept): array
    {
        $files = [];
        $directories = [];
        $unreadable = [];

        /** @var list<string> $stack */
        $stack = [''];

        while ($stack !== []) {
            $relativeDir = array_shift($stack);
            $absoluteDir = $this->joinRelative($root, $relativeDir);

            try {
                $iterator = new \FilesystemIterator($absoluteDir, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                $unreadable[] = $relativeDir;

                continue;
            }

            for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
                if ($iterator->isLink()) {
                    continue;
                }

                $name = $iterator->getFilename();
                $entryRelative = $relativeDir === '' ? $name : $relativeDir.'/'.$name;

                if ($iterator->isDir()) {
                    if ($accept($entryRelative, $name, true)) {
                        $directories[] = $entryRelative;
                        $stack[] = $entryRelative;
                    }
                } elseif ($iterator->isFile()) {
                    if ($accept($entryRelative, $name, false)) {
                        try {
                            $size = $iterator->getSize();
                        } catch (\Throwable) {
                            $size = 0;
                        }

                        try {
                            $mtime = $iterator->getMTime();

                            if ($mtime <= 0) {
                                $mtime = null;
                            }
                        } catch (\Throwable) {
                            $mtime = null;
                        }

                        $files[] = ['path' => $entryRelative, 'size' => $size, 'mtime' => $mtime];
                    }
                }
            }

            unset($iterator);
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($directories, 'strcmp');
        usort($unreadable, 'strcmp');

        return ['files' => $files, 'directories' => $directories, 'unreadable' => $unreadable];
    }

    /**
     * Everything under $root, unfiltered and without following links: dot
     * entries are included, and symlinks/junctions are listed (never
     * descended into). Used by the conversion preflight, which must see
     * what `scan()` hides. Every list is '/'-separated relative to $root
     * and sorted with strcmp; `unreadable` lists directories that could
     * not be listed ('' means the root).
     *
     * @return array{files: list<array{path: string, size: int, mtime: ?int}>, directories: list<string>, symlinks: list<string>, unreadable: list<string>}
     */
    public function inventory(string $root): array
    {
        $files = [];
        $directories = [];
        $symlinks = [];
        $unreadable = [];

        /** @var list<string> $stack */
        $stack = [''];

        while ($stack !== []) {
            $relativeDir = array_shift($stack);

            try {
                $iterator = new \FilesystemIterator($this->joinRelative($root, $relativeDir), \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                $unreadable[] = $relativeDir;

                continue;
            }

            for ($iterator->rewind(); $iterator->valid(); $iterator->next()) {
                $name = $iterator->getFilename();
                $entryRelative = $relativeDir === '' ? $name : $relativeDir.'/'.$name;

                if ($iterator->isLink()) {
                    $symlinks[] = $entryRelative;
                } elseif ($iterator->isDir()) {
                    $directories[] = $entryRelative;
                    $stack[] = $entryRelative;
                } else {
                    try {
                        $size = $iterator->getSize();
                    } catch (\Throwable) {
                        $size = 0;
                    }

                    try {
                        $mtime = $iterator->getMTime();
                        $mtime = $mtime > 0 ? $mtime : null;
                    } catch (\Throwable) {
                        $mtime = null;
                    }

                    $files[] = ['path' => $entryRelative, 'size' => $size, 'mtime' => $mtime];
                }
            }

            unset($iterator);
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['path'], $b['path']));
        usort($directories, 'strcmp');
        usort($symlinks, 'strcmp');
        usort($unreadable, 'strcmp');

        return ['files' => $files, 'directories' => $directories, 'symlinks' => $symlinks, 'unreadable' => $unreadable];
    }

    public function samePath(string $a, string $b): bool
    {
        return $this->key($a) === $this->key($b);
    }

    /**
     * Whether $path is $ancestor itself, or is inside it. Requires a real
     * separator boundary, so `C:\a\Work` is not "inside" `C:\a\Wo`.
     */
    public function isSameOrInside(string $path, string $ancestor): bool
    {
        if ($this->samePath($path, $ancestor)) {
            return true;
        }

        $ancestorKey = rtrim($this->key($ancestor), '/').'/';

        return str_starts_with($this->key($path), $ancestorKey);
    }

    /**
     * The forward-slash remainder of $path after $ancestor, in $path's own
     * original case. Null unless $path is strictly inside $ancestor.
     */
    public function relativeTo(string $path, string $ancestor): ?string
    {
        if (! $this->isSameOrInside($path, $ancestor) || $this->samePath($path, $ancestor)) {
            return null;
        }

        $canonicalPath = str_replace('\\', '/', $this->canonical($path));
        $ancestorKey = rtrim($this->key($ancestor), '/').'/';

        return mb_substr($canonicalPath, mb_strlen($ancestorKey));
    }

    public function isFilesystemRoot(string $path): bool
    {
        if ($path === '/') {
            return true;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]?$/', $path) === 1) {
            return true;
        }

        // A UNC share root (\\server\share), tolerant of either separator
        // since key() calls this after normalising to forward slashes.
        $normalized = str_replace('\\', '/', $path);

        return preg_match('#^//[^/]+/[^/]+/?$#', $normalized) === 1;
    }

    public function canTrash(): bool
    {
        return $this->trash->isAvailable();
    }

    /**
     * Asks the OS to move $path to the Recycle Bin / Trash, then verifies
     * it is actually gone. This check afterwards is the only source of
     * truth, since the OS call can fail silently.
     */
    public function moveToTrash(string $path): bool
    {
        if (! $this->canTrash()) {
            return false;
        }

        try {
            $this->trash->moveToTrash($path);
        } catch (\Throwable) {
            return false;
        }

        clearstatcache();

        return ! file_exists($path);
    }

    /**
     * A normalised comparison key: canonical, forward slashes, no trailing
     * slash (unless it is a root), lower-cased on Windows.
     */
    private function key(string $path): string
    {
        $canonical = str_replace('\\', '/', $this->canonical($path));

        if (! $this->isFilesystemRoot($canonical) && $canonical !== '/') {
            $canonical = rtrim($canonical, '/');
        }

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($canonical) : $canonical;
    }
}
