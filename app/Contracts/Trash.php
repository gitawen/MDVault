<?php

namespace App\Contracts;

interface Trash
{
    /** Whether moving to the OS Recycle Bin / Trash is possible in this runtime. */
    public function isAvailable(): bool;

    /** Ask the OS to move $path to its Recycle Bin / Trash. May fail silently; callers must verify. */
    public function moveToTrash(string $path): void;
}
