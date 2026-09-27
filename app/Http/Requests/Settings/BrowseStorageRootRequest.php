<?php

namespace App\Http\Requests\Settings;

use App\Exceptions\InvalidStorageRootException;
use App\Services\NativeDialogService;
use App\Services\StoragePathService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BrowseStorageRootRequest extends FormRequest
{
    /**
     * Only available while the native folder picker can actually be shown
     * (the desktop runtime); matches the pre-existing 404 outside it.
     */
    public function authorize(): bool
    {
        return $this->container->make(NativeDialogService::class)->isAvailable();
    }

    /**
     * A 404 (not the default 403) when the dialog can't be shown, so the
     * route continues to look "not found" outside the desktop runtime.
     */
    protected function failedAuthorization(): never
    {
        abort(404);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The typed folder name is validated (and must fail) before the native
     * dialog is ever opened.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(StoragePathService $paths): array
    {
        return [
            'folder_name' => $this->folderNameRules($paths),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'folder_name.required' => 'The folder name is required.',
        ];
    }

    /**
     * The folder name is the single source of truth for what gets created:
     * it is always required, never falls back to a previously saved value.
     *
     * @return array<int, mixed>
     */
    private function folderNameRules(StoragePathService $paths): array
    {
        return [
            'bail', 'required', 'string', 'max:100',
            function (string $attribute, mixed $value, \Closure $fail) use ($paths): void {
                if (! is_string($value)) {
                    return;
                }

                try {
                    $paths->assertValidFolderName($value);
                } catch (InvalidStorageRootException $e) {
                    $fail($e->getMessage());
                }
            },
        ];
    }
}
