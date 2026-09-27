<?php

namespace Database\Factories;

use App\Enums\VaultStatus;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Vault>
 */
class VaultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->word()),
            'description' => null,
            'path' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-factory-'.Str::random(10),
            'relative_path' => null,
            'is_encrypted' => false,
            'status' => VaultStatus::Active,
        ];
    }

    /**
     * A vault whose folder no longer exists.
     */
    public function missing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => VaultStatus::Missing,
        ]);
    }
}
