<?php

use App\Enums\SettingKey;
use App\Models\Note;
use App\Models\Vault;
use App\Services\SettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-existing-vault-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('registering an existing folder succeeds and opens it', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);
    File::put($existing.DIRECTORY_SEPARATOR.'n.md', 'hi');

    $response = $this->post(route('vaults.existing.store'), ['path' => $existing, 'name' => 'Existing']);

    $response->assertRedirect(route('workspace'));
    $response->assertInertiaFlash('toast.type', 'success');
    $response->assertInertiaFlash('toast.message', "Vault \u{201c}Existing\u{201d} added. 1 note(s) indexed.");

    $vault = Vault::query()->sole();

    expect(File::get($existing.DIRECTORY_SEPARATOR.'n.md'))->toBe('hi')
        ->and(app(SettingsService::class)->string(SettingKey::CurrentVault))->toBe($vault->uuid)
        ->and(Note::query()->count())->toBe(1);
});

test('registering rejects a relative path', function () {
    $this->post(route('vaults.existing.store'), ['path' => 'relative/dir', 'name' => 'Name'])
        ->assertSessionHasErrors('path');

    expect(Vault::count())->toBe(0);
});

test('registering rejects a missing directory', function () {
    $missing = $this->tmp.DIRECTORY_SEPARATOR.'does-not-exist';

    $this->post(route('vaults.existing.store'), ['path' => $missing, 'name' => 'Name'])
        ->assertSessionHasErrors('path');

    expect(Vault::count())->toBe(0);
});

test('registering rejects a file path', function () {
    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'file.txt';
    File::put($file, 'x');

    $this->post(route('vaults.existing.store'), ['path' => $file, 'name' => 'Name'])
        ->assertSessionHasErrors('path');
});

test('registering rejects the storage root itself', function () {
    $root = $this->tmp.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'MDVault';
    File::makeDirectory($root, 0755, true);

    $this->post(route('vaults.existing.store'), ['path' => $root, 'name' => 'Name'])
        ->assertSessionHasErrors('path');
});

test('registering rejects an invalid name', function () {
    $existing = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($existing, 0755, true);

    $this->post(route('vaults.existing.store'), ['path' => $existing, 'name' => 'CON'])
        ->assertSessionHasErrors('name');

    expect(Vault::count())->toBe(0);
});

test('browse is not found outside the desktop runtime', function () {
    $this->post(route('vaults.existing.browse'))->assertNotFound();
});

test('browse flashes the picked folder path and suggested name', function () {
    config(['nativephp-internal.running' => true]);

    $dir = $this->tmp.DIRECTORY_SEPARATOR.'Existing';
    File::makeDirectory($dir, 0755, true);

    Http::fake(['*dialog/open' => Http::response(['result' => [$dir]])]);

    $response = $this->post(route('vaults.existing.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlash('pickedFolder.path', $dir);
    $response->assertInertiaFlash('pickedFolder.name', 'Existing');
    $response->assertInertiaFlash('pickedFolders', [
        ['path' => $dir, 'name' => 'Existing'],
    ]);

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), 'dialog/open')
            && in_array('multiSelections', $request['properties'], true);
    });

    expect(Vault::count())->toBe(0);
});

test('browse flashes pickedFolders when multiple folders are selected', function () {
    config(['nativephp-internal.running' => true]);

    $dir1 = $this->tmp.DIRECTORY_SEPARATOR.'First';
    $dir2 = $this->tmp.DIRECTORY_SEPARATOR.'Second';
    File::makeDirectory($dir1, 0755, true);
    File::makeDirectory($dir2, 0755, true);

    Http::fake(['*dialog/open' => Http::response(['result' => [$dir1, $dir2]])]);

    $response = $this->post(route('vaults.existing.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlashMissing('pickedFolder');
    $response->assertInertiaFlash('pickedFolders', [
        ['path' => $dir1, 'name' => 'First'],
        ['path' => $dir2, 'name' => 'Second'],
    ]);

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), 'dialog/open')
            && in_array('multiSelections', $request['properties'], true);
    });

    expect(Vault::count())->toBe(0);
});

test('cancelling the browse dialog flashes nothing', function (mixed $dialogResult) {
    config(['nativephp-internal.running' => true]);

    Http::fake(['*dialog/open' => Http::response(['result' => $dialogResult])]);

    $response = $this->post(route('vaults.existing.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlashMissing('pickedFolder');
    $response->assertInertiaFlashMissing('pickedFolders');
})->with([
    'empty array' => [[]],
    'null result' => [null],
]);
