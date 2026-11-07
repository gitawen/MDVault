<?php

namespace App\Http\Requests\Vaults;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterVaultBatchRequest extends FormRequest
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
            'paths' => ['required', 'array', 'min:1', 'max:50'],
            'paths.*' => ['required', 'string', 'max:1024', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'paths.required' => 'At least one folder path is required.',
            'paths.min' => 'At least one folder path is required.',
            'paths.max' => 'Cannot register more than :max folders at once.',
            'paths.*.required' => 'Each folder path is required.',
            'paths.*.distinct' => 'Duplicate folder paths are not allowed.',
        ];
    }
}
