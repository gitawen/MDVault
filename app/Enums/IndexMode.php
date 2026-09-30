<?php

namespace App\Enums;

/**
 * `VaultIndexService::reconcile()` mode (ADR `external-change-reconciliation`).
 */
enum IndexMode: string
{
    /** Automatic checks: skips hashing a file whose size/mtime are trusted and unchanged. */
    case Quick = 'quick';

    /** Manual Re-index: hashes every file. */
    case Full = 'full';
}
