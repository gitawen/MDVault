<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $uuid
 * @property int $vault_id
 * @property string $title
 * @property string $filename
 * @property string $relative_path
 * @property string $extension
 * @property string $mime_type
 * @property int $file_size
 * @property string $file_hash
 * @property bool $is_encrypted
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Vault $vault
 */
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory, HasUuids;

    public const MIME_TYPE = 'text/markdown';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vault_id',
        'title',
        'filename',
        'relative_path',
        'extension',
        'mime_type',
        'file_size',
        'file_hash',
        'is_encrypted',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['id', 'vault_id'];

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
            'file_size' => 'integer',
            'is_encrypted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Vault, $this>
     */
    public function vault(): BelongsTo
    {
        return $this->belongsTo(Vault::class);
    }
}
