<?php

namespace App\Services;

use App\Enums\FileReplaceResult;
use App\Enums\VaultStatus;
use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Support\EncryptionHeader;
use Carbon\CarbonImmutable;

/**
 * Key-file and mirror management for encrypted vaults (ADR
 * `encrypted-vault-storage-layout`): the on-disk `mdvault-encryption.json`
 * is authoritative and `vault_encryption` mirrors it. Also unlock, lock and
 * password change. Passwords exist only as call arguments here.
 */
final class VaultEncryptionService
{
    public const HEADER_FILENAME = EncryptionHeaderService::HEADER_FILENAME;

    public const HEADER_MAX_BYTES = EncryptionHeaderService::HEADER_MAX_BYTES;

    public function __construct(
        private readonly EncryptionService $encryption,
        private readonly VaultKeyService $keys,
        private readonly FileStorageService $files,
        private readonly FileHashService $hasher,
        private readonly EncryptionHeaderService $headers,
        private readonly VaultService $vaults,
        private readonly VaultRecoveryService $recovery,
    ) {}

    /**
     * Creates a new encrypted vault (ADR `vault-encryption-conversion`): the
     * vault is created empty and unencrypted, then the key file is written
     * and the mirror and `is_encrypted` are recorded in one transaction, and
     * the fresh key goes straight into the keyring (no second Argon2 run).
     * If anything after the vault was created fails, the key file (only if
     * its bytes still match), the record and the empty folder are removed
     * again and the failure is rethrown.
     *
     * @return array{0: Vault, 1: string} the vault and the unlock token
     *
     * @throws EncryptionException
     * @throws VaultOperationException
     */
    public function createEncryptedVault(string $name, ?string $description, #[\SensitiveParameter] string $password): array
    {
        $vault = $this->vaults->create($name, $description);
        $path = $this->headers->headerPath($vault->path);
        $bytes = null;

        try {
            [$header, $key] = $this->encryption->newVaultKey($password);
            $bytes = $this->encryption->encodeHeader($header);

            if (! $this->files->createFile($path, $bytes)) {
                throw EncryptionException::keyFileWriteFailed();
            }

            $this->headers->adopt($vault, $header, $this->hasher->hashString($bytes));

            return [$vault->refresh(), $this->keys->store($vault, $key)];
        } catch (\Throwable $e) {
            if ($bytes !== null) {
                $this->files->deleteNewFileWithContents($path, $bytes);
            }

            $this->keys->forget($vault);

            try {
                $this->vaults->remove($vault);
                $this->files->deleteEmptyDirectory($vault->path);
            } catch (\Throwable $cleanup) {
                report($cleanup);
            }

            throw $e;
        }
    }

    /**
     * The vault's key material: the key file on disk first, else the mirror.
     *
     * @return array{0: EncryptionHeader, 1: 'disk'|'registry', 2: ?string} the header, where it came from, and the key file's SHA-256 (disk only)
     *
     * @throws EncryptionException
     */
    public function readHeader(Vault $vault): array
    {
        $path = $this->headerPath($vault->path);

        if ($this->files->exists($path)) {
            $bytes = $this->readHeaderBytes($path);

            return [$this->encryption->parseHeader($bytes), 'disk', $this->hasher->hashString($bytes)];
        }

        $row = VaultEncryption::query()->where('vault_id', $vault->id)->first();

        if ($row === null) {
            throw EncryptionException::headerMissing();
        }

        return [$this->headerFromMirror($row), 'registry', null];
    }

    /**
     * Verifies the password and unlocks the vault for this session. Returns
     * the token the renderer must hold. The disk key file wins over the
     * mirror: a changed file updates the mirror, and a missing file is
     * re-created from the mirror once the password has proven it genuine.
     *
     * @throws EncryptionException
     * @throws VaultOperationException
     */
    public function unlock(Vault $vault, #[\SensitiveParameter] string $password): string
    {
        if (! $vault->is_encrypted) {
            throw EncryptionException::notEncrypted();
        }

        if ($vault->status === VaultStatus::Missing || ! $this->files->isDirectory($vault->path)) {
            throw VaultOperationException::folderMissing($vault->path, 'vault');
        }

        [$header, $source, $diskHash] = $this->readHeader($vault);

        $key = $this->encryption->unlock($header, $password);

        $this->syncAfterUnlock($vault, $header, $source, $diskHash);

        return $this->keys->store($vault, $key);
    }

    /**
     * Whether the vault's key material is usable: a valid key file on disk,
     * or else a mirror row. False means the vault can't be unlocked until
     * the key file is restored.
     */
    public function isConsistent(Vault $vault): bool
    {
        try {
            $this->readHeader($vault);

            return true;
        } catch (EncryptionException) {
            return false;
        }
    }

    public function lock(Vault $vault): void
    {
        $this->keys->forget($vault);
    }

    public function lockAll(): void
    {
        $this->keys->forgetAll();
    }

    /**
     * Re-wraps the data key under a new password. Notes are untouched and
     * the vault stays unlocked: the data key and its id do not change.
     *
     * @throws EncryptionException
     */
    public function changePassword(
        Vault $vault,
        #[\SensitiveParameter] string $current,
        #[\SensitiveParameter] string $new,
    ): void {
        $this->recover($vault);

        if (! $vault->is_encrypted) {
            throw EncryptionException::notEncrypted();
        }

        [$header, $source, $diskHash] = $this->readHeader($vault);

        $key = $this->encryption->unlock($header, $current);
        $next = $this->encryption->rewrap($header, $key, $new);
        $bytes = $this->encryption->encodeHeader($next);
        $path = $this->headerPath($vault->path);

        if ($source === 'disk') {
            $result = $this->files->replaceFile(
                $path,
                $bytes,
                fn (): bool => $this->hasher->hashFile($path) === $diskHash,
            );

            if ($result !== FileReplaceResult::Replaced) {
                throw EncryptionException::keyFileWriteFailed();
            }
        } elseif (! $this->files->createFile($path, $bytes)) {
            throw EncryptionException::keyFileWriteFailed();
        }

        // The disk copy is the truth now; a failed mirror update is repaired
        // by the next unlock.
        try {
            $this->syncMirror($vault, $next, $this->hasher->hashString($bytes));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Repairs what a crashed conversion left behind (ADR
     * `vault-encryption-conversion`). See `VaultRecoveryService`.
     */
    public function recover(Vault $vault): ?string
    {
        return $this->recovery->recover($vault);
    }

    /**
     * The key file in $folder, or null when there is none.
     *
     * @throws EncryptionException when a file exists but is not a valid key file
     */
    public function headerAt(string $folder): ?EncryptionHeader
    {
        return $this->headers->headerAt($folder);
    }

    /**
     * Records the key file in the registry and marks the vault encrypted,
     * in one transaction.
     */
    public function adopt(Vault $vault, EncryptionHeader $header, string $headerHash): void
    {
        $this->headers->adopt($vault, $header, $headerHash);
    }

    /**
     * Re-creates a missing key file from the mirror (backups call this
     * first so the archive always carries the key file).
     *
     * @throws EncryptionException when neither the file nor the mirror exists
     */
    public function ensureHeaderOnDisk(Vault $vault): void
    {
        $path = $this->headerPath($vault->path);

        if ($this->files->exists($path)) {
            return;
        }

        $row = VaultEncryption::query()->where('vault_id', $vault->id)->first();

        if ($row === null) {
            throw EncryptionException::headerMissing();
        }

        $bytes = $this->encryption->encodeHeader($this->headerFromMirror($row));

        if (! $this->files->createFile($path, $bytes)) {
            throw EncryptionException::keyFileWriteFailed();
        }

        $this->refreshMirrorHash($row, $this->hasher->hashString($bytes));
    }

    /**
     * Keeps the mirror and the disk copy in step after the password has
     * been verified. Never fails the unlock.
     */
    private function syncAfterUnlock(Vault $vault, EncryptionHeader $header, string $source, ?string $diskHash): void
    {
        try {
            if ($source === 'disk') {
                $mirror = VaultEncryption::query()->where('vault_id', $vault->id)->first();

                if ($mirror === null || $mirror->header_hash !== $diskHash) {
                    $this->syncMirror($vault, $header, (string) $diskHash);
                }

                return;
            }

            $bytes = $this->encryption->encodeHeader($header);

            if ($this->files->createFile($this->headerPath($vault->path), $bytes)) {
                $mirror = VaultEncryption::query()->where('vault_id', $vault->id)->first();

                if ($mirror !== null) {
                    $this->refreshMirrorHash($mirror, $this->hasher->hashString($bytes));
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function syncMirror(Vault $vault, EncryptionHeader $header, string $headerHash): void
    {
        $this->headers->syncMirror($vault, $header, $headerHash);
    }

    private function refreshMirrorHash(VaultEncryption $mirror, string $hash): void
    {
        if ($mirror->header_hash !== $hash) {
            $mirror->forceFill(['header_hash' => $hash])->save();
        }
    }

    private function headerFromMirror(VaultEncryption $row): EncryptionHeader
    {
        $createdAt = CarbonImmutable::parse((string) $row->getRawOriginal('created_at'), 'UTC')->format('Y-m-d\TH:i:s\Z');

        return $this->encryption->headerFromRegistry([
            ...$row->makeVisible(['salt', 'nonce', 'encrypted_key'])->only([
                'key_id', 'key_version', 'algorithm', 'kdf_algorithm', 'kdf_opslimit', 'kdf_memlimit',
                'salt', 'nonce', 'encrypted_key', 'format_version',
            ]),
            'created_at' => $createdAt,
        ]);
    }

    private function readHeaderBytes(string $path): string
    {
        return $this->headers->readHeaderBytes($path);
    }

    private function headerPath(string $folder): string
    {
        return $this->headers->headerPath($folder);
    }
}
