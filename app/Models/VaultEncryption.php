<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Non-secret mirror of a vault's on-disk key file (the disk copy is
 * authoritative). Holds the wrapped data key, never a password or key.
 *
 * @property int $id
 * @property int $vault_id
 * @property string $key_id
 * @property int $key_version
 * @property string $algorithm
 * @property string $kdf_algorithm
 * @property int $kdf_opslimit
 * @property int $kdf_memlimit
 * @property string $salt
 * @property string $nonce
 * @property string $encrypted_key
 * @property int $format_version
 * @property string $header_hash
 * @property ?CarbonImmutable $created_at
 * @property ?CarbonImmutable $updated_at
 * @property-read Vault $vault
 */
class VaultEncryption extends Model
{
    protected $table = 'vault_encryption';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'vault_id',
        'key_id',
        'key_version',
        'algorithm',
        'kdf_algorithm',
        'kdf_opslimit',
        'kdf_memlimit',
        'salt',
        'nonce',
        'encrypted_key',
        'format_version',
        'header_hash',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['id', 'vault_id', 'salt', 'nonce', 'encrypted_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'key_version' => 'integer',
            'kdf_opslimit' => 'integer',
            'kdf_memlimit' => 'integer',
            'format_version' => 'integer',
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
