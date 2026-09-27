<?php

namespace App\Services;

use App\Contracts\Trash;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Directory primitives, path comparison and the OS-trash boundary shared by
 * vault operations (the Phase 2 subset of §41's FileStorageService).
 */
final class FileStorageService
{
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
