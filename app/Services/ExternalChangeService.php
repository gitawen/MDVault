<?php

namespace App\Services;

use App\Enums\IndexMode;
use App\Enums\SettingKey;
use App\Enums\VaultStatus;
use App\Exceptions\NoteOperationException;
use App\Models\Vault;

/**
 * Orchestrates a single external-change check (ADR
 * `external-change-detection`): a Quick reconcile of the current vault,
 * reported back to the renderer as a small status the client can act on.
 * Holds no filesystem or reconcile logic of its own (arch test).
 */
final class ExternalChangeService
{
    public function __construct(
        private readonly VaultService $vaults,
        private readonly VaultIndexService $index,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array{status: 'ok'|'busy'|'unavailable'|'disabled'|'inactive', changed: bool, tree_signature: ?string, open_note: ?array{uuid: string, exists: bool, relative_path: ?string, file_hash: ?string}, orphan_temp_files: list<string>}
     */
    public function check(Vault $vault, ?string $openNoteUuid): array
    {
        if (! $this->settings->boolean(SettingKey::CheckExternalChanges)) {
            return $this->result('disabled');
        }

        $current = $this->vaults->current();

        if ($current === null || ! $current->is($vault)) {
            return $this->result('inactive');
        }

        $this->vaults->refreshStatus($vault);

        if ($vault->status === VaultStatus::Missing) {
            return $this->result('unavailable');
        }

        $verify = [];

        if ($openNoteUuid !== null) {
            $row = $vault->notes()->where('uuid', $openNoteUuid)->first(['relative_path']);

            if ($row !== null) {
                $verify = [$row->relative_path];
            }
        }

        try {
            $result = $this->index->reconcile($vault, IndexMode::Quick, $verify);
        } catch (NoteOperationException) {
            return $this->result('unavailable');
        }

        if ($result->stale) {
            return $this->result('busy');
        }

        return [
            'status' => 'ok',
            'changed' => $result->hasChanges(),
            'tree_signature' => $result->treeSignature,
            'open_note' => $openNoteUuid !== null ? $this->openNoteState($vault, $openNoteUuid) : null,
            'orphan_temp_files' => $result->orphanTempFiles,
        ];
    }

    /**
     * @return array{uuid: string, exists: bool, relative_path: ?string, file_hash: ?string}
     */
    private function openNoteState(Vault $vault, string $uuid): array
    {
        $note = $vault->notes()->where('uuid', $uuid)->first(['uuid', 'relative_path', 'file_hash']);

        if ($note === null) {
            return ['uuid' => $uuid, 'exists' => false, 'relative_path' => null, 'file_hash' => null];
        }

        return [
            'uuid' => $note->uuid,
            'exists' => true,
            'relative_path' => $note->relative_path,
            'file_hash' => $note->file_hash,
        ];
    }

    /**
     * @param  'busy'|'unavailable'|'disabled'|'inactive'  $status
     * @return array{status: 'ok'|'busy'|'unavailable'|'disabled'|'inactive', changed: bool, tree_signature: ?string, open_note: ?array{uuid: string, exists: bool, relative_path: ?string, file_hash: ?string}, orphan_temp_files: list<string>}
     */
    private function result(string $status): array
    {
        return [
            'status' => $status,
            'changed' => false,
            'tree_signature' => null,
            'open_note' => null,
            'orphan_temp_files' => [],
        ];
    }
}
