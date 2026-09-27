<?php

use App\Enums\VaultStatus;
use App\Models\Vault;

test('uuid is a 36-character string and id is an int', function () {
    $vault = Vault::factory()->create();

    expect($vault->uuid)->toBeString()->and(mb_strlen($vault->uuid))->toBe(36);
    expect($vault->id)->toBeInt();
});

test('uuid is unchanged after an update', function () {
    $vault = Vault::factory()->create();
    $uuid = $vault->uuid;

    $vault->update(['name' => 'X']);

    expect($vault->fresh()->uuid)->toBe($uuid);
});

test('toArray has no id', function () {
    $vault = Vault::factory()->create();

    expect($vault->toArray())->not->toHaveKey('id');
});

test('status casts to VaultStatus', function () {
    $vault = Vault::factory()->create();

    expect($vault->status)->toBeInstanceOf(VaultStatus::class);
    expect($vault->status)->toBe(VaultStatus::Active);
});
