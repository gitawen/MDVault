<?php

namespace App\Support;

use App\Models\Vault;

/**
 * Result of batch vault registration.
 */
final readonly class VaultBatchResult
{
    /**
     * @param  list<Vault>  $added
     * @param  list<array{path: string, reason: string}>  $skipped
     */
    public function __construct(
        public array $added = [],
        public array $skipped = [],
        public int $notesIndexed = 0,
    ) {}

    public function addedCount(): int
    {
        return count($this->added);
    }

    public function skippedCount(): int
    {
        return count($this->skipped);
    }

    public function isFullSuccess(): bool
    {
        return $this->addedCount() > 0 && $this->skippedCount() === 0;
    }

    public function isPartialSuccess(): bool
    {
        return $this->addedCount() > 0 && $this->skippedCount() > 0;
    }

    public function isFailure(): bool
    {
        return $this->addedCount() === 0;
    }

    public function summary(): string
    {
        $addedCount = $this->addedCount();
        $vaultLabel = $addedCount === 1 ? '1 vault added' : "{$addedCount} vaults added";
        $summary = "{$vaultLabel}. {$this->notesIndexed} note(s) indexed.";

        if ($this->skippedCount() > 0) {
            $skippedDetails = array_map(function (array $item): string {
                $trimmed = rtrim(str_replace('\\', '/', $item['path']), '/');
                $position = strrpos($trimmed, '/');
                $name = $position === false ? $trimmed : substr($trimmed, $position + 1);

                return "{$name} ({$item['reason']})";
            }, $this->skipped);

            $summary .= " {$this->skippedCount()} skipped: ".implode(', ', $skippedDetails).'.';
        }

        return $summary;
    }
}
