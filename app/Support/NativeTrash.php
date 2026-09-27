<?php

namespace App\Support;

use App\Contracts\Trash;
use Illuminate\Contracts\Config\Repository;
use Native\Desktop\Facades\Shell;

/**
 * Moves a folder to the OS Recycle Bin / Trash via the NativePHP Shell
 * facade. Only available in the desktop runtime. `Shell::trashFile()`
 * returns void and swallows a failing HTTP response, so callers must check
 * the filesystem afterwards to know whether it actually worked.
 */
final class NativeTrash implements Trash
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function isAvailable(): bool
    {
        return (bool) $this->config->get('nativephp-internal.running');
    }

    public function moveToTrash(string $path): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        Shell::trashFile($path);
    }
}
