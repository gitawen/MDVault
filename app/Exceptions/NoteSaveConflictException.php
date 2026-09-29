<?php

namespace App\Exceptions;

/**
 * A save refused because the disk no longer matches what the client loaded
 * (`changed`), or the file is gone (`missing`). Maps to HTTP 409 (ADR
 * `note-save-atomic-replace`).
 */
final class NoteSaveConflictException extends \RuntimeException
{
    private function __construct(string $message, private readonly string $reason, private readonly ?string $currentHash)
    {
        parent::__construct($message);
    }

    public static function changed(string $relative, string $currentHash): self
    {
        return new self(
            "\u{201c}{$relative}\u{201d} was changed outside MDVault after you opened it. Your edits haven't been saved yet.",
            'changed',
            $currentHash,
        );
    }

    public static function missing(string $relative): self
    {
        return new self(
            "The file for this note is no longer at {$relative}. It may have been moved, renamed or deleted outside MDVault. Your text is still in the editor.",
            'missing',
            null,
        );
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function currentHash(): ?string
    {
        return $this->currentHash;
    }
}
