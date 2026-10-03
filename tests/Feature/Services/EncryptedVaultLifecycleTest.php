<?php

use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\VaultEncryptionService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-enclife-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    $this->vaults = app(VaultService::class);
    $this->encryption = app(VaultEncryptionService::class);
    $this->keys = app(VaultKeyService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('a created encrypted vault holds only its key file and is unlocked', function () {
    [$vault, $token] = $this->encryption->createEncryptedVault('Secrets', 'Private notes', 'correct horse battery');

    expect($vault->is_encrypted)->toBeTrue()
        ->and($vault->name)->toBe('Secrets')
        ->and($vault->description)->toBe('Private notes')
        ->and(array_map('basename', File::allFiles($vault->path)))->toBe([VaultEncryptionService::HEADER_FILENAME])
        ->and(File::directories($vault->path))->toBe([])
        ->and(VaultEncryption::query()->where('vault_id', $vault->id)->count())->toBe(1)
        ->and($token)->toMatch('/^[A-Za-z0-9_-]{43}$/');

    $this->keys->provideTokens($vault->uuid.':'.$token);
    expect($this->keys->isUnlocked($vault))->toBeTrue()
        ->and($this->vaults->present($vault)['is_unlocked'])->toBeTrue();

    $this->keys->provideTokens(null);
    expect($this->vaults->present($vault)['is_unlocked'])->toBeFalse()
        ->and($this->vaults->present($vault)['is_encrypted'])->toBeTrue();

    // The password unlocks it again, the wrong one doesn't.
    expect($this->encryption->unlock($vault, 'correct horse battery'))->toBeString();
    expect(fn () => $this->encryption->unlock($vault, 'wrong password!!'))->toThrow(EncryptionException::class);

    assertNoNeedlesInDatabase(['correct horse battery', $token]);
});

test('a plaintext vault is never reported as unlocked', function () {
    $vault = $this->vaults->create('Plain');

    expect($this->vaults->present($vault)['is_unlocked'])->toBeFalse()
        ->and($this->vaults->present($vault)['is_encrypted'])->toBeFalse();
});

test('a registry failure during creation leaves no record and no folder', function () {
    DB::unprepared('create trigger fail_mirror before insert on vault_encryption begin select raise(abort, "boom"); end');

    try {
        expect(fn () => $this->encryption->createEncryptedVault('Doomed', null, 'correct horse battery'))
            ->toThrow(QueryException::class);
    } finally {
        DB::unprepared('drop trigger fail_mirror');
    }

    expect(Vault::query()->count())->toBe(0)
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(File::isDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault'.DIRECTORY_SEPARATOR.'Doomed'))->toBeFalse();
});

test('a duplicate name is refused before anything is written', function () {
    $this->vaults->create('Taken');

    expect(fn () => $this->encryption->createEncryptedVault('taken', null, 'correct horse battery'))
        ->toThrow(VaultOperationException::class);
    expect(VaultEncryption::query()->count())->toBe(0);
});

test('after a registry reset the folder registers as an encrypted, locked vault', function () {
    [$vault] = $this->encryption->createEncryptedVault('Secrets', null, 'correct horse battery');
    $path = $vault->path;

    $this->vaults->resetRegistry();
    expect(Vault::query()->count())->toBe(0)->and(VaultEncryption::query()->count())->toBe(0);

    $again = $this->vaults->register($path, 'Secrets');

    expect($again->is_encrypted)->toBeTrue()
        ->and(VaultEncryption::query()->where('vault_id', $again->id)->count())->toBe(1)
        ->and($this->vaults->present($again)['is_unlocked'])->toBeFalse();

    expect($this->encryption->unlock($again, 'correct horse battery'))->toBeString();
    expect(fn () => $this->encryption->unlock($again, 'not the password'))->toThrow(EncryptionException::class);
});

test('a folder with a damaged key file is refused', function () {
    $folder = $this->tmp.DIRECTORY_SEPARATOR.'Damaged';
    File::makeDirectory($folder, 0755, true);
    File::put($folder.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, '{"not":"a key file"}');

    try {
        $this->vaults->register($folder, 'Damaged');
        $this->fail('Expected a refusal.');
    } catch (VaultOperationException $e) {
        expect($e->field())->toBe('path')
            ->and($e->getMessage())->toContain('key file is damaged');
    }

    expect(Vault::query()->count())->toBe(0);
});

test('a plain folder still registers as an unencrypted vault', function () {
    $folder = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($folder, 0755, true);

    $vault = $this->vaults->register($folder, 'Existing');

    expect($vault->is_encrypted)->toBeFalse()
        ->and(VaultEncryption::query()->count())->toBe(0);
});

test('closing an encrypted vault locks it', function () {
    [$vault, $token] = $this->encryption->createEncryptedVault('Secrets', null, 'correct horse battery');
    $this->vaults->open($vault);
    $header = $vault->uuid.':'.$token;

    $this->keys->provideTokens($header);
    expect($this->keys->isUnlocked($vault))->toBeTrue();

    $this->vaults->close();

    $this->keys->provideTokens($header);
    expect($this->keys->isUnlocked($vault))->toBeFalse();
});

test('switching to another vault does not lock the first one', function () {
    [$first, $token] = $this->encryption->createEncryptedVault('First', null, 'correct horse battery');
    $second = $this->vaults->create('Second');
    $this->vaults->open($first);
    $this->vaults->open($second);

    $this->keys->provideTokens($first->uuid.':'.$token);

    expect($this->keys->isUnlocked($first))->toBeTrue();
});

test('removing an encrypted vault forgets the key and cascades the mirror', function () {
    [$vault, $token] = $this->encryption->createEncryptedVault('Secrets', null, 'correct horse battery');
    $id = $vault->id;
    $header = $vault->uuid.':'.$token;

    $this->vaults->remove($vault);

    $this->keys->provideTokens($header);

    expect(VaultEncryption::query()->where('vault_id', $id)->count())->toBe(0)
        ->and(session()->has(VaultKeyService::SESSION_PREFIX.$vault->uuid))->toBeFalse()
        ->and(File::exists($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME))->toBeTrue();
});

test('a reset never removes the key file', function () {
    [$vault] = $this->encryption->createEncryptedVault('Secrets', null, 'correct horse battery');

    $this->vaults->resetRegistry();

    expect(File::exists($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME))->toBeTrue();
});
