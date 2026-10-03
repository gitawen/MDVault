<?php

use App\Enums\IndexMode;
use App\Enums\SettingKey;
use App\Enums\VaultStatus;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Models\VaultEncryption;
use App\Services\SettingsService;
use App\Services\StoragePathService;
use App\Services\VaultEncryptionService;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-registry-reset-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
    $this->settings = app(SettingsService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

/**
 * Every file path under $root mapped to its SHA-256 (a directory-only entry
 * maps to null), so a before/after comparison catches any filesystem change
 * (FR-02).
 *
 * @return array<string, ?string>
 */
function snapshotTree(string $root): array
{
    if (! is_dir($root)) {
        return [];
    }

    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = str_replace($root.DIRECTORY_SEPARATOR, '', $item->getPathname());
        $snapshot[$relative] = $item->isDir() ? null : hash_file('sha256', $item->getPathname());
    }

    return $snapshot;
}

test('resetRegistry deletes all vault and note rows and returns the counts', function () {
    $active = $this->vaults->create('Work');
    writeVaultFiles($active->path, ['A.md' => 'hello']);
    $this->index->reconcile($active, IndexMode::Full);

    $missing = $this->vaults->create('Personal');
    writeVaultFiles($missing->path, ['B.md' => 'world', 'C.md' => 'more']);
    $this->index->reconcile($missing, IndexMode::Full);
    File::deleteDirectory($missing->path);
    $this->vaults->refreshStatus($missing);

    expect(Vault::count())->toBe(2)
        ->and(Note::count())->toBe(3)
        ->and($missing->fresh()->status)->toBe(VaultStatus::Missing);

    $result = $this->vaults->resetRegistry();

    expect($result->vaults)->toBe(2)
        ->and($result->missingVaults)->toBe(1)
        ->and($result->notes)->toBe(3)
        ->and(Vault::count())->toBe(0)
        ->and(Note::count())->toBe(0);
});

test('resetRegistry forgets the current vault but leaves other settings alone', function () {
    app(StoragePathService::class)->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'custom-root', 'MyVault');
    $this->settings->set(SettingKey::AppearanceTheme, 'dark');
    $this->settings->set(SettingKey::StorageFolderName, 'MyVault');
    $this->settings->set(SettingKey::EditorFontSize, 20);

    $vault = $this->vaults->create('Work');
    $this->vaults->open($vault);

    expect($this->settings->string(SettingKey::CurrentVault))->toBe($vault->uuid);

    $this->vaults->resetRegistry();

    expect($this->settings->string(SettingKey::CurrentVault))->toBeNull()
        ->and($this->settings->string(SettingKey::AppearanceTheme))->toBe('dark')
        ->and($this->settings->string(SettingKey::StorageFolderName))->toBe('MyVault')
        ->and($this->settings->integer(SettingKey::EditorFontSize))->toBe(20)
        ->and(app(StoragePathService::class)->folderName())->toBe('MyVault');
});

test('resetRegistry never touches backup history', function () {
    Backup::factory()->count(2)->create();

    $this->vaults->resetRegistry();

    expect(Backup::count())->toBe(2);
});

test('resetRegistry never touches the filesystem', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, [
        'Notes/Nested/Deep.md' => 'deep note',
        'image.png' => 'not markdown',
        'Empty/' => '',
    ]);
    $this->index->reconcile($vault, IndexMode::Full);

    // An unregistered folder and a leftover restore-staging folder must
    // survive untouched too.
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'Unregistered', 0755, true);
    File::put($this->root.DIRECTORY_SEPARATOR.'Unregistered'.DIRECTORY_SEPARATOR.'file.txt', 'stray');
    File::makeDirectory($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-abc', 0755, true);
    File::put($this->root.DIRECTORY_SEPARATOR.'.mdvault-restore-abc'.DIRECTORY_SEPARATOR.'staged.md', 'staged');

    $before = snapshotTree($this->tmp);

    $this->vaults->resetRegistry();

    $after = snapshotTree($this->tmp);

    expect($after)->toEqual($before)
        ->and(is_dir($this->root))->toBeTrue();
});

test('resetRegistry on an empty registry succeeds with all-zero counts', function () {
    $result = $this->vaults->resetRegistry();

    expect($result->vaults)->toBe(0)
        ->and($result->missingVaults)->toBe(0)
        ->and($result->notes)->toBe(0);
});

test('resetRegistry is atomic: a DB failure leaves everything unchanged', function () {
    $vault = $this->vaults->create('Work');
    writeVaultFiles($vault->path, ['A.md' => 'hello']);
    $this->index->reconcile($vault, IndexMode::Full);
    $this->vaults->open($vault);

    DB::statement("CREATE TRIGGER fail_vault_delete BEFORE DELETE ON vaults BEGIN SELECT RAISE(ABORT, 'boom'); END");

    expect(fn () => $this->vaults->resetRegistry())->toThrow(QueryException::class);

    expect(Vault::count())->toBe(1)
        ->and(Note::count())->toBe(1)
        ->and($this->settings->string(SettingKey::CurrentVault))->toBe($vault->uuid);
});

test('resetRegistry removes vault_encryption rows and never touches the key file', function () {
    [$vault] = encryptedVault('Secrets');
    $keyFile = $vault->path.DIRECTORY_SEPARATOR.VaultEncryptionService::HEADER_FILENAME;
    $bytes = File::get($keyFile);

    expect(VaultEncryption::count())->toBe(1);

    $this->vaults->resetRegistry();

    expect(VaultEncryption::count())->toBe(0)
        ->and(Vault::count())->toBe(0)
        ->and(File::get($keyFile))->toBe($bytes);
});
