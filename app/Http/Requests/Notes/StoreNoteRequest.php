<?php

namespace App\Http\Requests\Notes;

use App\Services\NoteService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
{
    use NoteNameRules;

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
    public function rules(NoteService $notes): array
    {
        return [
            'name' => $this->noteNameRules($notes),
            'folder' => ['nullable', 'string', 'max:1024'],
        ];
    }
}
