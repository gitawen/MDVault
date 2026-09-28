<?php

namespace App\Http\Requests\Notes;

use App\Exceptions\NoteOperationException;
use App\Services\NoteService;

/**
 * Shared `name` validation rules for every note/folder Form Request: the
 * shape is checked here, and the portable-name check is delegated to
 * `NoteService` (the single source of truth, per ADR note-file-operations).
 */
trait NoteNameRules
{
    /**
     * @return array<int, mixed>
     */
    protected function noteNameRules(NoteService $notes): array
    {
        return [
            'bail', 'required', 'string', 'max:110',
            function (string $attribute, mixed $value, \Closure $fail) use ($notes): void {
                if (! is_string($value)) {
                    return;
                }

                try {
                    $notes->assertValidNoteName($value);
                } catch (NoteOperationException $e) {
                    $fail($e->getMessage());
                }
            },
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function folderNameRules(NoteService $notes): array
    {
        return [
            'bail', 'required', 'string', 'max:100',
            function (string $attribute, mixed $value, \Closure $fail) use ($notes): void {
                if (! is_string($value)) {
                    return;
                }

                try {
                    $notes->assertValidFolderName($value);
                } catch (NoteOperationException $e) {
                    $fail($e->getMessage());
                }
            },
        ];
    }
}
