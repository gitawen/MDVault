<?php

namespace App\Exceptions;

/**
 * A user-facing storage root validation failure. Messages must never
 * contain a stack trace or anything beyond the user's own path.
 */
final class InvalidStorageRootException extends \RuntimeException
{
    private function __construct(string $message, private readonly string $field = 'location')
    {
        parent::__construct($message);
    }

    /**
     * The request field this failure should be reported against
     * ('location' or 'folder_name').
     */
    public function field(): string
    {
        return $this->field;
    }

    public static function notAbsolute(): self
    {
        return new self('Enter a full (absolute) folder path.');
    }

    public static function isFile(): self
    {
        return new self('That path points to a file, not a folder.');
    }

    public static function cannotCreate(): self
    {
        return new self('The folder could not be created. Check the path and your permissions.');
    }

    public static function notWritable(): self
    {
        return new self('MDVault cannot write to that folder.');
    }

    public static function invalid(): self
    {
        return new self('The folder path is invalid.');
    }

    public static function invalidFolderName(): self
    {
        return new self(
            'Enter a valid folder name: no path separators, no reserved characters (< > : " / \\ | ? *), no leading or trailing spaces, no trailing dot, not "." or "..", not a reserved system name (e.g. CON, NUL, COM1), and 100 characters or fewer.',
            'folder_name',
        );
    }
}
