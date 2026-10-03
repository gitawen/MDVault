<?php

namespace App\Exceptions;

/**
 * A user-facing encryption failure. Messages are fixed and generic: they
 * never include passwords, keys, decrypted names or any input data, and a
 * `SodiumException` is never chained into one.
 */
final class EncryptionException extends \RuntimeException
{
    /**
     * @param  list<string>  $problems
     */
    private function __construct(
        string $message,
        private readonly string $field,
        private readonly array $problems = [],
    ) {
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
     * Preflight problems (conversion refusals).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    public static function wrongPassword(): self
    {
        return new self("That password didn't unlock this vault.", 'password');
    }

    public static function damagedHeader(): self
    {
        return new self("This vault's key file is damaged or isn't an MDVault key file.", 'vault');
    }

    public static function headerMissing(): self
    {
        return new self("This vault's key file is missing and MDVault has no copy of it.", 'vault');
    }

    public static function undecryptable(): self
    {
        return new self("This item couldn't be decrypted. It may be damaged, or it may belong to a different vault.", 'vault');
    }

    public static function unsupportedNote(): self
    {
        return new self("This note's name or size isn't supported in an encrypted vault.", 'name');
    }

    public static function cryptoFailed(): self
    {
        return new self("Encryption isn't available right now. Make sure this computer has enough free memory and try again.", 'vault');
    }

    public static function keyFileWriteFailed(): self
    {
        return new self("The vault's key file couldn't be updated. Close any programs using the vault folder and try again. Nothing was changed.", 'vault');
    }

    public static function notEncrypted(): self
    {
        return new self("This vault isn't encrypted.", 'vault');
    }

    public static function alreadyEncrypted(): self
    {
        return new self('This vault is already encrypted.', 'vault');
    }

    /**
     * @param  list<string>  $problems
     */
    public static function unsupportedContents(array $problems): self
    {
        return new self("This vault contains files that can't be encrypted yet.", 'vault', $problems);
    }

    /**
     * @param  list<string>  $problems
     */
    public static function foreignFiles(array $problems): self
    {
        return new self("This encrypted vault contains files that aren't MDVault notes, so the encryption can't be removed safely. Move them out of the folder first. Nothing was changed.", 'vault', $problems);
    }

    /**
     * @param  list<string>  $problems
     */
    public static function unreadableNotes(array $problems): self
    {
        return new self("Some notes couldn't be decrypted, so the encryption can't be removed. Nothing was changed.", 'vault', $problems);
    }

    public static function vaultChanged(): self
    {
        return new self('The vault was changed while it was being converted. Nothing was changed. Try again.', 'vault');
    }

    public static function parentNotWritable(): self
    {
        return new self("MDVault can't write next to the vault folder, which it needs to convert the vault. Nothing was changed.", 'vault');
    }

    public static function swapFailed(): self
    {
        return new self('Close any programs using the vault folder and try again. Nothing was changed.', 'vault');
    }

    public static function conversionFailed(): self
    {
        return new self("The vault couldn't be converted. Nothing was changed.", 'vault');
    }

    public static function cleanupIncomplete(string $folder): self
    {
        return new self("The vault was converted, but MDVault couldn't remove a leftover folder: {$folder}. It will be removed automatically next time.", 'vault');
    }
}
