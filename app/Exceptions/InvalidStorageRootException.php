<?php

namespace App\Exceptions;

/**
 * A user-facing storage root validation failure. Messages must never
 * contain a stack trace or anything beyond the user's own path.
 */
final class InvalidStorageRootException extends \RuntimeException
{
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
}
