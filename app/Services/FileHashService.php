<?php

namespace App\Services;

/**
 * SHA-256 hashing of files (streamed) and strings. Only this class calls
 * `hash_file()` (Pest arch rule, ADR note-registry-and-indexing).
 */
final class FileHashService
{
    public const ALGORITHM = 'sha256';

    /**
     * Lowercase hex SHA-256 of the file (streamed), or null if it can't be
     * read or isn't a regular file.
     */
    public function hashFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $hash = @hash_file(self::ALGORITHM, $path);

        return $hash ?: null;
    }

    public function hashString(string $contents): string
    {
        return hash(self::ALGORITHM, $contents);
    }

    /**
     * Streamed SHA-256 of $stream, reading at most $maxBytes + 1 bytes in
     * 1 MiB chunks.
     *
     * @return array{hash: string, bytes: int}|null null when a read fails,
     *                                              or more than $maxBytes bytes are available
     */
    public function hashStream(mixed $stream, int $maxBytes): ?array
    {
        $context = hash_init(self::ALGORITHM);
        $bytes = 0;

        while (! feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);

            if ($chunk === false) {
                return null;
            }

            $length = strlen($chunk);

            if ($length === 0) {
                continue;
            }

            $bytes += $length;

            if ($bytes > $maxBytes) {
                return null;
            }

            hash_update($context, $chunk);
        }

        return ['hash' => hash_final($context), 'bytes' => $bytes];
    }

    /**
     * Constant-time comparison of the file's current hash with $expected;
     * false when unreadable.
     */
    public function matches(string $path, string $expected): bool
    {
        $actual = $this->hashFile($path);

        return $actual !== null && hash_equals($expected, $actual);
    }
}
