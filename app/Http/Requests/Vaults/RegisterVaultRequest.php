<?php

namespace App\Http\Requests\Vaults;

use App\Services\StoragePathService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterVaultRequest extends FormRequest
{
    use VaultNameRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(StoragePathService $paths): array
    {
        return [
            'path' => ['required', 'string', 'max:1024'],
            'name' => $this->nameRules($paths),
            'description' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'path.required' => 'The folder path is required.',
            'name.required' => 'The vault name is required.',
        ];
    }
}
