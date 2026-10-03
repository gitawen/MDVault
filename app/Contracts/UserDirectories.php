<?php

namespace App\Contracts;

interface UserDirectories
{
    /**
     * Absolute path of the current user's Documents directory, or null if
     * it cannot be determined. Never creates anything.
     */
    public function documentsPath(): ?string;

    /**
     * Absolute path of the current user's home directory, or null if
     * it cannot be determined. Never creates anything.
     */
    public function homeDirectory(): ?string;
}
