<?php

namespace App\Support;

/**
 * The outcome of `BackupService::inspect()` (ADR `backup-restore-semantics`,
 * §28 "read manifest → validate"). Also the first stage of `restore()`.
 */
final readonly class BackupInspection
{
    /**
     * @param  list<string>  $problems
     * @param  ?array{created_at: string, app_version: string, format_version: int, scope: string, vault_count: int, note_count: int, file_count: int, total_bytes: int, archive_size: int}  $backup
     * @param  list<array{uuid: string, name: string, description: ?string, is_encrypted: bool, note_count: int, file_count: int, total_bytes: int, state: string, restore_name: ?string, copy_name: ?string, default_action: string}>  $vaults
     */
    public function __construct(
        public bool $valid,
        public array $problems,
        public ?array $backup,
        public array $vaults,
    ) {}

    /**
     * @return array{valid: bool, problems: list<string>, backup: array<string, mixed>|null, vaults: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'problems' => $this->problems,
            'backup' => $this->backup,
            'vaults' => $this->vaults,
        ];
    }
}
