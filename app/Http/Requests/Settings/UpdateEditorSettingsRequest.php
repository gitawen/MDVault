<?php

namespace App\Http\Requests\Settings;

use App\Enums\EditorFontFamily;
use App\Exceptions\NoteOperationException;
use App\Services\MarkdownService;
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
    public function rules(MarkdownService $markdown): array
    {
        return [
            'font_size' => ['required', 'integer', 'between:12,24'],
            'font_family' => ['required', Rule::enum(EditorFontFamily::class)],
            'line_height' => ['required', 'numeric', 'between:1.2,2.2'],
            'word_wrap' => ['required', 'boolean'],
            'show_line_numbers' => ['required', 'boolean'],
            'indent_size' => ['required', 'integer', 'between:1,8'],
            'new_note_template_enabled' => ['required', 'boolean'],
            'new_note_template' => [
                'nullable',
                'string',
                'max:4000',
                'required_if_accepted:new_note_template_enabled',
                function (string $attribute, mixed $value, \Closure $fail) use ($markdown): void {
                    if ($value === null) {
                        return;
                    }

                    $normalized = str_replace("\r\n", "\n", (string) $value);

                    if (str_contains($normalized, "\0")) {
                        $fail('The template contains an invalid character.');

                        return;
                    }

                    try {
                        $markdown->assertValidFrontmatterYaml($normalized);
                    } catch (NoteOperationException) {
                        $fail("The template can't contain a line of three dashes (---). MDVault adds the --- lines around it for you.");
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'new_note_template.required_if_accepted' => 'Enter a template, or turn the template off.',
        ];
    }
}
