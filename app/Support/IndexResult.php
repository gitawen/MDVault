<?php

namespace App\Support;

/**
 * Immutable counts (and, from Phase 5, changes) from a
 * `VaultIndexService::reconcile()`/`reindex()` run.
 */
final readonly class IndexResult
{
    /**
     * @param  list<array{type: 'created'|'modified'|'moved'|'deleted', uuid: string, path: string, from: ?string, content_changed: bool}>  $changes
     * @param  list<string>  $orphanTempFiles
     */
    public function __construct(
        public int $added,
        public int $updated,
        public int $moved,
        public int $removed,
        public int $unchanged,
        public int $skipped,
        public int $touched = 0,
        public array $changes = [],
        public bool $stale = false,
        public array $orphanTempFiles = [],
        public ?string $treeSignature = null,
    ) {}

    public function hasChanges(): bool
    {
        return $this->added > 0 || $this->updated > 0 || $this->moved > 0 || $this->removed > 0;
    }

    public function summary(): string
    {
        if ($this->stale) {
            return 'The vault changed while it was being indexed. Try again.';
        }

        $summary = $this->hasChanges()
            ? "Index updated: {$this->added} added, {$this->updated} updated, {$this->moved} moved or renamed, {$this->removed} removed."
            : 'The index is already up to date.';

        if ($this->skipped > 0) {
            $summary .= " {$this->skipped} item(s) couldn't be read and were left as they were.";
        }

        return $summary;
    }
}
