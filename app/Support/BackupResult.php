<?php

namespace App\Support;

/**
 * The outcome of `BackupService::create()`.
 */
final readonly class BackupResult
{
    /**
     * @param  list<string>  $skippedVaults  names of vaults skipped because their folder was missing (Backup All only)
     */
    public function __construct(
        public string $path,
        public string $filename,
        public int $size,
        public string $hash,
        public int $vaultCount,
        public int $noteCount,
        public int $fileCount,
        public array $skippedVaults,
        public bool $recorded,
    ) {}
}
