<?php

namespace App\Enums;

/**
 * The per-vault choice offered when restoring a backup (ADR
 * `backup-restore-semantics`).
 */
enum RestoreAction: string
{
    /** Keep the original vault and note UUIDs. Only allowed when the vault UUID isn't registered. */
    case Restore = 'restore';

    /** New UUIDv7s for the vault and every note; name suffixed "(restored)". */
    case Copy = 'copy';

    /** Do nothing with this vault. */
    case Skip = 'skip';
}
