<?php

namespace App\Services;

use App\Exceptions\BackupException;
use ZipArchive;

/**
 * The only class that touches `ZipArchive` (Pest arch rule, ADR
 * `backup-archive-format`). Writes are exclusive-create (never overwrite);
 * reads are read-only with consistency checks. `extractTo()` is never used
 * anywhere in MDVault.
 */
final class ArchiveService
{
    /**
     * Symlink mode bits (S_IFLNK) as stored in the upper 16 bits of a Unix
     * external attribute.
     */
    private const UNIX_MODE_MASK = 0170000;

    private const UNIX_MODE_SYMLINK = 0120000;

    /**
     * Creates a NEW zip at $path (`ZipArchive::CREATE | ZipArchive::EXCL`).
     * Entries are added in order: $strings (e.g. manifest.json first), then
     * directory entries (names ending '/'), then files via `addFile`.
     *
     * @param  array<string, string>  $strings  entry name => contents
     * @param  list<string>  $directories  entry names ending in '/'
     * @param  list<array{name: string, source: string}>  $files
     *
     * @throws BackupException archiveWriteFailed on open/add/close failure (the caller discards the temp file)
     */
    public function write(string $path, array $strings, array $directories, array $files): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw BackupException::archiveWriteFailed(dirname($path));
        }

        // `addFile()` only registers an entry; its source is read lazily,
        // when `close()` is called. If a source becomes unreadable in the
        // meantime (deleted, locked, or — as in this branch — never a
        // regular file to begin with), `close()` is what fails. `close()`
        // is therefore called AT MOST ONCE below, no matter which branch is
        // taken: PHP 8's ext-zip sets the internal handle to null once
        // `close()` has been attempted, so a second call throws
        // `ValueError("Invalid or uninitialized Zip object")` — verified
        // empirically, including after a successful close. `@` here only
        // suppresses the warning-to-`ErrorException` promotion (Laravel's
        // error handler checks `error_reporting()`, which `@` zeroes), not
        // a thrown `ValueError`; since this is always the first and only
        // close attempt on this object, no `ValueError` can occur here.
        try {
            foreach ($strings as $name => $contents) {
                if (! $zip->addFromString($name, $contents)) {
                    throw BackupException::archiveWriteFailed(dirname($path));
                }
            }

            foreach ($directories as $name) {
                if (! $zip->addEmptyDir(rtrim($name, '/'))) {
                    throw BackupException::archiveWriteFailed(dirname($path));
                }
            }

            foreach ($files as $file) {
                if (! $zip->addFile($file['source'], $file['name'])) {
                    throw BackupException::archiveWriteFailed(dirname($path));
                }
            }
        } catch (\Throwable $e) {
            // Nothing has been flushed to disk yet, and close() has not
            // been attempted, so a single cleanup close is safe here.
            $zip->unchangeAll();
            @$zip->close();

            throw $e instanceof BackupException ? $e : BackupException::archiveWriteFailed(dirname($path));
        }

        try {
            $closed = $zip->close();
        } catch (\Throwable) {
            // A warning promoted to an exception (or, in principle, a
            // ValueError) from this — the first and only — close attempt.
            // Never retry or call close() again after this point.
            throw BackupException::archiveWriteFailed(dirname($path));
        }

        if (! $closed) {
            throw BackupException::archiveWriteFailed(dirname($path));
        }
    }

    /**
     * Opens read-only (`RDONLY | CHECKCONS`).
     *
     * @return list<array{name: string, size: int, compressed_size: int, is_directory: bool, is_symlink: bool, is_encrypted: bool}>
     *
     * @throws BackupException notAnArchive when it can't be opened or read
     */
    public function entries(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw BackupException::notAnArchive();
        }

        try {
            /** @var list<array{name: string, size: int, compressed_size: int, is_directory: bool, is_symlink: bool, is_encrypted: bool}> $result */
            $result = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat === false) {
                    throw BackupException::notAnArchive();
                }

                $result[] = [
                    'name' => $stat['name'],
                    'size' => $stat['size'],
                    'compressed_size' => $stat['comp_size'],
                    'is_directory' => str_ends_with($stat['name'], '/'),
                    'is_symlink' => $this->isSymlinkEntry($zip, $i),
                    'is_encrypted' => $stat['encryption_method'] !== ZipArchive::EM_NONE,
                ];
            }

            return $result;
        } finally {
            $zip->close();
        }
    }

    /**
     * The whole entry as a string when it exists and its size is <=
     * $maxBytes; otherwise null.
     *
     * @throws BackupException notAnArchive
     */
    public function readEntry(string $path, string $name, int $maxBytes): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw BackupException::notAnArchive();
        }

        try {
            $stat = $zip->statName($name);

            if ($stat === false || $stat['size'] > $maxBytes) {
                return null;
            }

            $contents = $zip->getFromName($name);

            return $contents === false ? null : $contents;
        } finally {
            $zip->close();
        }
    }

    /**
     * Opens once; for each name in order, gets a read stream (`getStream`)
     * and calls $consumer($name, $stream), then closes the stream. A
     * missing entry calls $consumer($name, null).
     *
     * @param  list<string>  $names
     * @param  callable(string, mixed): void  $consumer
     *
     * @throws BackupException notAnArchive
     */
    public function eachEntryStream(string $path, array $names, callable $consumer): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw BackupException::notAnArchive();
        }

        try {
            foreach ($names as $name) {
                $stream = $zip->getStream($name);

                if ($stream === false) {
                    $consumer($name, null);

                    continue;
                }

                try {
                    $consumer($name, $stream);
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function isSymlinkEntry(ZipArchive $zip, int $index): bool
    {
        $opsys = 0;
        $attr = 0;

        if (! $zip->getExternalAttributesIndex($index, $opsys, $attr)) {
            return false;
        }

        return $opsys === ZipArchive::OPSYS_UNIX
            && ($attr >> 16 & self::UNIX_MODE_MASK) === self::UNIX_MODE_SYMLINK;
    }
}
