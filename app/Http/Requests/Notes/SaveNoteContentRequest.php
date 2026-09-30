<?php

namespace App\Http\Requests\Notes;

use App\Enums\NoteSaveMode;
use App\Services\NoteService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveNoteContentRequest extends FormRequest
{
    use InteractsWithNoteContent;

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
            'content' => [
                'present',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen((string) ($value ?? '')) > NoteService::EDIT_LIMIT) {
                        $fail("This note is larger than 1 MB, which the editor can't save. The file on disk wasn't changed.");
                    }
                },
            ],
            'base_hash' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'mode' => ['required', Rule::enum(NoteSaveMode::class)],
            'has_frontmatter' => ['sometimes', 'boolean', 'prohibited_unless:mode,rich'],
            'frontmatter' => ['nullable', 'string'],
        ];
    }
}
