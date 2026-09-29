<?php

namespace App\Support;

/**
 * The outcome of `NoteService::save()` (ADR `note-save-atomic-replace`).
 */
final readonly class NoteSaveResult
{
    public function __construct(
        public bool $written,
        public string $fileHash,
        public int $fileSize,
        public ?string $updatedAt,
    ) {}

    /**
     * @return array{saved: bool, file_hash: string, file_size: int, updated_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'saved' => $this->written,
            'file_hash' => $this->fileHash,
            'file_size' => $this->fileSize,
            'updated_at' => $this->updatedAt,
        ];
    }
}
