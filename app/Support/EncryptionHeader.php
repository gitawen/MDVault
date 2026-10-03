<?php

namespace App\Support;

/**
 * The non-secret contents of a vault's `mdvault-encryption.json` key file
 * (ADR `encrypted-vault-storage-layout`): KDF parameters and the wrapped
 * data key. Binary fields are raw bytes here; they are base64 on disk.
 */
final readonly class EncryptionHeader
{
    public function __construct(
        public int $formatVersion,
        public string $keyId,
        public int $keyVersion,
        public string $cipher,
        public string $kdfAlgorithm,
        public int $opslimit,
        public int $memlimit,
        public string $salt,
        public string $wrapNonce,
        public string $wrappedKey,
        public string $createdAt,
    ) {}

    /**
     * The `vault_encryption` mirror columns (everything except `vault_id`).
     *
     * @return array<string, int|string>
     */
    public function toRegistryAttributes(string $headerHash): array
    {
        return [
            'key_id' => $this->keyId,
            'key_version' => $this->keyVersion,
            'algorithm' => $this->cipher,
            'kdf_algorithm' => $this->kdfAlgorithm,
            'kdf_opslimit' => $this->opslimit,
            'kdf_memlimit' => $this->memlimit,
            'salt' => base64_encode($this->salt),
            'nonce' => base64_encode($this->wrapNonce),
            'encrypted_key' => base64_encode($this->wrappedKey),
            'format_version' => $this->formatVersion,
            'header_hash' => $headerHash,
        ];
    }
}
