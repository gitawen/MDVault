<?php

use App\Enums\IndexMode;
use App\Enums\SettingKey;
use App\Models\Note;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-changecheck-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the check returns exactly the documented keys with no id or vault_id', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    $response = $this->postJson(route('vaults.changes.check', $this->vault->uuid), ['open_note' => $note->uuid]);

    $response->assertOk();
    $response->assertJsonStructure([
        'status', 'changed', 'tree_signature',
        'open_note' => ['uuid', 'exists', 'relative_path', 'file_hash'],
        'orphan_temp_files',
    ]);
    $response->assertExactJson([
        'status' => 'ok',
        'changed' => false,
        'tree_signature' => $response->json('tree_signature'),
        'open_note' => [
            'uuid' => $note->uuid,
            'exists' => true,
            'relative_path' => 'a.md',
            'file_hash' => $note->file_hash,
        ],
        'orphan_temp_files' => [],
    ]);
    expect($response->getContent())->not->toContain('vault_id');
});

test('an invalid open_note fails validation', function () {
    $this->postJson(route('vaults.changes.check', $this->vault->uuid), ['open_note' => 'nope'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('open_note');
});

test('an unknown vault gives 404, and GET gives 405', function () {
    $this->postJson(route('vaults.changes.check', Str::uuid()->toString()))->assertNotFound();
    $this->getJson(route('vaults.changes.check', $this->vault->uuid))->assertStatus(405);
});

test('the setting off gives status disabled', function () {
    app(SettingsService::class)->set(SettingKey::CheckExternalChanges, false);

    $response = $this->postJson(route('vaults.changes.check', $this->vault->uuid), []);

    $response->assertOk();
    expect($response->json('status'))->toBe('disabled');
});
