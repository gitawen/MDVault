<?php

namespace App\Exceptions;

/**
 * A user-facing media upload or storage failure. Messages must never contain
 * stack traces or absolute OS paths.
 */
final class MediaOperationException extends \RuntimeException
{
    private function __construct(string $message, private readonly string $field)
    {
        parent::__construct($message);
    }

    /**
     * The request field this failure should be reported against.
     */
    public function field(): string
    {
        return $this->field;
    }

    public static function vaultUnavailable(): self
    {
        return new self('This vault folder cannot be found on disk. Reconnect the drive and try again.', 'image');
    }

    public static function encryptedVaultUnsupported(): self
    {
        return new self("Image attachments aren't supported in encrypted vaults yet.", 'image');
    }

    public static function mediaFolderUnavailable(): self
    {
        return new self("MDVault's shared Media folder could not be created or written to. Check your storage location in Settings.", 'image');
    }

    public static function mediaFolderConflict(): self
    {
        return new self("MDVault's shared Media folder overlaps an existing vault. Move or remove the conflicting vault before attaching images.", 'image');
    }

    public static function invalidImage(): self
    {
        return new self('Upload a valid PNG, JPEG, GIF, or WEBP image.', 'image');
    }

    public static function tooLarge(): self
    {
        return new self('Images must be 10 MB or smaller.', 'image');
    }

    public static function writeFailed(): self
    {
        return new self('The image could not be saved to the Media folder. Check disk space and permissions, then try again.', 'image');
    }
}
