<?php

use App\Enums\IndexMode;
use App\Enums\NoteSaveMode;
use App\Enums\SettingKey;
use App\Models\Note;
use App\Services\ExternalChangeService;
use App\Services\NoteService;
use App\Services\SettingsService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-extchange-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->vault = app(VaultService::class)->create('Work');
    app(VaultService::class)->open($this->vault);
    $this->service = app(ExternalChangeService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the setting off gives disabled and runs no scan', function () {
    app(SettingsService::class)->set(SettingKey::CheckExternalChanges, false);
    writeVaultFiles($this->vault->path, ['New.md' => 'x']);

    $result = $this->service->check($this->vault, null);

    expect($result)->toBe(['status' => 'disabled', 'changed' => false, 'tree_signature' => null, 'open_note' => null, 'orphan_temp_files' => []]);
    expect(Note::query()->count())->toBe(0);
});

test('a vault that is not the current one gives inactive', function () {
    $other = app(VaultService::class)->create('Other');

    $result = $this->service->check($other, null);

    expect($result['status'])->toBe('inactive');
});

test('no current vault gives inactive', function () {
    app(VaultService::class)->close();

    $result = $this->service->check($this->vault, null);

    expect($result['status'])->toBe('inactive');
});

test('a missing vault folder gives unavailable', function () {
    File::deleteDirectory($this->vault->path);

    $result = $this->service->check($this->vault, null);

    expect($result['status'])->toBe('unavailable');
});

test('a clean check gives ok with changed false and a tree signature', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $result = $this->service->check($this->vault, null);

    expect($result['status'])->toBe('ok');
    expect($result['changed'])->toBeFalse();
    expect($result['tree_signature'])->toBeString();
    expect($result['open_note'])->toBeNull();
    expect($result['orphan_temp_files'])->toBe([]);
});

test('an external create gives ok with changed true', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);

    $result = $this->service->check($this->vault, null);

    expect($result['status'])->toBe('ok');
    expect($result['changed'])->toBeTrue();
    expect(Note::query()->count())->toBe(1);
});

test('open_note reports an unchanged note', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    $result = $this->service->check($this->vault, $note->uuid);

    expect($result['open_note'])->toBe([
        'uuid' => $note->uuid,
        'exists' => true,
        'relative_path' => 'a.md',
        'file_hash' => $note->file_hash,
    ]);
});

test('open_note reports a modified note with the disk hash', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    file_put_contents($this->vault->path.DIRECTORY_SEPARATOR.'a.md', 'two-longer');

    $result = $this->service->check($this->vault, $note->uuid);

    expect($result['changed'])->toBeTrue();
    expect($result['open_note']['file_hash'])->toBe(hash('sha256', 'two-longer'));
    expect($result['open_note']['relative_path'])->toBe('a.md');
});

test('open_note reports a moved note at its new path with the same UUID', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    rename($this->vault->path.DIRECTORY_SEPARATOR.'a.md', $this->vault->path.DIRECTORY_SEPARATOR.'b.md');

    $result = $this->service->check($this->vault, $note->uuid);

    expect($result['open_note'])->toBe([
        'uuid' => $note->uuid,
        'exists' => true,
        'relative_path' => 'b.md',
        'file_hash' => $note->file_hash,
    ]);
});

test('open_note reports a deleted note as not existing', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();
    $uuid = $note->uuid;

    unlink($this->vault->path.DIRECTORY_SEPARATOR.'a.md');

    $result = $this->service->check($this->vault, $uuid);

    expect($result['open_note'])->toBe([
        'uuid' => $uuid,
        'exists' => false,
        'relative_path' => null,
        'file_hash' => null,
    ]);
});

test('a uuid from another vault is treated as not found and is never verified', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);

    $other = app(VaultService::class)->create('Other');
    writeVaultFiles($other->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($other, IndexMode::Full);
    $otherNote = Note::query()->where('vault_id', $other->id)->first();

    $result = $this->service->check($this->vault, $otherNote->uuid);

    expect($result['open_note'])->toBe([
        'uuid' => $otherNote->uuid,
        'exists' => false,
        'relative_path' => null,
        'file_hash' => null,
    ]);
    expect($result['changed'])->toBeFalse();
});

test('an in-app save is never reported as an external change', function () {
    writeVaultFiles($this->vault->path, ['a.md' => 'one']);
    app(VaultIndexService::class)->reconcile($this->vault, IndexMode::Full);
    $note = Note::query()->first();

    $result = app(NoteService::class)->save($note, 'two', $note->file_hash, NoteSaveMode::Source);

    $check = $this->service->check($this->vault, $note->uuid);

    expect($check['changed'])->toBeFalse();
    expect($check['open_note']['file_hash'])->toBe($result->fileHash);
});
