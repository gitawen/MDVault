<?php

namespace App\Support;

/**
 * Holds a vault's 32-byte data key in memory (ADR
 * `vault-encryption-cryptography`). The key is never exposed through debug
 * output, serialization, cloning or JSON, and is wiped when the object is
 * destroyed (best effort: PHP may have copied the string elsewhere).
 *
 * Only `EncryptionService` may read the material.
 */
final class VaultKey
{
    private ?string $material;

    public function __construct(
        #[\SensitiveParameter] string $material,
        public readonly string $keyId,
    ) {
        $this->material = $material;
    }

    /**
     * @internal Used only by `App\Services\EncryptionService`.
     */
    public function material(): string
    {
        return $this->material ?? throw new \LogicException('This vault key has been wiped.');
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['keyId' => $this->keyId, 'key' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A vault key cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('A vault key cannot be unserialized.');
    }

    public function __clone(): void
    {
        throw new \LogicException('A vault key cannot be cloned.');
    }

    public function __destruct()
    {
        if ($this->material !== null) {
            sodium_memzero($this->material);
        }
    }
}
