<?php

namespace App\Http\Requests\Vaults;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVaultPasswordRequest extends FormRequest
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
            'current_password' => ['required', 'string', 'max:1024'],
            'password' => ['required', 'string', 'min:10', 'max:1024', 'confirmed', 'different:current_password'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter the current vault password.',
            'password.min' => 'Use a password of at least 10 characters.',
            'password.confirmed' => "The password confirmation doesn't match.",
            'password.different' => 'Choose a password different from the current one.',
        ];
    }
}
