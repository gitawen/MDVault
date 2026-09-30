<?php

namespace App\Enums;

/**
 * Whether a backup contains every active vault or just one
 * (`BackupService::create`, ADR `backup-archive-format`).
 */
enum BackupScope: string
{
    case All = 'all';
    case Vault = 'vault';
}
