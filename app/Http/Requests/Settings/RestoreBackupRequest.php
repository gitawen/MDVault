<?php

namespace App\Http\Requests\Settings;

use App\Enums\RestoreAction;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RestoreBackupRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'path' => ['required', 'string', 'max:4096'],
            'vaults' => ['required', 'array', 'min:1', 'max:1000'],
            'vaults.*.uuid' => ['required', 'uuid', 'distinct'],
            'vaults.*.action' => ['required', Rule::enum(RestoreAction::class)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $vaults = $this->input('vaults');

            if (! is_array($vaults)) {
                return;
            }

            $everySkipped = collect($vaults)->every(
                fn (mixed $v): bool => is_array($v) && ($v['action'] ?? null) === RestoreAction::Skip->value,
            );

            if ($everySkipped) {
                $validator->errors()->add('vaults', 'Choose at least one vault to restore.');
            }
        });
    }

    /**
     * @return array<string, RestoreAction>
     */
    public function actions(): array
    {
        $actions = [];

        foreach ($this->validated('vaults') as $vault) {
            $actions[$vault['uuid']] = RestoreAction::from($vault['action']);
        }

        return $actions;
    }
}
