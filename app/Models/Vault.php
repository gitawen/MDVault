<?php

namespace App\Models;

use App\Enums\VaultStatus;
use Carbon\CarbonImmutable;
use Database\Factories\VaultFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property ?string $description
 * @property string $path
 * @property ?string $relative_path
 * @property bool $is_encrypted
 * @property VaultStatus $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Vault extends Model
{
    /** @use HasFactory<VaultFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'path',
        'relative_path',
        'is_encrypted',
        'status',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['id'];

    /**
     * The column(s) that receive a generated UUID. `id` stays auto-increment.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'status' => VaultStatus::class,
        ];
    }
}
