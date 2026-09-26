<?php

namespace App\Models;

use App\Enums\SettingGroup;
use App\Enums\SettingType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property ?string $value
 * @property SettingType $type
 * @property SettingGroup $group
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['key', 'value', 'type', 'group'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => SettingType::class,
            'group' => SettingGroup::class,
        ];
    }
}
