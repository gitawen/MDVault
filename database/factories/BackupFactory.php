<?php

namespace Database\Factories;

use App\Enums\BackupScope;
use App\Models\Backup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vaultUuid = (string) Str::uuid7();
        $name = Str::title(fake()->unique()->word());

        return [
            'scope' => BackupScope::All,
            'filename' => 'MDVault-Backup-'.now()->format('Y-m-d-His').'.zip',
            'path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'MDVault-Backup-'.Str::random(10).'.zip',
            'file_size' => fake()->numberBetween(1024, 10_000_000),
            'file_hash' => hash('sha256', Str::random(32)),
            'format_version' => 1,
            'vault_count' => 1,
            'note_count' => fake()->numberBetween(0, 50),
            'file_count' => 0,
            'contents' => [
                ['uuid' => $vaultUuid, 'name' => $name, 'notes' => fake()->numberBetween(0, 50)],
            ],
        ];
    }
}
