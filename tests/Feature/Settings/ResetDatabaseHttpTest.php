<?php

use App\Enums\IndexMode;
use App\Models\Backup;
use App\Models\Note;
use App\Models\Vault;
use App\Services\VaultIndexService;
use App\Services\VaultService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-reset-http-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
    $this->root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';

    $this->vaults = app(VaultService::class);
    $this->index = app(VaultIndexService::class);
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

function httpVaultWithNote(): Vault
{
    $vault = test()->vaults->create('Work');
    writeVaultFiles($vault->path, ['A.md' => 'hello']);
    test()->index->reconcile($vault, IndexMode::Full);

    return $vault;
}

test('a successful reset redirects to settings.backup.edit with a success toast and clears the tables', function () {
    httpVaultWithNote();

    $response = $this->delete(route('settings.backup.database.destroy'), ['confirmation' => 'RESET']);

    $response->assertRedirect(route('settings.backup.edit'));

    $flash = session('inertia.flash_data');
    expect($flash['toast']['type'])->toBe('success')
        ->and($flash['toast']['message'])->toContain('No files were deleted');

    expect(Vault::count())->toBe(0)
        ->and(Note::count())->toBe(0);
});

test('a wrong or missing confirmation fails validation and changes nothing', function (?string $confirmation) {
    httpVaultWithNote();

    $params = $confirmation === null ? [] : ['confirmation' => $confirmation];

    $this->delete(route('settings.backup.database.destroy'), $params)
        ->assertSessionHasErrors('confirmation');

    expect(Vault::count())->toBe(1)
        ->and(Note::count())->toBe(1);
})->with([
    'missing' => [null],
    'empty' => [''],
    'lowercase' => ['reset'],
    'trailing junk' => ['RESETX'],
    'mixed case' => ['Reset'],
]);

test('after a reset, the backup page shows no vaults but keeps backup history', function () {
    httpVaultWithNote();
    Backup::factory()->count(2)->create();

    $this->delete(route('settings.backup.database.destroy'), ['confirmation' => 'RESET'])
        ->assertRedirect(route('settings.backup.edit'));

    $response = $this->get(route('settings.backup.edit'));

    $response->assertInertia(fn ($page) => $page
        ->where('vaults', [])
        ->has('backups', 2));
});

test('a DB failure gives an error toast and leaves the rows intact', function () {
    httpVaultWithNote();

    DB::statement("CREATE TRIGGER fail_vault_delete BEFORE DELETE ON vaults BEGIN SELECT RAISE(ABORT, 'boom'); END");

    $response = $this->delete(route('settings.backup.database.destroy'), ['confirmation' => 'RESET']);

    $response->assertRedirect();

    $flash = session('inertia.flash_data');
    expect($flash['toast']['type'])->toBe('error')
        ->and($flash['toast']['message'])->toContain('Nothing was changed');

    expect(Vault::count())->toBe(1)
        ->and(Note::count())->toBe(1);
});

test('a GET to the reset endpoint is not allowed', function () {
    $this->get('/settings/backup/database')->assertStatus(405);
});
