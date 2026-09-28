<?php

namespace Database\Factories;

use App\Models\Note;
use App\Models\Vault;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Note>
 */
class NoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = Str::title(fake()->unique()->word());

        return [
            'vault_id' => Vault::factory(),
            'title' => $title,
            'filename' => "{$title}.md",
            'relative_path' => "{$title}.md",
            'extension' => 'md',
            'mime_type' => Note::MIME_TYPE,
            'file_size' => 0,
            'file_hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            'is_encrypted' => false,
        ];
    }
}
