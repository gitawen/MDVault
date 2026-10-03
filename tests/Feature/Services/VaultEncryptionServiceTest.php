<?php

use App\Enums\VaultStatus;
use App\Exceptions\EncryptionException;
use App\Exceptions\VaultOperationException;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\EncryptionService;
use App\Services\VaultEncryptionService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const VES_PASSWORD = 'Canary-Password-91!';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-vault-encryption-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');

    $this->crypto = app(EncryptionService::class);
    $this->service = app(VaultEncryptionService::class);
    $this->keys = app(VaultKeyService::class);

    [$this->vault, $this->token] = encryptedVault('Secrets', VES_PASSWORD);
    $this->keyFile = $this->vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME;
});

afterEach(function () {
    DB::unprepared('DROP TRIGGER IF EXISTS fail_mirror_update');
    File::deleteDirectory($this->tmp);
});

/** A fresh request context: no tokens, nothing opened. */
function newRequest(): void
{
    app(VaultKeyService::class)->provideTokens(null);
}

function sessionHoldsEntry(Vault $vault): bool
{
    return session()->has(VaultKeyService::SESSION_PREFIX.$vault->uuid);
}

test('the helper builds an encrypted, unlocked vault whose folder holds only the key file', function () {
    expect($this->vault->is_encrypted)->toBeTrue()
        ->and(scandir($this->vault->path))->toBe(['.', '..', VaultEncryptionService::HEADER_FILENAME])
        ->and($this->vault->encryption)->not->toBeNull()
        ->and($this->keys->isUnlocked($this->vault))->toBeTrue()
        ->and($this->token)->toMatch('/^[A-Za-z0-9_-]{43}$/');
});

test('the correct password unlocks and a wrong one is refused with no session entry', function () {
    $this->keys->forget($this->vault);
    expect(sessionHoldsEntry($this->vault))->toBeFalse();

    try {
        $this->service->unlock($this->vault, 'not the password');
        test()->fail('Expected an exception.');
    } catch (EncryptionException $e) {
        expect($e->field())->toBe('password');
    }

    expect(sessionHoldsEntry($this->vault))->toBeFalse();

    $token = $this->service->unlock($this->vault, VES_PASSWORD);

    expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(sessionHoldsEntry($this->vault))->toBeTrue()
        ->and($this->keys->isUnlocked($this->vault))->toBeTrue();
});

test('lock and lockAll forget the keys', function () {
    $this->service->lock($this->vault);
    expect(sessionHoldsEntry($this->vault))->toBeFalse();

    $this->service->unlock($this->vault, VES_PASSWORD);
    $this->service->lockAll();
    expect(sessionHoldsEntry($this->vault))->toBeFalse();
});

test('unlock refuses an unencrypted vault and a missing vault', function () {
    $plain = app(VaultService::class)->create('Plain');

    expect(fn () => $this->service->unlock($plain, 'whatever'))->toThrow(EncryptionException::class, "This vault isn't encrypted.");

    $this->vault->forceFill(['status' => VaultStatus::Missing])->save();

    expect(fn () => $this->service->unlock($this->vault, VES_PASSWORD))->toThrow(VaultOperationException::class);
});

test('a changed key file on disk updates the mirror at the next unlock', function () {
    [$header] = $this->service->readHeader($this->vault);
    $key = $this->crypto->unlock($header, VES_PASSWORD);
    $rewrapped = $this->crypto->rewrap($header, $key, 'changed outside the app');
    $bytes = $this->crypto->encodeHeader($rewrapped);
    File::put($this->keyFile, $bytes);

    expect($this->vault->encryption->fresh()->key_version)->toBe(1);

    $this->service->unlock($this->vault, 'changed outside the app');

    $mirror = $this->vault->encryption()->first();

    expect($mirror->key_version)->toBe(2)
        ->and($mirror->header_hash)->toBe(hash('sha256', $bytes));

    expect(fn () => $this->service->unlock($this->vault, VES_PASSWORD))->toThrow(EncryptionException::class);
});

test('a deleted key file is re-created identically after a correct unlock, not after a wrong one', function () {
    $original = File::get($this->keyFile);
    File::delete($this->keyFile);

    expect(fn () => $this->service->unlock($this->vault, 'wrong password'))->toThrow(EncryptionException::class)
        ->and(File::exists($this->keyFile))->toBeFalse();

    $this->service->unlock($this->vault, VES_PASSWORD);

    expect(File::exists($this->keyFile))->toBeTrue()
        ->and(File::get($this->keyFile))->toBe($original)
        ->and($this->vault->encryption()->first()->header_hash)->toBe(hash('sha256', $original));
});

test('readHeader prefers the disk copy and falls back to the mirror', function () {
    [, $source, $hash] = $this->service->readHeader($this->vault);

    expect($source)->toBe('disk')
        ->and($hash)->toBe(hash('sha256', File::get($this->keyFile)));

    File::delete($this->keyFile);
    [$header, $source, $hash] = $this->service->readHeader($this->vault);

    expect($source)->toBe('registry')
        ->and($hash)->toBeNull()
        ->and($header->keyId)->toBe($this->vault->encryption->key_id);

    VaultEncryption::query()->delete();

    expect(fn () => $this->service->readHeader($this->vault->refresh()))->toThrow(EncryptionException::class);
});

test('a damaged key file on disk is refused even when the mirror is fine', function () {
    File::put($this->keyFile, '{"not":"a key file"}');

    expect(fn () => $this->service->unlock($this->vault, VES_PASSWORD))
        ->toThrow(EncryptionException::class, "This vault's key file is damaged or isn't an MDVault key file.");
});

test('changing the password: old fails, new works, version 2, notes untouched, session stays valid', function () {
    $note = $this->vault->path.DIRECTORY_SEPARATOR.str_repeat('a', 32).'.mdenc';
    File::put($note, 'ciphertext placeholder bytes');
    $noteHash = hash_file('sha256', $note);

    $this->service->changePassword($this->vault, VES_PASSWORD, 'a much newer password');

    expect(hash_file('sha256', $note))->toBe($noteHash)
        ->and($this->vault->encryption()->first()->key_version)->toBe(2)
        ->and($this->crypto->parseHeader(File::get($this->keyFile))->keyVersion)->toBe(2)
        ->and($this->vault->encryption()->first()->header_hash)->toBe(hash('sha256', File::get($this->keyFile)));

    // The vault stays unlocked with the same token.
    $this->keys->provideTokens($this->vault->uuid.':'.$this->token);
    expect($this->keys->isUnlocked($this->vault))->toBeTrue();

    newRequest();
    expect(fn () => $this->service->unlock($this->vault, VES_PASSWORD))->toThrow(EncryptionException::class);
    expect($this->service->unlock($this->vault, 'a much newer password'))->toBeString();
});

test('changing the password needs the current one', function () {
    $before = File::get($this->keyFile);

    expect(fn () => $this->service->changePassword($this->vault, 'wrong current', 'a much newer password'))
        ->toThrow(EncryptionException::class);

    expect(File::get($this->keyFile))->toBe($before);
});

test('a key file changed since it was read blocks the password change', function () {
    [$header, $source, $hash] = $this->service->readHeader($this->vault);
    $other = $this->crypto->rewrap($header, $this->crypto->unlock($header, VES_PASSWORD), 'somebody else');

    // Simulate a writer slipping in between the read and the replace.
    $fake = fakeFilePuts(onPut: fn () => file_put_contents($this->keyFile, $this->crypto->encodeHeader($other)));
    $service = app(VaultEncryptionService::class);

    expect(fn () => $service->changePassword($this->vault, VES_PASSWORD, 'a much newer password'))
        ->toThrow(EncryptionException::class, "The vault's key file couldn't be updated. Close any programs using the vault folder and try again. Nothing was changed.");

    expect($this->crypto->parseHeader(File::get($this->keyFile))->keyVersion)->toBe(2);
    expect($fake->calls)->toBeGreaterThan(0);
});

test('a database failure while updating the mirror keeps the new key file and the next unlock resyncs', function () {
    $logs = captureLogs();
    DB::unprepared("CREATE TRIGGER fail_mirror_update BEFORE UPDATE ON vault_encryption BEGIN SELECT RAISE(ABORT, 'mirror down'); END");

    $this->service->changePassword($this->vault, VES_PASSWORD, 'a much newer password');

    expect($this->crypto->parseHeader(File::get($this->keyFile))->keyVersion)->toBe(2)
        ->and($this->vault->encryption()->first()->key_version)->toBe(1);

    DB::unprepared('DROP TRIGGER fail_mirror_update');
    newRequest();

    $this->service->unlock($this->vault, 'a much newer password');

    expect($this->vault->encryption()->first()->key_version)->toBe(2)
        ->and($this->vault->encryption()->first()->header_hash)->toBe(hash('sha256', File::get($this->keyFile)));

    foreach ($logs as $line) {
        expect($line)->not->toContain(VES_PASSWORD)->not->toContain('a much newer password');
    }
});

test('the key file on disk holds no password and no data key', function () {
    $bytes = File::get($this->keyFile);
    $material = $this->keys->keyFor($this->vault)->material();

    expect($bytes)->not->toContain(VES_PASSWORD)
        ->and($bytes)->not->toContain($material)
        ->and($bytes)->not->toContain(base64_encode($material))
        ->and($bytes)->not->toContain(bin2hex($material));
});

test('logs never contain the password across the failure paths', function () {
    $logs = captureLogs();
    $wrong = 'Wrong-Canary-Password-55!';
    $original = File::get($this->keyFile);

    // Wrong password.
    try {
        $this->service->unlock($this->vault, $wrong);
    } catch (EncryptionException) {
    }

    // Damaged key file.
    File::put($this->keyFile, 'garbage');
    try {
        $this->service->unlock($this->vault, VES_PASSWORD);
    } catch (EncryptionException) {
    }

    // Database failure during the unlock-time mirror sync.
    File::put($this->keyFile, $original);
    VaultEncryption::query()->update(['header_hash' => str_repeat('0', 64)]);
    DB::unprepared("CREATE TRIGGER fail_mirror_update BEFORE UPDATE ON vault_encryption BEGIN SELECT RAISE(ABORT, 'mirror down'); END");
    $this->service->unlock($this->vault, VES_PASSWORD);

    expect($logs)->not->toBeEmpty();

    foreach ($logs as $line) {
        expect($line)->not->toContain($wrong)->not->toContain(VES_PASSWORD);
    }
});

test('headerAt returns null without a key file, the header with one, and refuses a damaged one', function () {
    $folder = $this->tmp.DIRECTORY_SEPARATOR.'plain';
    File::makeDirectory($folder, 0755, true);

    expect($this->service->headerAt($folder))->toBeNull();

    File::copy($this->keyFile, $folder.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME);

    expect($this->service->headerAt($folder)?->keyId)->toBe($this->vault->encryption->key_id);

    File::put($folder.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, str_repeat('x', 70000));

    expect(fn () => $this->service->headerAt($folder))->toThrow(EncryptionException::class);
});

test('adopt records the mirror and marks the vault encrypted in one transaction', function () {
    $vault = app(VaultService::class)->create('Adopted');
    [$header] = $this->crypto->newVaultKey('adopt password!');
    $bytes = $this->crypto->encodeHeader($header);

    $this->service->adopt($vault, $header, hash('sha256', $bytes));

    $vault->refresh();

    expect($vault->is_encrypted)->toBeTrue()
        ->and($vault->encryption->key_id)->toBe($header->keyId)
        ->and($vault->encryption->header_hash)->toBe(hash('sha256', $bytes));

    // Adopting again updates the single mirror row.
    $this->service->adopt($vault, $header, hash('sha256', $bytes));
    expect(VaultEncryption::query()->where('vault_id', $vault->id)->count())->toBe(1);
});

test('adopt rolls back the mirror when marking the vault fails', function () {
    $vault = app(VaultService::class)->create('Adopted');
    [$header] = $this->crypto->newVaultKey('adopt password!');
    DB::unprepared("CREATE TRIGGER fail_mirror_update BEFORE UPDATE ON vaults BEGIN SELECT RAISE(ABORT, 'vaults down'); END");

    try {
        expect(fn () => $this->service->adopt($vault, $header, str_repeat('a', 64)))->toThrow(QueryException::class);
    } finally {
        DB::unprepared('DROP TRIGGER fail_mirror_update');
    }

    expect(VaultEncryption::query()->where('vault_id', $vault->id)->exists())->toBeFalse();
});

test('ensureHeaderOnDisk re-creates a missing key file from the mirror', function () {
    $original = File::get($this->keyFile);
    File::delete($this->keyFile);

    $this->service->ensureHeaderOnDisk($this->vault);

    expect(File::get($this->keyFile))->toBe($original);

    // A present key file is left alone.
    File::put($this->keyFile, $original."\n");
    $this->service->ensureHeaderOnDisk($this->vault);
    expect(File::get($this->keyFile))->toBe($original."\n");

    File::delete($this->keyFile);
    VaultEncryption::query()->delete();

    expect(fn () => $this->service->ensureHeaderOnDisk($this->vault->refresh()))->toThrow(EncryptionException::class);
});

test('the mirror never serialises its secrets', function () {
    $array = $this->vault->encryption->toArray();

    expect($array)->not->toHaveKeys(['id', 'vault_id', 'salt', 'nonce', 'encrypted_key'])
        ->and($array)->toHaveKeys(['key_id', 'key_version', 'header_hash']);
});

test('a key file rebuilt from the mirror is byte-identical even after the disk copy changed its created_at (7A-QA-05)', function () {
    $data = json_decode(File::get($this->keyFile), true);
    $data['created_at'] = '2031-02-03T04:05:06Z';
    $changed = $this->crypto->encodeHeader($this->crypto->parseHeader(json_encode($data)));
    File::put($this->keyFile, $changed);

    newRequest();
    $this->service->unlock($this->vault->refresh(), VES_PASSWORD);

    expect($this->vault->encryption()->first()->header_hash)->toBe(hash('sha256', $changed));

    File::delete($this->keyFile);
    $this->service->ensureHeaderOnDisk($this->vault->refresh());

    expect(File::get($this->keyFile))->toBe($changed);
});
