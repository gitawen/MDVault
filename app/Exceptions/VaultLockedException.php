<?php

namespace App\Exceptions;

/**
 * An operation needed a vault's key and the vault is locked. The message is
 * fixed and generic: it never names the vault or its contents.
 */
final class VaultLockedException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This vault is locked. Unlock it to continue.');
    }
}
