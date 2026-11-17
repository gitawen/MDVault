<?php

namespace App\Http\Requests\Settings;

use App\Enums\RestoreAction;
use App\Enums\SettingGroup;
use App\Enums\SettingKey;
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
            'setting_groups' => ['sometimes', 'array'],
            'setting_groups.*' => ['required', 'string', 'distinct', Rule::in(SettingKey::portableGroupValues())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $vaults = $this->input('vaults');

            if (! is_array($vaults)) {
                return;
            }

            $everySkipped = collect($vaults)->every(
                fn (mixed $v): bool => is_array($v) && ($v['action'] ?? null) === RestoreAction::Skip->value,
            );

            $settingGroups = $this->input('setting_groups', []);

            if ($everySkipped && (! is_array($settingGroups) || $settingGroups === [])) {
                $validator->errors()->add('vaults', 'Choose at least one vault or setting group to restore.');
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

    /**
     * @return list<SettingGroup>
     */
    public function settingGroups(): array
    {
        /** @var list<string> $groups */
        $groups = $this->validated('setting_groups', []);

        return array_map(
            static fn (string $group): SettingGroup => SettingGroup::from($group),
            $groups,
        );
    }
}
