<?php

use App\Enums\SettingKey;
use App\Models\Note;
use App\Models\Vault;
use App\Services\SettingsService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-vault-http-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the vaults index shows the storage root and capability flags', function () {
    $response = $this->get(route('vaults.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('vaults/Index')
        ->where('canBrowse', false)
        ->where('canTrash', false)
        ->where('vaults', []));

    expect($response->inertiaPage()['props']['storageRoot'])->toEndWith('MDVault');
});

test('storing a vault creates it, opens it and redirects to the workspace', function () {
    $response = $this->post(route('vaults.store'), ['name' => 'Work', 'description' => 'd']);

    $response->assertRedirect(route('workspace'));
    $response->assertInertiaFlash('toast.type', 'success');

    $vault = Vault::query()->sole();

    expect(is_dir($vault->path))->toBeTrue()
        ->and(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid);
});

test('storing an invalid name fails validation and creates nothing', function (string $name) {
    $this->post(route('vaults.store'), ['name' => $name])
        ->assertSessionHasErrors('name');

    expect(Vault::count())->toBe(0);
})->with([
    'reserved' => ['CON'],
    'slash' => ['a/b'],
    'empty' => [''],
]);

test('storing a duplicate name fails validation', function () {
    $this->post(route('vaults.store'), ['name' => 'Work'])->assertRedirect();

    $this->post(route('vaults.store'), ['name' => 'Work'])
        ->assertSessionHasErrors('name');
});

test('updating a vault changes its name, renames the folder, and a duplicate name fails validation', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    $workPath = $vault->path;
    $officePath = dirname($workPath).DIRECTORY_SEPARATOR.'Office';

    $this->patch(route('vaults.update', $vault->uuid), ['name' => 'Office'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Vault renamed. Its folder is now '.realpath($officePath).'.');

    expect($vault->fresh()->name)->toBe('Office')
        ->and($vault->fresh()->path)->toBe(realpath($officePath))
        ->and(is_dir($officePath))->toBeTrue()
        ->and(is_dir($workPath))->toBeFalse();

    $this->post(route('vaults.store'), ['name' => 'Second']);
    $second = Vault::query()->where('uuid', '!=', $vault->uuid)->sole();

    $this->patch(route('vaults.update', $vault->uuid), ['name' => $second->name])
        ->assertSessionHasErrors('name');
});

test('updating only the description keeps the folder and shows a plain toast', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    $path = $vault->path;

    $this->patch(route('vaults.update', $vault->uuid), ['name' => 'Work', 'description' => 'new'])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Vault updated.');

    expect($vault->fresh()->description)->toBe('new')
        ->and($vault->fresh()->path)->toBe($path)
        ->and(is_dir($path))->toBeTrue();
});

test('updating a vault to a name whose folder already exists fails validation', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    File::makeDirectory(dirname($vault->path).DIRECTORY_SEPARATOR.'Office');

    $this->patch(route('vaults.update', $vault->uuid), ['name' => 'Office'])
        ->assertSessionHasErrors('name');
});

test('updating the name of a vault whose folder was deleted fails validation', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    File::deleteDirectory($vault->path);

    $this->patch(route('vaults.update', $vault->uuid), ['name' => 'Office'])
        ->assertSessionHasErrors('name');
});

test('destroying a vault without the trash flag unregisters it and keeps the folder', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();

    $response = $this->delete(route('vaults.destroy', $vault->uuid));

    $response->assertRedirect(route('vaults.index'));
    expect(Vault::count())->toBe(0)
        ->and(is_dir($vault->path))->toBeTrue();
});

test('destroying with move_to_trash outside the desktop runtime fails validation', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();

    $this->delete(route('vaults.destroy', $vault->uuid), ['move_to_trash' => true])
        ->assertSessionHasErrors('move_to_trash');

    expect(Vault::count())->toBe(1);
});

test('destroying with move_to_trash after a successful trash removes the folder and the record', function () {
    fakeTrash();
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    $path = $vault->path;

    $this->delete(route('vaults.destroy', $vault->uuid), ['move_to_trash' => true])
        ->assertRedirect(route('vaults.index'));

    expect(Vault::count())->toBe(0)
        ->and(is_dir($path))->toBeFalse();
});

test('opening a vault redirects to the workspace, and opening a missing one flashes an error', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();

    $this->post(route('vaults.open', $vault->uuid))
        ->assertRedirect(route('workspace'));

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid);

    File::deleteDirectory($vault->path);

    $response = $this->post(route('vaults.open', $vault->uuid));

    $response->assertRedirect();
    $response->assertInertiaFlash('toast.type', 'error');
});

test('opening a vault indexes its Markdown files', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();
    File::put($vault->path.DIRECTORY_SEPARATOR.'n.md', 'hi');

    $response = $this->post(route('vaults.open', $vault->uuid));

    $response->assertRedirect(route('workspace'));
    expect(Note::query()->count())->toBe(1);
    expect(session('inertia.flash_data')['toast']['message'])->toContain('1 added');
});

test('closing clears the current vault', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();

    $this->post(route('vaults.close'))->assertRedirect(route('workspace'));

    expect(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBeNull();
});

test('an unknown uuid and an integer id both 404', function () {
    $this->post(route('vaults.store'), ['name' => 'Work']);
    $vault = Vault::query()->sole();

    $this->post(route('vaults.open', Str::uuid()->toString()))->assertNotFound();
    $this->post("/vaults/{$vault->id}/open")->assertNotFound();
});

test('the shared vaults prop appears on other pages with the correct shape', function () {
    app(VaultService::class)->create('Alpha');
    $beta = app(VaultService::class)->create('Beta');
    app(VaultService::class)->open($beta);
    File::deleteDirectory($beta->path);

    $response = $this->get(route('workspace'));

    $response->assertInertia(function ($page) {
        $page->has('vaults', 2);
        $vaults = $page->toArray()['props']['vaults'];

        expect($vaults)->each(fn ($vault) => $vault->not->toHaveKey('id'));

        $beta = collect($vaults)->firstWhere('name', 'Beta');
        expect($beta['is_current'])->toBeTrue()
            ->and($beta['status'])->toBe('missing');

        $alpha = collect($vaults)->firstWhere('name', 'Alpha');
        expect($alpha['is_current'])->toBeFalse()
            ->and($alpha['status'])->toBe('active');
    });

    $this->get(route('settings.general.edit'))
        ->assertInertia(fn ($page) => $page->has('vaults', 2));
});
