<?php

namespace App\Http\Requests\Vaults;

use App\Exceptions\InvalidStorageRootException;
use App\Exceptions\VaultOperationException;
use App\Services\StoragePathService;

/**
 * Shared `name` validation rules for every vault Form Request that accepts
 * a name: the shape is checked here, and the portable-folder-name check is
 * delegated to `StoragePathService::assertValidFolderName()` (the single
 * source of truth, per ADR vault-registry-and-consistency).
 */
trait VaultNameRules
{
    /**
     * @return array<int, mixed>
     */
    protected function nameRules(StoragePathService $paths): array
    {
        return [
            'bail', 'required', 'string', 'max:100',
            function (string $attribute, mixed $value, \Closure $fail) use ($paths): void {
                if (! is_string($value)) {
                    return;
                }

                try {
                    $paths->assertValidFolderName($value);
                } catch (InvalidStorageRootException) {
                    $fail(VaultOperationException::invalidName()->getMessage());
                }
            },
        ];
    }
}
