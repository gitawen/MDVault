<?php

namespace App\Support;

/**
 * The outcome of `VaultService::resetRegistry()` (ADR `database-reset-semantics`).
 */
final readonly class RegistryResetResult
{
    public function __construct(
        public int $vaults,
        public int $missingVaults,
        public int $notes,
    ) {}

    public function summary(): string
    {
        return "Removed {$this->vaults} vault(s) and {$this->notes} note record(s) from MDVault. No files were deleted.";
    }
}
