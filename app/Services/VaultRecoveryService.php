<?php

namespace App\Services;

use App\Models\Vault;
use App\Support\ConversionLock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Crash recovery for vault conversions (ADR `vault-encryption-conversion`).
 *
 * A conversion parks the replaced folder next to the vault as
 * `.mdvault-original-<uuid>` and swaps the converted staging folder in. At
 * any instant the vault folder `V` is either the full original or the full
 * converted vault, and the state left on disk by a crash has exactly one
 * recovery rule (the table in the ADR). The DB value `vaults.is_encrypted`
 * is the committed truth.
 *
 * This service depends only on `FileStorageService`, the DB and the cache
 * (for the in-flight conversion lock), so `VaultService` can call it without
 * a dependency cycle. Every action uses `renameDirectory` (never overwrites)
 * and `deleteStagingDirectory` (the only recursive delete).
 */
final class VaultRecoveryService
{
    public const LOCK_PREFIX = 'mdvault.conversion.';

    public const LOCK_SECONDS = 1800;

    public function __construct(
        private readonly FileStorageService $files,
        private readonly CacheFactory $cache,
    ) {}

    /**
     * Takes the in-progress marker for this vault, or returns null when
     * another conversion or recovery of this vault is in flight. The handle
     * carries an owner token: only the owner releases it, and it can be
     * asked whether it is still held. Without a lock-capable cache store the
     * marker is a no-op that always reports itself held.
     */
    public function lock(Vault $vault): ?ConversionLock
    {
        $lock = $this->rawLock($vault);

        if ($lock === null) {
            return new ConversionLock(null);
        }

        return $lock->get() ? new ConversionLock($lock) : null;
    }

    private function rawLock(Vault $vault): ?Lock
    {
        $repository = $this->cache->store();
        $store = $repository instanceof Repository ? $repository->getStore() : null;

        if (! $store instanceof LockProvider) {
            return null;
        }

        return $store->lock(self::LOCK_PREFIX.$vault->uuid, self::LOCK_SECONDS);
    }

    /**
     * Repairs whatever a crashed or half-finished conversion left behind.
     * Idempotent and safe to call at any time: it does nothing when no
     * conversion folder exists or a conversion is in flight.
     *
     * @return string|null the action taken (`restored-original`, `rolled-back`, `removed-original`, `removed-staging`), or null
     */
    public function recover(Vault $vault): ?string
    {
        $original = $this->originalPath($vault);
        $hasOriginal = $this->isRealDirectory($original);
        $hasStaging = $this->isRealDirectory($this->stagingPath($vault, true)) || $this->isRealDirectory($this->stagingPath($vault, false));

        if (! $hasOriginal && ! $hasStaging) {
            return null;
        }

        $lock = $this->lock($vault);

        if ($lock === null) {
            return null;
        }

        try {
            return $this->repair($vault);
        } finally {
            $lock->release();
        }
    }

    /**
     * Recovers every registered vault (app start). The app is a single
     * instance, so at boot no conversion can be in flight: a conversion lock
     * found now was left by a crash (a persisted cache store keeps it) and
     * is released first. A failure for one vault is reported and never stops
     * the others.
     *
     * @return int how many vaults needed repair
     */
    public function recoverAll(): int
    {
        $repaired = 0;

        foreach (Vault::query()->get() as $vault) {
            try {
                $this->rawLock($vault)?->forceRelease();

                if ($this->recover($vault) !== null) {
                    $repaired++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $repaired;
    }

    public function originalPath(Vault $vault): string
    {
        return $this->files->siblingPath($vault->path, FileStorageService::CONVERSION_ORIGINAL_PREFIX.$vault->uuid);
    }

    public function stagingPath(Vault $vault, bool $encrypting): string
    {
        $prefix = $encrypting ? FileStorageService::ENCRYPT_STAGING_PREFIX : FileStorageService::DECRYPT_STAGING_PREFIX;

        return $this->files->siblingPath($vault->path, $prefix.$vault->uuid);
    }

    private function repair(Vault $vault): ?string
    {
        $folder = $vault->path;
        $original = $this->originalPath($vault);
        $encryptStaging = $this->stagingPath($vault, true);
        $decryptStaging = $this->stagingPath($vault, false);

        $hasOriginal = $this->isRealDirectory($original);
        $hasFolder = $this->files->isDirectory($folder);
        $dbEncrypted = (bool) Vault::query()->whereKey($vault->id)->value('is_encrypted');

        // V missing, O exists: a crash between the two renames (never committed).
        if (! $hasFolder && $hasOriginal) {
            if (! $this->files->renameDirectory($original, $folder)) {
                return null;
            }

            $this->discard($encryptStaging);
            $this->discard($decryptStaging);

            return 'restored-original';
        }

        if ($hasFolder && $hasOriginal) {
            $folderEncrypted = $this->files->exists($this->files->joinRelative($folder, EncryptionHeaderService::HEADER_FILENAME));

            if ($folderEncrypted !== $dbEncrypted) {
                // Both renames happened but the DB never committed: undo them.
                $staging = $dbEncrypted ? $decryptStaging : $encryptStaging;

                if ($this->files->exists($staging) && ! $this->discard($staging)) {
                    return null;
                }

                if (! $this->files->renameDirectory($folder, $staging)) {
                    return null;
                }

                if (! $this->files->renameDirectory($original, $folder)) {
                    $this->files->renameDirectory($staging, $folder);

                    return null;
                }

                $this->discard($staging);

                return 'rolled-back';
            }

            // Committed, but the replaced original was not deleted yet.
            $this->discard($encryptStaging);
            $this->discard($decryptStaging);

            return $this->discard($original) ? 'removed-original' : null;
        }

        // V exists, no original: a crash while staging.
        if ($hasFolder) {
            $removed = false;

            foreach ([$encryptStaging, $decryptStaging] as $staging) {
                if ($this->isRealDirectory($staging)) {
                    $removed = $this->discard($staging) || $removed;
                }
            }

            return $removed ? 'removed-staging' : null;
        }

        return null;
    }

    private function discard(string $path): bool
    {
        return ! $this->isRealDirectory($path) || $this->files->deleteStagingDirectory($path);
    }

    private function isRealDirectory(string $path): bool
    {
        return $this->files->isDirectory($path) && ! $this->files->isSymlink($path);
    }
}
