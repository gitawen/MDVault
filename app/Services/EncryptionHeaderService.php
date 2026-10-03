<?php

namespace App\Services;

use App\Exceptions\EncryptionException;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Support\EncryptionHeader;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;

/**
 * Reads the key file of a folder and records it in the registry mirror
 * (ADR `encrypted-vault-storage-layout`). It has no dependency on
 * `VaultService`, so `VaultService::register()` can detect an encrypted
 * folder without a cycle with `VaultEncryptionService` (which creates vaults
 * through `VaultService`).
 */
final class EncryptionHeaderService
{
    public const HEADER_FILENAME = 'mdvault-encryption.json';

    public const HEADER_MAX_BYTES = 65536;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly FileStorageService $files,
        private readonly FileHashService $hasher,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * The key file in $folder and its SHA-256, or null when there is none.
     *
     * @return array{0: EncryptionHeader, 1: string}|null
     *
     * @throws EncryptionException when a file exists but is not a valid key file
     */
    public function inspect(string $folder): ?array
    {
        $path = $this->headerPath($folder);

        if (! $this->files->exists($path)) {
            return null;
        }

        $bytes = $this->readHeaderBytes($path);

        return [$this->encryption->parseHeader($bytes), $this->hasher->hashString($bytes)];
    }

    /**
     * The key file in $folder, or null when there is none.
     *
     * @throws EncryptionException when a file exists but is not a valid key file
     */
    public function headerAt(string $folder): ?EncryptionHeader
    {
        return $this->inspect($folder)[0] ?? null;
    }

    /**
     * Records the key file in the registry and marks the vault encrypted,
     * in one transaction.
     */
    public function adopt(Vault $vault, EncryptionHeader $header, string $headerHash): void
    {
        $this->database->connection()->transaction(function () use ($vault, $header, $headerHash): void {
            $this->syncMirror($vault, $header, $headerHash);

            if (! $vault->is_encrypted) {
                $vault->forceFill(['is_encrypted' => true])->save();
            }
        });
    }

    public function syncMirror(Vault $vault, EncryptionHeader $header, string $headerHash): void
    {
        $mirror = VaultEncryption::query()->firstOrNew(['vault_id' => $vault->id]);
        $mirror->fill($header->toRegistryAttributes($headerHash));

        // The row's creation time doubles as the key file's `created_at`
        // (the table has no separate column), so a key file rebuilt from the
        // mirror is byte-identical. It is set on every sync, not just the
        // first, so a key file that changed on disk (a different
        // `created_at`) still rebuilds identically (7A-QA-05).
        $mirror->forceFill(['created_at' => CarbonImmutable::parse($header->createdAt, 'UTC')]);

        $mirror->save();
    }

    /**
     * @throws EncryptionException
     */
    public function readHeaderBytes(string $path): string
    {
        $size = $this->files->size($path);

        if ($this->files->isSymlink($path) || $size === null || $size > self::HEADER_MAX_BYTES) {
            throw EncryptionException::damagedHeader();
        }

        $bytes = $this->files->read($path);

        if ($bytes === null) {
            throw EncryptionException::damagedHeader();
        }

        return $bytes;
    }

    public function headerPath(string $folder): string
    {
        return $this->files->joinRelative($folder, self::HEADER_FILENAME);
    }
}
