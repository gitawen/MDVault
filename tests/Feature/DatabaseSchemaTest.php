<?php

use Illuminate\Support\Facades\Schema;

test('the schema keeps only framework tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['sessions', 'cache', 'jobs', 'settings', 'vaults', 'notes']);

test('the schema has no authentication tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['users', 'password_reset_tokens', 'passkeys']);

test('the settings table has the expected columns', function () {
    expect(Schema::hasColumns('settings', ['id', 'key', 'value', 'type', 'group', 'created_at', 'updated_at']))->toBeTrue();
});

test('the vaults table has the expected columns', function () {
    expect(Schema::hasColumns('vaults', [
        'id', 'uuid', 'name', 'description', 'path', 'relative_path',
        'is_encrypted', 'status', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

test('the notes table has the expected columns', function () {
    expect(Schema::hasColumns('notes', [
        'id', 'uuid', 'vault_id', 'title', 'filename', 'relative_path',
        'extension', 'mime_type', 'file_size', 'file_hash', 'is_encrypted',
        'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumn('notes', 'content'))->toBeFalse();
});

test('later-phase tables do not exist yet', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['vault_encryption', 'backups']);
