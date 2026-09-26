<?php

namespace App\Contracts;

interface UserDirectories
{
    /**
     * Absolute path of the current user's Documents directory, or null if
     * it cannot be determined. Never creates anything.
     */
    public function documentsPath(): ?string;
}
