<?php

namespace App\Support;

/**
 * Immutable counts from a `VaultIndexService::reindex()` run.
 */
final readonly class IndexResult
{
    public function __construct(
        public int $added,
        public int $updated,
        public int $moved,
        public int $removed,
        public int $unchanged,
        public int $skipped,
    ) {}

    public function hasChanges(): bool
    {
        return $this->added > 0 || $this->updated > 0 || $this->moved > 0 || $this->removed > 0;
    }

    public function summary(): string
    {
        $summary = $this->hasChanges()
            ? "Index updated: {$this->added} added, {$this->updated} updated, {$this->moved} moved or renamed, {$this->removed} removed."
            : 'The index is already up to date.';

        if ($this->skipped > 0) {
            $summary .= " {$this->skipped} item(s) couldn't be read and were left as they were.";
        }

        return $summary;
    }
}
