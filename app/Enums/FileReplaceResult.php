<?php

namespace App\Enums;

/**
 * The outcome of `FileStorageService::replaceFile()` (ADR
 * `note-save-atomic-replace`).
 */
enum FileReplaceResult: string
{
    case Replaced = 'replaced';
    case TargetInvalid = 'target_invalid';
    case WriteFailed = 'write_failed';
    case GuardFailed = 'guard_failed';
    case ReplaceFailed = 'replace_failed';
}
