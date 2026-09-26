<?php

namespace App\Http\Requests\Settings;

use App\Enums\EditorFontFamily;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEditorSettingsRequest extends FormRequest
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
            'font_size' => ['required', 'integer', 'between:12,24'],
            'font_family' => ['required', Rule::enum(EditorFontFamily::class)],
            'line_height' => ['required', 'numeric', 'between:1.2,2.2'],
            'word_wrap' => ['required', 'boolean'],
            'show_line_numbers' => ['required', 'boolean'],
        ];
    }
}
