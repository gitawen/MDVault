<?php

namespace App\Support;

use Illuminate\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\Lock;

/**
 * The in-progress marker of one vault conversion (see `VaultRecoveryService`).
 * Laravel cache locks carry an owner token, so `release()` only ever frees a
 * lock this handle took, and `isHeld()` tells the conversion whether it still
 * owns it (it does not when the lock expired or was cleared). A handle with
 * no underlying lock (a cache store without locks) is always held.
 */
final class ConversionLock
{
    public function __construct(private readonly ?Lock $lock) {}

    public function isHeld(): bool
    {
        return ! $this->lock instanceof CacheLock || $this->lock->isOwnedByCurrentProcess();
    }

    public function release(): void
    {
        $this->lock?->release();
    }

    public function __invoke(): void
    {
        $this->release();
    }
}
