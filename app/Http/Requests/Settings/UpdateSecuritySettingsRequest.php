<?php

namespace App\Http\Requests\Settings;

use App\Enums\SettingKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSecuritySettingsRequest extends FormRequest
{
    /**
     * The auto-lock choices, in minutes (0 = never).
     *
     * @var list<int>
     */
    public const AUTO_LOCK_CHOICES = SettingKey::SECURITY_AUTO_LOCK_CHOICES;

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
            'auto_lock_minutes' => ['required', 'integer', Rule::in(self::AUTO_LOCK_CHOICES)],
            'lock_on_screen_lock' => ['required', 'boolean'],
        ];
    }
}
