<?php

namespace App\Support;

/**
 * The outcome of a committed vault conversion. `token` is the unlock token
 * when the vault was just encrypted (it is unlocked), null after a
 * decryption. `warning` is set when the replaced original could not be
 * deleted yet (a later recovery retries).
 */
final readonly class ConversionResult
{
    public function __construct(
        public ?string $token,
        public int $notes,
        public ?string $warning = null,
    ) {}
}
