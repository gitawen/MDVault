<?php

use App\Models\Note;
use App\Models\Vault;
use Illuminate\Database\QueryException;

test('uuid is a 36-character string and unchanged after an update', function () {
    $note = Note::factory()->create();
    $uuid = $note->uuid;

    expect($uuid)->toBeString()->and(mb_strlen($uuid))->toBe(36);

    $note->update(['title' => 'X']);

    expect($note->fresh()->uuid)->toBe($uuid);
});

test('toArray has no id or vault_id', function () {
    $note = Note::factory()->create();

    expect($note->toArray())->not->toHaveKey('id')->not->toHaveKey('vault_id');
});

test('a note belongs to its vault and the vault has the note', function () {
    $vault = Vault::factory()->create();
    $note = Note::factory()->create(['vault_id' => $vault->id]);

    expect($note->vault->is($vault))->toBeTrue();
    expect($vault->notes->pluck('id'))->toContain($note->id);
});

test('deleting the vault cascades to its notes', function () {
    $vault = Vault::factory()->create();
    $note = Note::factory()->create(['vault_id' => $vault->id]);

    $vault->delete();

    expect(Note::query()->whereKey($note->id)->exists())->toBeFalse();
});

test('the same vault cannot have two notes with the same relative path', function () {
    $vault = Vault::factory()->create();
    Note::factory()->create(['vault_id' => $vault->id, 'relative_path' => 'a.md']);

    expect(fn () => Note::factory()->create(['vault_id' => $vault->id, 'relative_path' => 'a.md']))
        ->toThrow(QueryException::class);
});
