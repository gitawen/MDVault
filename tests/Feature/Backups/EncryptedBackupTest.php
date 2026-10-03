<?php

use App\Enums\NoteSaveMode;
use App\Enums\RestoreAction;
use App\Exceptions\BackupException;
use App\Exceptions\EncryptionException;
use App\Models\Note;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\BackupService;
use App\Services\EncryptedNoteService;
use App\Services\EncryptionService;
use App\Services\NoteService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultKeyService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

const BACKUP_CANARY = 'CANARY-CONTENT-7f3a';
const BACKUP_PASSWORD = 'correct horse battery';

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-encbackup-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);
    $this->outside = $this->tmp.DIRECTORY_SEPARATOR.'Outside';
    File::makeDirectory($this->outside, 0755, true);

    $this->service = app(BackupService::class);
    $this->vaults = app(VaultService::class);
    $this->keys = app(VaultKeyService::class);

    [$this->secrets] = encryptedVault('Secrets', BACKUP_PASSWORD);

    $notes = app(NoteService::class);
    $folder = $notes->createFolder($this->secrets, null, 'My Credentials');
    $one = $notes->create($this->secrets, $folder, 'Bank Accounts');
    $notes->save($one, BACKUP_CANARY, $notes->preview($one)['base_hash'], NoteSaveMode::Source);
    $two = $notes->create($this->secrets, null, 'Top Secret Plans');
    $notes->save($two, "line one\r\n".BACKUP_CANARY, $notes->preview($two)['base_hash'], NoteSaveMode::Source);

    $this->plain = $this->vaults->create('Plain');
    writeVaultFiles($this->plain->path, ['Readable.md' => 'plain content']);
    app(VaultIndexService::class)->reindex($this->plain);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * Every note of an encrypted vault, unlocked with $password: logical path =>
 * plaintext bytes.
 *
 * @return array<string, string>
 */
function backupDecryptedNotes(Vault $vault, string $password = BACKUP_PASSWORD): array
{
    app(VaultEncryptionService::class)->unlock($vault, $password);
    $key = app(VaultKeyService::class)->keyFor($vault, false);
    $ns = app(EncryptedNoteService::class)->namespace($vault, $key);
    $crypto = app(EncryptionService::class);
    $result = [];

    foreach ($ns->notes as $note) {
        $bytes = File::get($vault->path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $note['disk']));
        $result[$note['logical']] = $crypto->decryptNote($key, $note['file_id'], $bytes)['content'];
    }

    ksort($result);

    return $result;
}

/**
 * Every entry of a ZIP, with the entry name in each: name => contents (null
 * for a directory entry).
 *
 * @return array<string, ?string>
 */
function backupZipEntries(string $path): array
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::RDONLY);
    $entries = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->statIndex($i)['name'];
        $entries[$name] = str_ends_with($name, '/') ? null : $zip->getFromIndex($i);
    }

    $zip->close();

    return $entries;
}

function makeBackup(string $destination, ?Vault $vault = null): string
{
    app(BackupService::class)->create($vault, $destination);

    return $destination;
}

/**
 * Rewrites a valid backup after $mutate changed its manifest (decoded) and
 * entries, recomputing the manifest totals so only the mutation is wrong.
 */
function tamperedBackup(string $source, string $target, Closure $mutate): string
{
    $entries = backupZipEntries($source);
    $manifest = json_decode((string) $entries['manifest.json'], true);

    $mutate($manifest, $entries);

    $notes = $files = $bytes = 0;

    foreach ($manifest['contents'] as $vault) {
        $notes += count($vault['notes']);
        $files += count($vault['files']);
        $bytes += array_sum(array_column($vault['notes'], 'file_size')) + array_sum(array_column($vault['files'], 'file_size'));
    }

    $manifest['vaults'] = count($manifest['contents']);
    $manifest['notes'] = $notes;
    $manifest['files'] = $files;
    $manifest['total_bytes'] = $bytes;
    $entries['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES);

    makeZip($target, $entries);

    return $target;
}

function encryptedManifestVault(array &$manifest): int
{
    foreach ($manifest['contents'] as $index => $vault) {
        if ($vault['is_encrypted']) {
            return $index;
        }
    }

    throw new RuntimeException('No encrypted vault in the manifest.');
}

test('a backup of a locked encrypted vault copies only ciphertext and writes format 2', function () {
    $this->keys->forgetAll();
    expect($this->keys->isUnlocked($this->secrets))->toBeFalse();

    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'all.zip');
    $entries = backupZipEntries($destination);
    $manifest = json_decode($entries['manifest.json'], true);

    expect($manifest['format_version'])->toBe(2)
        ->and($manifest['vaults'])->toBe(2);

    $byName = collect($manifest['contents'])->keyBy('name');
    $encrypted = $byName['Secrets'];

    expect($encrypted['is_encrypted'])->toBeTrue()
        ->and($encrypted['encryption'])->toBe([
            'format' => 'mdvault-encrypted-vault',
            'format_version' => 1,
            'key_id' => VaultEncryption::query()->value('key_id'),
            'cipher' => 'xchacha20poly1305-ietf',
            'kdf' => 'argon2id13',
        ])
        ->and($byName['Plain']['is_encrypted'])->toBeFalse()
        ->and($byName['Plain']['encryption'])->toBeNull()
        ->and(collect($encrypted['files'])->pluck('relative_path')->contains('mdvault-encryption.json'))->toBeTrue()
        ->and(collect($encrypted['notes'])->every(fn (array $n): bool => preg_match('#^([0-9a-f]{32}/)*[0-9a-f]{32}\.mdenc$#', $n['relative_path']) === 1))->toBeTrue();

    // No name or content of the encrypted vault is in any entry name or body.
    foreach ($entries as $name => $contents) {
        if (str_starts_with($name, 'vaults/Plain') || $name === 'manifest.json') {
            continue;
        }

        foreach ([BACKUP_CANARY, 'Bank Accounts', 'My Credentials', 'Top Secret', 'Plans'] as $needle) {
            expect(stripos($name, $needle))->toBeFalse();
            expect(stripos((string) $contents, $needle))->toBeFalse();
        }
    }

    // The manifest holds no key material, only the non-secret key id.
    $manifestJson = $entries['manifest.json'];
    $row = VaultEncryption::query()->first()->makeVisible(['salt', 'nonce', 'encrypted_key']);

    foreach ([$row->salt, $row->nonce, $row->encrypted_key, 'wrapped_key', 'salt', BACKUP_PASSWORD, BACKUP_CANARY, 'Bank Accounts'] as $needle) {
        expect(stripos($manifestJson, $needle))->toBeFalse();
    }
});

test('a backup never copies plaintext files that were dropped into an encrypted vault', function () {
    File::put($this->secrets->path.DIRECTORY_SEPARATOR.'Dropped.md', 'plain '.BACKUP_CANARY);
    File::makeDirectory($this->secrets->path.DIRECTORY_SEPARATOR.'Photos');
    File::put($this->secrets->path.DIRECTORY_SEPARATOR.'Photos'.DIRECTORY_SEPARATOR.'x.md', 'plain');

    $entries = backupZipEntries(makeBackup($this->outside.DIRECTORY_SEPARATOR.'one.zip', $this->secrets));

    foreach ($entries as $name => $contents) {
        expect(stripos($name, 'Dropped'))->toBeFalse()
            ->and(stripos($name, 'Photos'))->toBeFalse()
            ->and(stripos((string) $contents, BACKUP_CANARY))->toBeFalse();
    }
});

test('a backup recreates a missing key file from the registry mirror', function () {
    File::delete($this->secrets->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME);

    $entries = backupZipEntries(makeBackup($this->outside.DIRECTORY_SEPARATOR.'one.zip', $this->secrets));

    expect($entries)->toHaveKey('vaults/Secrets/mdvault-encryption.json')
        ->and(File::exists($this->secrets->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME))->toBeTrue();
});

test('a vault with no key file and no mirror is refused alone and skipped in a full backup', function () {
    File::delete($this->secrets->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME);
    VaultEncryption::query()->delete();

    expect(fn () => $this->service->create($this->secrets, $this->outside.DIRECTORY_SEPARATOR.'one.zip'))
        ->toThrow(BackupException::class, 'key file');

    $result = $this->service->create(null, $this->outside.DIRECTORY_SEPARATOR.'all.zip');

    expect($result->skippedVaults)->toBe(['Secrets'])
        ->and($result->vaultCount)->toBe(1);
});

test('inspecting a backup reports which vaults are encrypted', function () {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'all.zip');

    $inspection = $this->service->inspect($destination);

    expect($inspection->valid)->toBeTrue()
        ->and($inspection->backup['format_version'])->toBe(2);

    $byName = collect($inspection->vaults)->keyBy('name');

    expect($byName['Secrets']['is_encrypted'])->toBeTrue()
        ->and($byName['Plain']['is_encrypted'])->toBeFalse();
});

test('restoring after a registry reset brings the encrypted vault back locked and openable', function () {
    $expected = backupDecryptedNotes($this->secrets);
    $uuids = Note::query()->where('vault_id', $this->secrets->id)->pluck('uuid')->sort()->values()->all();
    $keyId = VaultEncryption::query()->value('key_id');
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'all.zip');

    $this->vaults->resetRegistry();
    File::deleteDirectory($this->secrets->path);
    File::deleteDirectory($this->plain->path);
    $this->keys->forgetAll();

    $result = $this->service->restore($destination, [
        $this->secrets->uuid => RestoreAction::Restore,
        $this->plain->uuid => RestoreAction::Restore,
    ]);

    expect($result->restored)->toHaveCount(2);

    $restored = Vault::query()->where('uuid', $this->secrets->uuid)->firstOrFail();

    expect($restored->is_encrypted)->toBeTrue()
        ->and(VaultEncryption::query()->where('vault_id', $restored->id)->value('key_id'))->toBe($keyId)
        ->and($this->keys->isUnlocked($restored))->toBeFalse()
        ->and($this->vaults->present($restored)['is_unlocked'])->toBeFalse()
        ->and(Note::query()->where('vault_id', $restored->id)->pluck('uuid')->sort()->values()->all())->toBe($uuids)
        ->and(Note::query()->where('vault_id', $restored->id)->where('is_encrypted', true)->count())->toBe(2);

    expect(fn () => app(VaultEncryptionService::class)->unlock($restored, 'the wrong password'))->toThrow(EncryptionException::class);
    expect(backupDecryptedNotes($restored))->toBe($expected);

    expect(Vault::query()->where('uuid', $this->plain->uuid)->firstOrFail()->is_encrypted)->toBeFalse();
});

test('restoring as a copy gives a new identity that opens with the same password', function () {
    $expected = backupDecryptedNotes($this->secrets);
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'one.zip', $this->secrets);
    $this->keys->forgetAll();

    $result = $this->service->restore($destination, [$this->secrets->uuid => RestoreAction::Copy]);

    $copy = Vault::query()->where('uuid', $result->restored[0]['uuid'])->firstOrFail();

    expect($copy->uuid)->not->toBe($this->secrets->uuid)
        ->and($copy->is_encrypted)->toBeTrue()
        ->and($copy->name)->not->toBe('Secrets')
        ->and($this->keys->isUnlocked($copy))->toBeFalse();

    expect(backupDecryptedNotes($copy))->toBe($expected);
});

test('format 1 archives still restore', function () {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'plain.zip', $this->plain);
    $format1 = tamperedBackup($destination, $this->outside.DIRECTORY_SEPARATOR.'format1.zip', function (array &$manifest): void {
        $manifest['format_version'] = 1;
    });

    $this->vaults->remove($this->plain);
    File::deleteDirectory($this->plain->path);

    $inspection = $this->service->inspect($format1);

    expect($inspection->valid)->toBeTrue()
        ->and($inspection->backup['format_version'])->toBe(1);

    $this->service->restore($format1, [$this->plain->uuid => RestoreAction::Restore]);

    expect(File::get($this->plain->path.DIRECTORY_SEPARATOR.'Readable.md'))->toBe('plain content');
});

test('a hostile encrypted manifest is refused and nothing is written', function (Closure $mutate) {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'one.zip', $this->secrets);
    $hostile = tamperedBackup($destination, $this->outside.DIRECTORY_SEPARATOR.'hostile.zip', $mutate);

    $this->vaults->remove($this->secrets);
    File::deleteDirectory($this->secrets->path);
    $vaultsBefore = Vault::query()->count();
    $rootBefore = array_diff(scandir(dirname($this->plain->path)) ?: [], ['.', '..']);

    $inspection = $this->service->inspect($hostile);

    expect($inspection->valid)->toBeFalse()
        ->and($inspection->problems)->not->toBeEmpty();

    expect(fn () => $this->service->restore($hostile, [$this->secrets->uuid => RestoreAction::Restore]))
        ->toThrow(BackupException::class);

    expect(Vault::query()->count())->toBe($vaultsBefore)
        ->and(VaultEncryption::query()->count())->toBe(0)
        ->and(array_diff(scandir(dirname($this->plain->path)) ?: [], ['.', '..']))->toBe($rootBefore);
})->with([
    'no key file' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $manifest['contents'][$i]['files'] = array_values(array_filter($manifest['contents'][$i]['files'], fn (array $f): bool => $f['relative_path'] !== 'mdvault-encryption.json'));
        unset($entries['vaults/Secrets/mdvault-encryption.json']);
    }],
    'a plaintext-named note' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $old = $manifest['contents'][$i]['notes'][0]['relative_path'];
        $manifest['contents'][$i]['notes'][0]['relative_path'] = 'Bank Accounts.mdenc';
        $entries['vaults/Secrets/Bank Accounts.mdenc'] = $entries['vaults/Secrets/'.$old];
        unset($entries['vaults/Secrets/'.$old]);
    }],
    'a plain markdown note path' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $old = $manifest['contents'][$i]['notes'][0]['relative_path'];
        $new = str_repeat('a', 32).'.md';
        $manifest['contents'][$i]['notes'][0]['relative_path'] = $new;
        $entries['vaults/Secrets/'.$new] = $entries['vaults/Secrets/'.$old];
        unset($entries['vaults/Secrets/'.$old]);
    }],
    'a mismatched key id' => [function (array &$manifest): void {
        $manifest['contents'][encryptedManifestVault($manifest)]['encryption']['key_id'] = '11111111-1111-4111-8111-111111111111';
    }],
    'a damaged key file' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $garbage = '{"not":"a key file"}';
        $entries['vaults/Secrets/mdvault-encryption.json'] = $garbage;

        foreach ($manifest['contents'][$i]['files'] as &$file) {
            if ($file['relative_path'] === 'mdvault-encryption.json') {
                $file['file_size'] = strlen($garbage);
                $file['file_hash'] = hash('sha256', $garbage);
            }
        }
    }],
    'an extra unknown file' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $manifest['contents'][$i]['files'][] = ['relative_path' => 'Extra.txt', 'file_size' => 5, 'file_hash' => hash('sha256', 'extra'), 'modified_at' => null];
        $entries['vaults/Secrets/Extra.txt'] = 'extra';
    }],
    'a non-hex folder' => [function (array &$manifest, array &$entries): void {
        $i = encryptedManifestVault($manifest);
        $manifest['contents'][$i]['directories'][] = 'Plain Folder';
        $entries['vaults/Secrets/Plain Folder/'] = null;
    }],
    'encryption details with an extra key' => [function (array &$manifest): void {
        $manifest['contents'][encryptedManifestVault($manifest)]['encryption']['salt'] = 'AAAA';
    }],
    'encrypted flag without details' => [function (array &$manifest): void {
        $manifest['contents'][encryptedManifestVault($manifest)]['encryption'] = null;
    }],
    'an encrypted vault in a format 1 archive' => [function (array &$manifest): void {
        $manifest['format_version'] = 1;
    }],
]);

test('a format 2 manifest for a plain vault may not carry encryption details', function () {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'plain.zip', $this->plain);
    $hostile = tamperedBackup($destination, $this->outside.DIRECTORY_SEPARATOR.'hostile.zip', function (array &$manifest): void {
        $manifest['contents'][0]['encryption'] = ['format' => 'mdvault-encrypted-vault'];
    });

    expect($this->service->inspect($hostile)->valid)->toBeFalse();
});

test('a plain vault may not carry encryption files', function (Closure $mutate) {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'plain.zip', $this->plain);
    $hostile = tamperedBackup($destination, $this->outside.DIRECTORY_SEPARATOR.'hostile.zip', $mutate);

    $inspection = $this->service->inspect($hostile);

    expect($inspection->valid)->toBeFalse()
        ->and(implode(' ', $inspection->problems))->toContain("isn't encrypted");

    $this->vaults->remove($this->plain);
    File::deleteDirectory($this->plain->path);

    expect(fn () => $this->service->restore($hostile, [$this->plain->uuid => RestoreAction::Restore]))->toThrow(BackupException::class);
    expect(File::isDirectory($this->plain->path))->toBeFalse();
})->with([
    'a key file' => [function (array &$manifest, array &$entries): void {
        $body = '{"format":"mdvault-encrypted-vault"}';
        $manifest['contents'][0]['files'][] = ['relative_path' => 'mdvault-encryption.json', 'file_size' => strlen($body), 'file_hash' => hash('sha256', $body), 'modified_at' => null];
        $entries['vaults/Plain/mdvault-encryption.json'] = $body;
    }],
    'an mdenc file' => [function (array &$manifest, array &$entries): void {
        $body = 'ciphertext';
        $manifest['contents'][0]['files'][] = ['relative_path' => str_repeat('a', 32).'.mdenc', 'file_size' => strlen($body), 'file_hash' => hash('sha256', $body), 'modified_at' => null];
        $entries['vaults/Plain/'.str_repeat('a', 32).'.mdenc'] = $body;
    }],
]);

test('an archive with duplicate entry names is refused', function () {
    $destination = makeBackup($this->outside.DIRECTORY_SEPARATOR.'plain.zip', $this->plain);
    $withTwin = tamperedBackup($destination, $this->outside.DIRECTORY_SEPARATOR.'twin.zip', function (array &$manifest, array &$entries): void {
        $entries['vaults/Plain/Readablf.md'] = 'second body';
    });

    $bytes = (string) file_get_contents($withTwin);
    expect(substr_count($bytes, 'Readablf.md'))->toBe(2);
    $duplicate = $this->outside.DIRECTORY_SEPARATOR.'duplicate.zip';
    file_put_contents($duplicate, str_replace('Readablf.md', 'Readable.md', $bytes));

    $inspection = $this->service->inspect($duplicate);

    expect($inspection->valid)->toBeFalse()
        ->and($inspection->problems)->not->toBeEmpty();
});
