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
     * Constant-time comparison of the file's current hash with $expected;
     * false when unreadable.
     */
    public function matches(string $path, string $expected): bool
    {
        $actual = $this->hashFile($path);

        return $actual !== null && hash_equals($expected, $actual);
    }
}
