<?php

namespace App\Http\Requests\Vaults;

use App\Services\StoragePathService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEncryptedVaultRequest extends FormRequest
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
            'name' => $this->nameRules($paths),
            'description' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'string', 'min:10', 'max:1024', 'confirmed'],
            'acknowledge' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The vault name is required.',
            'password.min' => 'Use a password of at least 10 characters.',
            'password.confirmed' => "The password confirmation doesn't match.",
            'acknowledge.accepted' => "Confirm that you understand a forgotten password can't be recovered.",
        ];
    }
}
