<?php

namespace App\Exceptions;

/**
 * A user-facing note, folder or index operation failure. Messages must
 * never contain a stack trace, only the user's own names and paths.
 */
final class NoteOperationException extends \RuntimeException
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

    public static function invalidName(): self
    {
        return new self(
            'Enter a valid name. It is used as the file or folder name, so it can\'t contain < > : " / \\ | ? *, start with a dot or a space, end with a space or a dot, or be a reserved system name (e.g. CON, NUL). 100 characters or fewer. For notes, '.
            "\u{201c}.md\u{201d} is added for you.",
            'name',
        );
    }

    public static function invalidFolder(string $field): self
    {
        return new self('Choose a folder inside this vault.', $field);
    }

    public static function folderNotFound(string $folder, string $field): self
    {
        $name = $folder === '' ? "the vault's top level" : $folder;

        return new self("The folder \u{201c}{$name}\u{201d} can't be found in this vault. Re-index the vault to refresh the list.", $field);
    }

    public static function targetExists(string $relative, string $field): self
    {
        return new self("\u{201c}{$relative}\u{201d} already exists in this vault. Choose another name.", $field);
    }

    public static function vaultUnavailable(string $path): self
    {
        return new self("The vault folder can't be found or read at {$path}. Reconnect the drive, then try again.", 'vault');
    }

    public static function noteFileMissing(string $relative, string $field): self
    {
        return new self("The file for this note is missing ({$relative}). Re-index the vault to update the list.", $field);
    }

    public static function createFailed(string $relative, string $field): self
    {
        return new self("\u{201c}{$relative}\u{201d} could not be created. Check that MDVault can write to the vault folder.", $field);
    }

    public static function moveFailed(string $field): self
    {
        return new self("The note couldn't be renamed or moved. Close any programs using it and try again. Nothing was changed.", $field);
    }

    public static function moveInterrupted(string $from, string $to, string $field): self
    {
        return new self("The note couldn't be renamed and is no longer at {$from}. Look for {$to} or a file starting with \u{201c}.mdvault-rename-\u{201d} next to it, rename it back, then re-index the vault.", $field);
    }

    public static function moveRollbackFailed(string $to, string $field): self
    {
        return new self("The file was moved to {$to}, but MDVault couldn't save the change or move it back. Re-index the vault to pick up its new location.", $field);
    }

    public static function trashUnavailable(): self
    {
        return new self('Deleting notes moves them to the Recycle Bin / Trash, which is only available in the desktop app.', 'note');
    }

    public static function trashFailed(string $relative): self
    {
        return new self("\u{201c}{$relative}\u{201d} could not be moved to the Recycle Bin / Trash. Close any programs using it and try again. The note was not deleted.", 'note');
    }

    public static function folderNotEmpty(string $relative): self
    {
        return new self("The folder \u{201c}{$relative}\u{201d} isn't empty (it may contain hidden or non-Markdown files). Only empty folders can be deleted.", 'path');
    }

    public static function cannotDeleteRoot(): self
    {
        return new self("The vault's top-level folder can't be deleted here. Remove the vault from the Vaults page instead.", 'path');
    }

    public static function folderDeleteFailed(string $relative): self
    {
        return new self("The folder \u{201c}{$relative}\u{201d} could not be deleted. Close any programs using it and try again.", 'path');
    }
}
