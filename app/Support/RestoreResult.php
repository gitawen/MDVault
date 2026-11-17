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
     * @param  list<string>  $restoredSettingGroups
     */
    public function __construct(
        public array $restored,
        public array $skipped,
        public array $warnings,
        public bool $settingsRestored = false,
        public array $restoredSettingGroups = [],
    ) {}

    public function summary(): string
    {
        $count = count($this->restored);
        $notes = array_sum(array_column($this->restored, 'notes'));

        if ($count > 0 && $this->settingsRestored) {
            $summary = "Restored {$count} vault(s) ({$notes} note(s)) and settings.";
        } elseif ($count > 0) {
            $summary = "Restored {$count} vault(s) ({$notes} note(s)).";
        } elseif ($this->settingsRestored) {
            $summary = 'Restored settings.';
        } else {
            $summary = 'Nothing was restored.';
        }

        foreach ($this->warnings as $warning) {
            $summary .= ' '.$warning;
        }

        return $summary;
    }
}
