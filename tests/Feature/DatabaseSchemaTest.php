<?php

use Illuminate\Support\Facades\Schema;

test('the schema keeps only framework tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['sessions', 'cache', 'jobs', 'settings']);

test('the schema has no authentication tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['users', 'password_reset_tokens', 'passkeys']);

test('the settings table has the expected columns', function () {
    expect(Schema::hasColumns('settings', ['id', 'key', 'value', 'type', 'group', 'created_at', 'updated_at']))->toBeTrue();
});

test('phase 2 tables do not exist yet', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['vaults', 'notes', 'vault_encryption', 'backups']);
