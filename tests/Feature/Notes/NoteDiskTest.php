<?php

use App\Enums\IndexMode;
use App\Models\Note;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-notedisk-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the disk endpoint returns the current disk text after an external edit', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'two-longer');

    $response = $this->getJson(route('notes.disk.show', $note->uuid));

    $response->assertOk();
    $response->assertJson([
        'state' => 'ok',
        'content' => 'two-longer',
        'base_hash' => hash('sha256', 'two-longer'),
    ]);
});

test('a missing file gives state missing', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $response = $this->getJson(route('notes.disk.show', $note->uuid));

    $response->assertOk();
    $response->assertJson(['state' => 'missing', 'content' => null, 'base_hash' => null]);
});

test('an unknown uuid 404s', function () {
    $this->getJson(route('notes.disk.show', Str::uuid()->toString()))->assertNotFound();
});
