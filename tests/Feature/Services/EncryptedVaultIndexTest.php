<?php

use App\Enums\IndexMode;
use App\Models\Note;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-encindex-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    [$this->vault] = encryptedVault('Secrets');
    // From here on the key is gone: indexing must not need it.
    app(VaultKeyService::class)->forgetAll();

    $this->index = app(VaultIndexService::class);
    $this->id = fn (): string => bin2hex(random_bytes(16));
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('opaque note files are indexed with encrypted attributes and no key', function () {
    $root = ($this->id)();
    $folder = ($this->id)();
    $nested = ($this->id)();

    writeVaultFiles($this->vault->path, [
        "{$root}.mdenc" => 'cipher one',
        "{$folder}/folder.mdenc" => 'name file',
        "{$folder}/{$nested}.mdenc" => 'cipher two',
    ]);

    $result = $this->index->reconcile($this->vault, IndexMode::Full);

    expect($result->added)->toBe(2)
        ->and($this->vault->notes()->count())->toBe(2);

    $note = $this->vault->notes()->where('relative_path', "{$folder}/{$nested}.mdenc")->first();

    expect($note->extension)->toBe('mdenc')
        ->and($note->mime_type)->toBe(Note::ENCRYPTED_MIME_TYPE)
        ->and($note->is_encrypted)->toBeTrue()
        ->and($note->title)->toBe($nested)
        ->and($note->filename)->toBe("{$nested}.mdenc")
        ->and($note->file_hash)->toBe(hash('sha256', 'cipher two'))
        ->and($note->file_size)->toBe(strlen('cipher two'));
});

test('the folder name files and the key file are never indexed', function () {
    $folder = ($this->id)();

    writeVaultFiles($this->vault->path, ["{$folder}/folder.mdenc" => 'name file']);

    $result = $this->index->reconcile($this->vault, IndexMode::Full);

    expect($result->added)->toBe(0)
        ->and($this->vault->notes()->count())->toBe(0)
        ->and(File::exists($this->vault->path.DIRECTORY_SEPARATOR.'mdvault-encryption.json'))->toBeTrue();
});

test('files outside the opaque layout are not indexed', function () {
    writeVaultFiles($this->vault->path, [
        'Photos/'.($this->id)().'.mdenc' => 'x',
        ($this->id)().'.txt' => 'x',
        'short.mdenc' => 'x',
        '.hidden/'.($this->id)().'.mdenc' => 'x',
    ]);

    expect($this->index->reconcile($this->vault, IndexMode::Full)->added)->toBe(0);
});

test('an external move keeps the uuid', function () {
    $folder = ($this->id)();
    $file = ($this->id)();

    writeVaultFiles($this->vault->path, ["{$file}.mdenc" => 'moving cipher', "{$folder}/folder.mdenc" => 'n']);
    $this->index->reconcile($this->vault, IndexMode::Full);
    $uuid = $this->vault->notes()->first()->uuid;

    File::move($this->vault->path.DIRECTORY_SEPARATOR."{$file}.mdenc", $this->vault->path.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR."{$file}.mdenc");

    $result = $this->index->reconcile($this->vault, IndexMode::Quick);

    expect($result->moved)->toBe(1)
        ->and($result->added)->toBe(0)
        ->and($result->removed)->toBe(0)
        ->and($this->vault->notes()->first()->uuid)->toBe($uuid)
        ->and($this->vault->notes()->first()->relative_path)->toBe("{$folder}/{$file}.mdenc");
});

test('an external delete and an external modification are detected while locked', function () {
    $a = ($this->id)();
    $b = ($this->id)();

    writeVaultFiles($this->vault->path, ["{$a}.mdenc" => 'cipher a', "{$b}.mdenc" => 'cipher b']);
    $this->index->reconcile($this->vault, IndexMode::Full);

    File::delete($this->vault->path.DIRECTORY_SEPARATOR."{$a}.mdenc");
    File::put($this->vault->path.DIRECTORY_SEPARATOR."{$b}.mdenc", 'different cipher bytes');

    $result = $this->index->reconcile($this->vault, IndexMode::Full);

    expect($result->removed)->toBe(1)
        ->and($result->updated)->toBe(1)
        ->and($this->vault->notes()->count())->toBe(1)
        ->and($this->vault->notes()->first()->file_hash)->toBe(hash('sha256', 'different cipher bytes'));
});

test('a dropped plaintext note is reported but never indexed', function () {
    writeVaultFiles($this->vault->path, [
        'Secret.md' => '# Secret',
        'Sub/Deeper.md' => 'x',
        '.hidden.md' => 'ignored',
    ]);

    $result = $this->index->reconcile($this->vault, IndexMode::Full);

    expect($result->added)->toBe(0)
        ->and($this->vault->notes()->count())->toBe(0)
        ->and($result->unencryptedFiles)->toEqualCanonicalizing(['Secret.md', 'Sub/Deeper.md']);
});

test('unencrypted file reports are capped', function () {
    for ($i = 0; $i < VaultIndexService::UNENCRYPTED_REPORT_LIMIT + 5; $i++) {
        writeVaultFiles($this->vault->path, ["note-{$i}.md" => 'x']);
    }

    expect($this->index->reconcile($this->vault, IndexMode::Full)->unencryptedFiles)->toHaveCount(VaultIndexService::UNENCRYPTED_REPORT_LIMIT);
});

test('the encrypted tree signature changes when a note is rewritten in place', function () {
    $file = ($this->id)();

    writeVaultFiles($this->vault->path, ["{$file}.mdenc" => 'cipher v1']);
    $before = $this->index->reconcile($this->vault, IndexMode::Full)->treeSignature;

    File::put($this->vault->path.DIRECTORY_SEPARATOR."{$file}.mdenc", 'cipher v2 renamed inside');
    $after = $this->index->reconcile($this->vault, IndexMode::Full)->treeSignature;

    expect($before)->not->toBe($after);
});

test('plaintext vaults still index only visible markdown files', function () {
    $vault = app(VaultService::class)->create('Plain');

    writeVaultFiles($vault->path, ['A.md' => 'a', 'B.mdenc' => 'x', 'note.txt' => 'x']);

    $result = $this->index->reconcile($vault, IndexMode::Full);

    expect($result->added)->toBe(1)
        ->and($result->unencryptedFiles)->toBe([])
        ->and($vault->notes()->first()->is_encrypted)->toBeFalse()
        ->and($vault->notes()->first()->mime_type)->toBe(Note::MIME_TYPE);
});
