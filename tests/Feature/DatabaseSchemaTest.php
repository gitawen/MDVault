<?php

use Illuminate\Support\Facades\Schema;

test('the schema keeps only framework tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with(['sessions', 'cache', 'jobs']);

test('the schema has no authentication tables', function (string $table) {
    expect(Schema::hasTable($table))->toBeFalse();
})->with(['users', 'password_reset_tokens', 'passkeys']);
