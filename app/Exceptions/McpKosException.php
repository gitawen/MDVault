<?php

namespace App\Exceptions;

/**
 * Domain and permission failures for MDVault's MCP KOS operations.
 */
final class McpKosException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        string $message,
        private readonly ?string $currentHash = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function currentHash(): ?string
    {
        return $this->currentHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public static function encryptedVaultForbidden(string $vaultName): self
    {
        return new self(
            "Vault \u{201c}{$vaultName}\u{201d} is encrypted and is strictly excluded from MCP access.",
            context: ['vault' => $vaultName, 'reason' => 'encrypted_vault'],
        );
    }

    public static function vaultNotFound(string $identifier): self
    {
        return new self(
            "No vault matching \u{201c}{$identifier}\u{201d} could be found.",
            context: ['vault' => $identifier, 'reason' => 'vault_not_found'],
        );
    }

    public static function vaultMissing(string $vaultName, string $path): self
    {
        return new self(
            "Vault \u{201c}{$vaultName}\u{201d} is currently missing on disk at {$path}.",
            context: ['vault' => $vaultName, 'path' => $path, 'reason' => 'vault_missing'],
        );
    }

    public static function permissionDenied(string $action, string $vaultName): self
    {
        return new self(
            "Action \u{201c}{$action}\u{201d} is only permitted in the dedicated AI vault; vault \u{201c}{$vaultName}\u{201d} is a personal vault restricted to reading and safe note writes.",
            context: ['action' => $action, 'vault' => $vaultName, 'reason' => 'permission_denied'],
        );
    }

    public static function aiVaultUnavailable(string $reason): self
    {
        return new self(
            "The dedicated AI workspace vault is unavailable: {$reason}",
            context: ['reason' => 'ai_vault_unavailable'],
        );
    }

    public static function invalidPath(string $field): self
    {
        return new self(
            "Invalid relative path provided for \u{201c}{$field}\u{201d}. Paths must be vault-relative, forward-slash separated, and free of traversal or hidden segments.",
            context: ['field' => $field, 'reason' => 'invalid_path'],
        );
    }

    public static function noteNotFound(string $identifier, string $vaultName): self
    {
        return new self(
            "Note \u{201c}{$identifier}\u{201d} was not found in vault \u{201c}{$vaultName}\u{201d}.",
            context: ['identifier' => $identifier, 'vault' => $vaultName, 'reason' => 'note_not_found'],
        );
    }

    public static function noteUnreadable(string $relativePath, string $reason): self
    {
        return new self(
            "Note \u{201c}{$relativePath}\u{201d} cannot be read or edited ({$reason}).",
            context: ['relative_path' => $relativePath, 'reason' => $reason],
        );
    }

    public static function conflict(string $message, ?string $currentHash, string $reason): self
    {
        $suffix = $currentHash !== null ? " Current file_hash: {$currentHash}" : '';

        return new self(
            $message.$suffix,
            currentHash: $currentHash,
            context: [
                'reason' => $reason,
                'current_hash' => $currentHash,
            ],
        );
    }

    public static function hashRequiredForExistingSkill(string $relativePath, string $currentHash): self
    {
        return new self(
            "Skill \u{201c}{$relativePath}\u{201d} already exists. Provide file_hash ({$currentHash}) to overwrite it safely.",
            currentHash: $currentHash,
            context: [
                'relative_path' => $relativePath,
                'current_hash' => $currentHash,
                'reason' => 'file_hash_required',
            ],
        );
    }

    public static function folderNotFound(string $folder, string $vaultName): self
    {
        return new self(
            "Folder \u{201c}{$folder}\u{201d} was not found in vault \u{201c}{$vaultName}\u{201d}.",
            context: ['folder' => $folder, 'vault' => $vaultName, 'reason' => 'folder_not_found'],
        );
    }

    public static function invalidInput(string $message): self
    {
        return new self($message, context: ['reason' => 'invalid_input']);
    }

    public static function operationFailed(string $message): self
    {
        return new self($message, context: ['reason' => 'operation_failed']);
    }
}
