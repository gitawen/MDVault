<?php

namespace App\Http\Requests\Vaults;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EncryptVaultRequest extends FormRequest
{
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
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:10', 'max:1024', 'confirmed'],
            'acknowledge' => ['accepted'],
            'acknowledge_delete' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.min' => 'Use a password of at least 10 characters.',
            'password.confirmed' => "The password confirmation doesn't match.",
            'acknowledge.accepted' => "Confirm that you understand a forgotten password can't be recovered.",
            'acknowledge_delete.accepted' => 'Confirm that your existing unencrypted notes will be permanently deleted.',
        ];
    }
}
