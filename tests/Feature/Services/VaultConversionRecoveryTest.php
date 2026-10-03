<?php

use App\Models\Vault;
use App\Services\FileStorageService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultRecoveryService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-recover-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->recovery = app(VaultRecoveryService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function recPlainVault(string $name = 'Recover'): Vault
{
    $vault = app(VaultService::class)->create($name);
    writeVaultFiles($vault->path, ['Keep.md' => 'original note', 'Folder/Deep.md' => 'deep']);
    app(VaultIndexService::class)->reindex($vault);

    return $vault->refresh();
}

function recTree(string $root): array
{
    $tree = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
        $tree[str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1))] = $item->isFile() ? file_get_contents($item->getPathname()) : 'dir';
    }

    ksort($tree);

    return $tree;
}

function recSiblings(Vault $vault): array
{
    return array_values(array_filter(
        array_diff(scandir(dirname($vault->path)) ?: [], ['.', '..']),
        fn (string $name): bool => str_starts_with($name, '.mdvault-'),
    ));
}

test('a crash between the two renames restores the original', function () {
    $vault = recPlainVault();
    $original = $this->recovery->originalPath($vault);
    $staging = $this->recovery->stagingPath($vault, true);
    $expected = recTree($vault->path);

    rename($vault->path, $original);
    File::makeDirectory($staging);
    File::put($staging.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, 'staged key file');

    expect($this->recovery->recover($vault))->toBe('restored-original')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([])
        ->and($vault->refresh()->is_encrypted)->toBeFalse();

    // Idempotent.
    expect($this->recovery->recover($vault))->toBeNull()
        ->and(recTree($vault->path))->toBe($expected);
});

test('both renames done but never committed are undone (encryption)', function () {
    $vault = recPlainVault();
    $expected = recTree($vault->path);
    $original = $this->recovery->originalPath($vault);

    rename($vault->path, $original);
    File::makeDirectory($vault->path);
    File::put($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, 'converted copy');
    File::put($vault->path.DIRECTORY_SEPARATOR.str_repeat('a', 32).'.mdenc', 'ciphertext');

    expect($this->recovery->recover($vault))->toBe('rolled-back')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([]);

    expect($this->recovery->recover($vault))->toBeNull()
        ->and(recTree($vault->path))->toBe($expected);
});

test('both renames done but never committed are undone (decryption)', function () {
    [$vault] = encryptedVault('RecoverEnc');
    File::put($vault->path.DIRECTORY_SEPARATOR.str_repeat('b', 32).'.mdenc', 'ciphertext');
    $expected = recTree($vault->path);
    $original = $this->recovery->originalPath($vault);

    // The decrypted copy is in place, but the registry still says encrypted.
    rename($vault->path, $original);
    File::makeDirectory($vault->path);
    File::put($vault->path.DIRECTORY_SEPARATOR.'Plain.md', 'plaintext copy');

    expect($this->recovery->recover($vault))->toBe('rolled-back')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([])
        ->and($vault->refresh()->is_encrypted)->toBeTrue();
});

test('a committed encryption whose cleanup did not finish has its plaintext original deleted', function () {
    [$vault] = encryptedVault('RecoverCommitted');
    $expected = recTree($vault->path);
    $original = $this->recovery->originalPath($vault);

    File::makeDirectory($original.DIRECTORY_SEPARATOR.'Folder', 0755, true);
    File::put($original.DIRECTORY_SEPARATOR.'Plain.md', 'CANARY plaintext');
    File::put($original.DIRECTORY_SEPARATOR.'Folder'.DIRECTORY_SEPARATOR.'Deep.md', 'deep');

    expect($this->recovery->recover($vault))->toBe('removed-original')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([]);

    expect($this->recovery->recover($vault))->toBeNull();
});

test('a committed decryption whose cleanup did not finish has its encrypted original deleted', function () {
    $vault = recPlainVault('RecoverDecrypted');
    $expected = recTree($vault->path);
    $original = $this->recovery->originalPath($vault);

    File::makeDirectory($original);
    File::put($original.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, 'old key file');
    File::put($original.DIRECTORY_SEPARATOR.str_repeat('c', 32).'.mdenc', 'ciphertext');

    expect($this->recovery->recover($vault))->toBe('removed-original')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([]);
});

test('a crash while staging leaves the vault alone and deletes the staging folder', function (bool $encrypting) {
    $vault = recPlainVault();
    $expected = recTree($vault->path);
    $staging = $this->recovery->stagingPath($vault, $encrypting);

    File::makeDirectory($staging.DIRECTORY_SEPARATOR.'nested', 0755, true);
    File::put($staging.DIRECTORY_SEPARATOR.'nested'.DIRECTORY_SEPARATOR.'half.mdenc', 'partial');

    expect($this->recovery->recover($vault))->toBe('removed-staging')
        ->and(recTree($vault->path))->toBe($expected)
        ->and(recSiblings($vault))->toBe([]);

    expect($this->recovery->recover($vault))->toBeNull();
})->with([true, false]);

test('a plain registry row over a folder that holds a key file is left for the user', function () {
    $vault = recPlainVault();
    File::put($vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME, 'copied in by hand');
    $expected = recTree($vault->path);

    expect($this->recovery->recover($vault))->toBeNull()
        ->and(recTree($vault->path))->toBe($expected);
});

test('nothing happens when there is no conversion folder', function () {
    $vault = recPlainVault();
    $expected = recTree($vault->path);

    expect($this->recovery->recover($vault))->toBeNull()
        ->and(recTree($vault->path))->toBe($expected);
});

test('a conversion in flight is never repaired underneath itself', function () {
    $vault = recPlainVault();
    $original = $this->recovery->originalPath($vault);
    rename($vault->path, $original);

    $release = $this->recovery->lock($vault);
    $held = $this->recovery->lock($vault);

    try {
        if ($held === null) {
            expect($this->recovery->recover($vault))->toBeNull()
                ->and(File::isDirectory($vault->path))->toBeFalse();
        } else {
            $held();
        }
    } finally {
        $release();
    }

    expect($this->recovery->recover($vault))->toBe('restored-original');
});

test('opening a vault and refreshing a missing vault run recovery', function () {
    $vault = recPlainVault();
    $expected = recTree($vault->path);
    rename($vault->path, $this->recovery->originalPath($vault));

    $refreshed = app(VaultService::class)->refreshStatus($vault);

    expect($refreshed->status->value)->toBe('active')
        ->and(recTree($vault->path))->toBe($expected);

    $second = recPlainVault('RecoverOpen');
    $expectedSecond = recTree($second->path);
    $staging = $this->recovery->stagingPath($second, true);
    File::makeDirectory($staging);

    app(VaultService::class)->open($second);

    expect(File::isDirectory($staging))->toBeFalse()
        ->and(recTree($second->path))->toBe($expectedSecond);
});

test('recovery only ever deletes folders with the conversion prefixes of this vault', function () {
    $vault = recPlainVault();
    $bystander = dirname($vault->path).DIRECTORY_SEPARATOR.FileStorageService::RESTORE_STAGING_PREFIX.$vault->uuid;
    File::makeDirectory($bystander);
    $sibling = dirname($vault->path).DIRECTORY_SEPARATOR.FileStorageService::CONVERSION_ORIGINAL_PREFIX.'other';
    File::makeDirectory($sibling);

    expect($this->recovery->recover($vault))->toBeNull()
        ->and(File::isDirectory($bystander))->toBeTrue()
        ->and(File::isDirectory($sibling))->toBeTrue();
});

test('recoverAll repairs every vault and keeps going after a failure', function () {
    $first = recPlainVault('RecoverOne');
    $second = recPlainVault('RecoverTwo');
    $expectedFirst = recTree($first->path);
    $expectedSecond = recTree($second->path);

    rename($first->path, $this->recovery->originalPath($first));
    File::makeDirectory($this->recovery->stagingPath($second, false));

    expect($this->recovery->recoverAll())->toBe(2)
        ->and(recTree($first->path))->toBe($expectedFirst)
        ->and(recTree($second->path))->toBe($expectedSecond)
        ->and(recSiblings($first))->toBe([])
        ->and($this->recovery->recoverAll())->toBe(0);
});

test('a lock left by a crash is released at boot so recovery repairs the vault', function () {
    config(['cache.default' => 'database']);

    $vault = recPlainVault();
    $expected = recTree($vault->path);
    rename($vault->path, $this->recovery->originalPath($vault));

    // The crashed process took the lock and never released it; the database
    // cache store keeps the row, so a normal recovery is blocked.
    expect($this->recovery->lock($vault))->not->toBeNull()
        ->and($this->recovery->recover($vault))->toBeNull()
        ->and(File::isDirectory($vault->path))->toBeFalse();

    expect($this->recovery->recoverAll())->toBe(1)
        ->and(recTree($vault->path))->toBe($expected);

    // The lock is free again afterwards.
    $again = $this->recovery->lock($vault);
    expect($again)->not->toBeNull();
    $again->release();
});

test('only the owner of a conversion lock can release it', function () {
    config(['cache.default' => 'database']);

    $vault = recPlainVault();
    $first = $this->recovery->lock($vault);

    expect($first)->not->toBeNull()
        ->and($first->isHeld())->toBeTrue()
        ->and($this->recovery->lock($vault))->toBeNull();

    // Boot cleanup frees the row; a second owner takes it.
    app('cache')->store()->getStore()->lock(VaultRecoveryService::LOCK_PREFIX.$vault->uuid, 1)->forceRelease();
    $second = $this->recovery->lock($vault);

    expect($second)->not->toBeNull()
        ->and($first->isHeld())->toBeFalse();

    $first->release();

    expect($second->isHeld())->toBeTrue();
    $second->release();
});
