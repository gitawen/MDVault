<?php

use App\Enums\IndexMode;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-backup-http-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    File::makeDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents', 0755, true);
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    $this->backupDir = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault Backups';

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function httpWorkVault(): Vault
{
    $vault = test()->vaults->create('Work');
    writeVaultFiles($vault->path, ['A.md' => 'content']);
    test()->index->reconcile($vault, IndexMode::Full);

    return $vault;
}

// --- edit page ------------------------------------------------------------------

test('the backup page shows recent backups, the default directory and canBrowse false in browser mode', function () {
    $response = $this->get(route('settings.backup.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Backup')
        ->has('backups')
        ->where('canBrowse', false)
        ->where('defaultDirectory', fn (string $dir) => str_ends_with($dir, 'MDVault Backups')));
});

test('canBrowse is true in the desktop runtime', function () {
    config(['nativephp-internal.running' => true]);

    $response = $this->get(route('settings.backup.edit'));

    $response->assertInertia(fn ($page) => $page->where('canBrowse', true));
});

test('recent backups never expose an id', function () {
    httpWorkVault();
    $this->post(route('settings.backup.store'), []);

    $response = $this->get(route('settings.backup.edit'));

    $response->assertInertia(fn ($page) => $page->where('backups.0.uuid', fn ($uuid) => is_string($uuid)));
    $response->assertDontSee('"id"', false);
});

// --- store (browser mode) --------------------------------------------------------

test('storing a backup in browser mode writes the file into the default folder and records it', function () {
    httpWorkVault();

    $this->post(route('settings.backup.store'), [])->assertRedirect();

    expect(Backup::count())->toBe(1);
    $row = Backup::first();
    expect(File::exists($row->path))->toBeTrue()
        ->and(dirname($row->path))->toBe($this->backupDir);
});

test('storing a backup for a single vault includes only that vault', function () {
    $vault = httpWorkVault();

    $this->post(route('settings.backup.store'), ['vault' => $vault->uuid])->assertRedirect();

    $row = Backup::first();
    expect($row->scope->value)->toBe('vault')
        ->and($row->vault_count)->toBe(1);
});

test('an unknown vault UUID gives a validation error', function () {
    httpWorkVault();

    $this->post(route('settings.backup.store'), ['vault' => (string) Str::uuid()])
        ->assertSessionHasErrors('vault');

    expect(Backup::count())->toBe(0);
});

// --- store (desktop mode) --------------------------------------------------------

test('the desktop path saves to the dialog-chosen destination', function () {
    httpWorkVault();
    config(['nativephp-internal.running' => true]);
    $chosen = $this->tmp.DIRECTORY_SEPARATOR.'chosen.zip';

    Http::fake(['*dialog/save' => Http::response(['result' => $chosen])]);

    $this->post(route('settings.backup.store'), [])->assertRedirect();

    expect(File::exists($chosen))->toBeTrue();
});

test('cancelling the desktop save dialog writes no file and no record', function () {
    httpWorkVault();
    config(['nativephp-internal.running' => true]);

    Http::fake(['*dialog/save' => Http::response(['result' => null])]);

    $this->post(route('settings.backup.store'), [])->assertRedirect();

    expect(Backup::count())->toBe(0);
});

test('choosing an existing file gives an error toast and leaves the file unchanged', function () {
    httpWorkVault();
    config(['nativephp-internal.running' => true]);
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'existing.zip';
    File::put($existing, 'do not touch');

    Http::fake(['*dialog/save' => Http::response(['result' => $existing])]);

    $this->post(route('settings.backup.store'), [])->assertRedirect();

    expect(File::get($existing))->toBe('do not touch')
        ->and(Backup::count())->toBe(0);
});

// --- browse -------------------------------------------------------------------------

test('browse is not found outside the desktop runtime', function () {
    $this->post(route('settings.backup.restore.browse'))->assertNotFound();
});

test('browse in the desktop runtime flashes the picked backup path', function () {
    config(['nativephp-internal.running' => true]);
    $zip = $this->tmp.DIRECTORY_SEPARATOR.'picked.zip';
    File::put($zip, 'x');

    Http::fake(['*dialog/open' => Http::response(['result' => [$zip]])]);

    $response = $this->post(route('settings.backup.restore.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlash('pickedBackup.path', $zip);
});

// --- inspect ------------------------------------------------------------------------

test('inspect of a valid backup returns 200 with the exact top-level keys', function () {
    $vault = httpWorkVault();
    $this->post(route('settings.backup.store'), []);
    $path = Backup::first()->path;

    $response = $this->postJson(route('settings.backup.restore.inspect'), ['path' => $path]);

    $response->assertOk();
    $response->assertJsonStructure(['valid', 'problems', 'backup', 'vaults']);
    expect(array_keys($response->json()))->toEqualCanonicalizing(['valid', 'problems', 'backup', 'vaults']);
});

test('inspect of a tampered archive returns 200 with valid false', function () {
    $vault = httpWorkVault();
    $this->post(route('settings.backup.store'), []);
    $path = Backup::first()->path;

    $zip = new ZipArchive;
    $zip->open($path);
    $zip->deleteName('vaults/Work/A.md');
    $zip->close();
    $zip->open($path);
    $zip->addFromString('vaults/Work/A.md', 'tampered!!');
    $zip->close();

    $response = $this->postJson(route('settings.backup.restore.inspect'), ['path' => $path]);

    $response->assertOk()->assertJson(['valid' => false]);
});

test('inspect of a missing path returns 422 on path', function () {
    $this->postJson(route('settings.backup.restore.inspect'), ['path' => $this->tmp.DIRECTORY_SEPARATOR.'nope.zip'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('path');
});

test('a GET to inspect is not allowed', function () {
    $this->get(route('settings.backup.restore.inspect'))->assertStatus(405);
});

// --- restore ------------------------------------------------------------------------

test('restore validation: no vaults, all skip, a bad action, and a non-uuid key', function () {
    $vault = httpWorkVault();
    $this->post(route('settings.backup.store'), []);
    $path = Backup::first()->path;

    $this->post(route('settings.backup.restore.store'), ['path' => $path, 'vaults' => []])
        ->assertSessionHasErrors('vaults');

    $this->post(route('settings.backup.restore.store'), ['path' => $path, 'vaults' => [['uuid' => (string) Str::uuid(), 'action' => 'skip']]])
        ->assertSessionHasErrors('vaults');

    $this->post(route('settings.backup.restore.store'), ['path' => $path, 'vaults' => [['uuid' => (string) Str::uuid(), 'action' => 'nonsense']]])
        ->assertSessionHasErrors('vaults.0.action');

    $this->post(route('settings.backup.restore.store'), ['path' => $path, 'vaults' => [['uuid' => 'not-a-uuid', 'action' => 'restore']]])
        ->assertSessionHasErrors('vaults.0.uuid');
});

test('a successful restore redirects to vaults.index with a toast and creates rows', function () {
    $vault = httpWorkVault();
    $this->post(route('settings.backup.store'), []);
    $backupPath = Backup::first()->path;

    Note::query()->delete();
    Vault::query()->delete();
    File::deleteDirectory($this->root);

    $response = $this->post(route('settings.backup.restore.store'), [
        'path' => $backupPath,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'restore']],
    ]);

    $response->assertRedirect(route('vaults.index'));
    expect(Vault::query()->where('uuid', $vault->uuid)->exists())->toBeTrue();
});

test('restoring a registered vault UUID with "restore" gives a session error on path', function () {
    $vault = httpWorkVault();
    $this->post(route('settings.backup.store'), []);
    $backupPath = Backup::first()->path;

    $this->post(route('settings.backup.restore.store'), [
        'path' => $backupPath,
        'vaults' => [['uuid' => $vault->uuid, 'action' => 'restore']],
    ])->assertSessionHasErrors('path');
});
