<?php

namespace App\Models;

use App\Enums\BackupScope;
use Carbon\CarbonImmutable;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A record of a backup this installation created successfully (ADR
 * `backup-archive-format`). No foreign key to `vaults`: a record outlives
 * vault removal. Restores are never recorded here.
 *
 * @property int $id
 * @property string $uuid
 * @property BackupScope $scope
 * @property string $filename
 * @property string $path
 * @property int $file_size
 * @property string $file_hash
 * @property int $format_version
 * @property int $vault_count
 * @property int $note_count
 * @property int $file_count
 * @property array<int, array{uuid: string, name: string, notes: int}> $contents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'filename',
        'path',
        'file_size',
        'file_hash',
        'format_version',
        'vault_count',
        'note_count',
        'file_count',
        'contents',
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
            'scope' => BackupScope::class,
            'contents' => 'array',
            'file_size' => 'integer',
            'format_version' => 'integer',
            'vault_count' => 'integer',
            'note_count' => 'integer',
            'file_count' => 'integer',
        ];
    }
}
