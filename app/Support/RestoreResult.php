<?php

namespace App\Support;

/**
 * The outcome of `BackupService::restore()`.
 */
final readonly class RestoreResult
{
    /**
     * @param  list<array{uuid: string, name: string, path: string, notes: int, copy: bool}>  $restored
     * @param  list<string>  $skipped  names of vaults the user chose to skip
     * @param  list<string>  $warnings  non-fatal issues found while verifying the restore
     */
    public function __construct(
        public array $restored,
        public array $skipped,
        public array $warnings,
    ) {}

    public function summary(): string
    {
        $count = count($this->restored);
        $notes = array_sum(array_column($this->restored, 'notes'));

        $summary = $count > 0
            ? "Restored {$count} vault(s) ({$notes} note(s))."
            : 'Nothing was restored.';

        foreach ($this->warnings as $warning) {
            $summary .= ' '.$warning;
        }

        return $summary;
    }
}
