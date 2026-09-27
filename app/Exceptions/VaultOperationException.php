<?php

namespace App\Exceptions;

/**
 * A user-facing vault operation failure. Messages must never contain a
 * stack trace, only the user's own vault names and paths.
 */
final class VaultOperationException extends \RuntimeException
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
            'Enter a valid vault name. It is also used as the folder name, so it can\'t contain < > : " / \\ | ? *, start or end with a space, end with a dot, or be a reserved system name (e.g. CON, NUL). 100 characters or fewer.',
            'name',
        );
    }

    public static function duplicateName(string $name): self
    {
        return new self("A vault named \u{201c}{$name}\u{201d} already exists.", 'name');
    }

    public static function storageUnavailable(string $reason): self
    {
        return new self("Your storage location can't be used: {$reason} Change it in Settings \u{2192} Storage.", 'name');
    }

    public static function targetIsFile(string $path): self
    {
        return new self("A file already exists at {$path}.", 'name');
    }

    public static function targetNotEmpty(string $path): self
    {
        return new self("A folder that already contains files exists at {$path}. Choose another name, or add it with \u{201c}Add existing folder\u{201d}.", 'name');
    }

    public static function overlapsVault(string $vaultName, string $field): self
    {
        return new self("This folder overlaps the vault \u{201c}{$vaultName}\u{201d}. Vault folders can't be inside each other.", $field);
    }

    public static function cannotCreate(string $path): self
    {
        return new self("The vault folder could not be created at {$path}.", 'name');
    }

    public static function notWritable(string $path, string $field): self
    {
        return new self("MDVault cannot write to {$path}.", $field);
    }

    public static function folderMissing(string $path, string $field): self
    {
        return new self("The vault folder can't be found at {$path}. Reconnect the drive or remove the vault.", $field);
    }

    public static function notAbsolute(): self
    {
        return new self('Enter a full (absolute) folder path.', 'path');
    }

    public static function pathNotDirectory(string $path): self
    {
        return new self("No folder exists at {$path}.", 'path');
    }

    public static function unsafeFolder(string $field): self
    {
        return new self("For safety, this folder can't be used for a vault (it is a drive root, your Documents folder, or contains your storage location).", $field);
    }

    public static function trashUnavailable(): self
    {
        return new self('Moving folders to the Recycle Bin / Trash is only available in the desktop app.', 'move_to_trash');
    }

    public static function trashFailed(string $path): self
    {
        return new self("The folder at {$path} could not be moved to the Recycle Bin / Trash. Close any programs using it and try again. The vault was not removed.", 'move_to_trash');
    }

    public static function renameTargetExists(string $path): self
    {
        $basename = basename($path);

        return new self("Something named \u{201c}{$basename}\u{201d} already exists at {$path}. Choose another name.", 'name');
    }

    public static function renameFailed(string $from, string $to): self
    {
        return new self("The vault folder couldn't be renamed to {$to}. Close any programs using it (File Explorer / Finder, VS Code, a terminal or sync tools such as OneDrive) and try again. Nothing was changed.", 'name');
    }

    public static function renameRollbackFailed(string $from, string $to): self
    {
        return new self("The folder was renamed to {$to}, but MDVault couldn't save the change or rename the folder back. Rename the folder back to {$from} yourself; the vault will then be available again.", 'name');
    }

    public static function renameInterrupted(string $from, string $to): self
    {
        $basename = basename($to);

        return new self("The folder couldn't be renamed and is no longer at {$from}. Look next to it for a folder named \u{201c}{$basename}\u{201d} or starting with \u{201c}.mdvault-rename-\u{201d}, and rename it back to {$from}.", 'name');
    }
}
