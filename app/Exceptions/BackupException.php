<?php

namespace App\Exceptions;

/**
 * A user-facing backup or restore failure (ADRs `backup-archive-format`,
 * `backup-restore-semantics`). Messages must never contain a stack trace,
 * only the user's own names and paths.
 *
 * `field()` is `destination` for a backup's own creation (destination
 * rules and the vaults/writing failures inside `BackupService::create()`),
 * `path` for archive and restore failures, and `vaults` for a restore
 * selection error (nothing chosen, or an unknown vault key).
 */
final class BackupException extends \RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(string $message, private readonly string $field, private readonly array $problems = [])
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

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    // --- Destination -----------------------------------------------------

    public static function invalidDestination(): self
    {
        return new self('Enter a valid destination for the backup.', 'destination');
    }

    public static function invalidDestinationName(): self
    {
        return new self(
            'Enter a valid file name. It can\'t contain < > : " / \\ | ? *, start or end with a space, start or end with a dot, or be a reserved system name (e.g. CON, NUL).',
            'destination',
        );
    }

    public static function destinationFolderUnavailable(string $dir): self
    {
        return new self("MDVault can't write to {$dir}.", 'destination');
    }

    public static function destinationExists(string $path): self
    {
        return new self("Something already exists at {$path}. MDVault never overwrites files; choose another name.", 'destination');
    }

    public static function destinationInsideVault(string $name): self
    {
        return new self("The backup can't be saved inside the vault \u{201c}{$name}\u{201d}. Choose another location.", 'destination');
    }

    // --- Vaults (during backup creation) ----------------------------------

    public static function nothingToBackUp(): self
    {
        return new self('There is nothing to back up: no vault is available.', 'destination');
    }

    public static function vaultMissing(string $name): self
    {
        return new self("The vault \u{201c}{$name}\u{201d}'s folder can't be found. Reconnect the drive, or remove the vault.", 'destination');
    }

    public static function encryptedNotSupported(string $name): self
    {
        return new self("The vault \u{201c}{$name}\u{201d} is encrypted, which backups don't support yet.", 'destination');
    }

    public static function vaultBusy(string $name): self
    {
        return new self("The vault \u{201c}{$name}\u{201d} changed while MDVault was checking it. Try again.", 'destination');
    }

    public static function vaultChanged(string $name): self
    {
        return new self("The vault \u{201c}{$name}\u{201d} changed while it was being backed up. Try again.", 'destination');
    }

    /**
     * @param  list<string>  $paths
     */
    public static function unreadable(string $vaultName, array $paths): self
    {
        $list = implode(', ', $paths);

        return new self("Some files in \u{201c}{$vaultName}\u{201d} couldn't be read: {$list}. Close any programs using them and try again.", 'destination', $paths);
    }

    public static function unsupportedName(string $vaultName, string $path): self
    {
        return new self("\u{201c}{$path}\u{201d} in \u{201c}{$vaultName}\u{201d} has a name backups can't handle. Rename it and try again.", 'destination');
    }

    // --- Writing -----------------------------------------------------------

    public static function archiveWriteFailed(string $dir): self
    {
        return new self("The backup couldn't be written to {$dir}. Check that MDVault can write there and that there's enough free space.", 'destination');
    }

    public static function changedDuringBackup(): self
    {
        return new self('A file changed while it was being backed up. Try again.', 'destination');
    }

    // --- Archive path --------------------------------------------------------

    public static function archiveNotFound(string $path): self
    {
        return new self("No file was found at {$path}.", 'path');
    }

    public static function notZipFile(string $path): self
    {
        return new self("{$path} doesn't look like a ZIP file (it must end in \u{201c}.zip\u{201d}).", 'path');
    }

    public static function notAnArchive(): self
    {
        return new self("This isn't a valid ZIP file.", 'path');
    }

    // --- Restore -------------------------------------------------------------

    /**
     * @param  list<string>  $problems  all problems found, at most 20
     */
    public static function invalidBackup(array $problems): self
    {
        $first = $problems[0] ?? "This backup can't be restored.";
        $more = count($problems) - 1;
        $message = $more > 0 ? "{$first} (and {$more} more)" : $first;

        return new self($message, 'path', $problems);
    }

    public static function storageUnavailable(string $reason): self
    {
        return new self("Your storage location can't be used: {$reason}", 'path');
    }

    public static function unknownVault(): self
    {
        return new self("One of the chosen vaults isn't in this backup.", 'vaults');
    }

    public static function nothingSelected(): self
    {
        return new self('Choose at least one vault to restore.', 'vaults');
    }

    public static function vaultAlreadyRegistered(string $name): self
    {
        return new self("\u{201c}{$name}\u{201d} is already in MDVault. Choose \u{201c}Restore as a copy\u{201d} or skip it.", 'path');
    }

    public static function noFreeName(string $name): self
    {
        return new self("MDVault couldn't find a free name for \u{201c}{$name}\u{201d}. Rename or remove some vaults and try again.", 'path');
    }

    public static function notEnoughSpace(int $need, int $free): self
    {
        return new self("Not enough free space to restore this backup (needs about {$need} bytes, {$free} available).", 'path');
    }

    public static function extractFailed(string $entry): self
    {
        return new self($entry === '' ? "The backup couldn't be extracted." : "\u{201c}{$entry}\u{201d} couldn't be extracted.", 'path');
    }

    public static function damaged(string $entry): self
    {
        return new self("\u{201c}{$entry}\u{201d} is damaged (its checksum doesn't match). Nothing was restored.", 'path');
    }

    public static function moveFailed(string $target): self
    {
        return new self("The restored folder couldn't be moved into place at {$target}. Close any programs using it and try again. Nothing was changed.", 'path');
    }

    public static function restoreFailed(): self
    {
        return new self('Nothing was restored.', 'path');
    }

    /**
     * @param  list<string>  $folders  restored folders left unregistered on disk
     */
    public static function restoreRollbackFailed(array $folders): self
    {
        $list = implode(', ', $folders);

        return new self("The restore failed, and MDVault couldn't fully undo it. Remove or rename these folders by hand: {$list}.", 'path', $folders);
    }
}
