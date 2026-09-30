<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\StoragePathService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-tests-'.Str::random(8);
    fakeDocumentsDirectory($this->tmp.DIRECTORY_SEPARATOR.'Documents');
});

afterEach(function () {
    File::deleteDirectory($this->tmp);
});

test('the storage page shows the default location and no browse button', function () {
    $response = $this->get(route('settings.storage.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Storage')
        ->where('storage.is_default', true)
        ->where('canBrowse', false)
        ->where('storage.folder_name', 'MDVault')
        ->where('storage.default_path', fn (string $path) => str_ends_with($path, 'MDVault')));
});

test('patching a new location creates its MDVault subfolder and persists the canonical path', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    $expected = $target.DIRECTORY_SEPARATOR.'MDVault';

    $this->patch(route('settings.storage.update'), [
        'location' => $target,
        'folder_name' => 'MDVault',
    ])->assertRedirect();

    expect(is_dir($expected))->toBeTrue()
        ->and(app(StoragePathService::class)->rootPath())->toBe(realpath($expected));
});

test('patching a location with a custom folder name persists both', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    $expected = $target.DIRECTORY_SEPARATOR.'MyVault';

    $this->patch(route('settings.storage.update'), [
        'location' => $target,
        'folder_name' => 'MyVault',
    ])->assertRedirect();

    expect(is_dir($expected))->toBeTrue()
        ->and(app(StoragePathService::class)->rootPath())->toBe(realpath($expected))
        ->and(app(StoragePathService::class)->folderName())->toBe('MyVault');
});

test('an update with the folder name omitted is rejected and nothing already saved is disturbed', function () {
    app(StoragePathService::class)->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'first', 'MyVault');

    $target = $this->tmp.DIRECTORY_SEPARATOR.'second';

    $this->patch(route('settings.storage.update'), ['location' => $target])
        ->assertSessionHasErrors('folder_name');

    expect(app(StoragePathService::class)->folderName())->toBe('MyVault')
        ->and(app(StoragePathService::class)->rootPath())->toBe(
            realpath($this->tmp.DIRECTORY_SEPARATOR.'first'.DIRECTORY_SEPARATOR.'MyVault')
        )
        ->and(is_dir($target))->toBeFalse();
});

test('an update with an empty folder name is rejected', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    $this->patch(route('settings.storage.update'), [
        'location' => $target,
        'folder_name' => '',
    ])->assertSessionHasErrors('folder_name');

    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse()
        ->and(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('a relative location is rejected and the setting is unchanged', function () {
    $this->patch(route('settings.storage.update'), [
        'location' => 'relative/dir',
        'folder_name' => 'MDVault',
    ])->assertSessionHasErrors('location');

    expect(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('an empty location is rejected', function () {
    $this->patch(route('settings.storage.update'), [
        'location' => '',
        'folder_name' => 'MDVault',
    ])->assertSessionHasErrors('location');
});

test('a file location is rejected', function () {
    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::put($file, 'content');

    $this->patch(route('settings.storage.update'), [
        'location' => $file,
        'folder_name' => 'MDVault',
    ])->assertSessionHasErrors('location');
});

test('an invalid folder name is rejected on the folder_name field and the setting is unchanged', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    $this->patch(route('settings.storage.update'), [
        'location' => $target,
        'folder_name' => 'CON',
    ])->assertSessionHasErrors('folder_name');

    expect(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse()
        ->and(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('deleting the storage setting restores the default', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';
    app(StoragePathService::class)->changeRoot($target);

    $this->delete(route('settings.storage.destroy'))
        ->assertRedirect();

    expect(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('browse is not found outside the desktop runtime', function () {
    $this->post(route('settings.storage.browse'))->assertNotFound();
});

test('browse returns the picked folder without creating it or writing any setting', function () {
    config(['nativephp-internal.running' => true]);

    $target = $this->tmp.DIRECTORY_SEPARATOR.'picked';

    Http::fake([
        '*dialog/open' => Http::response(['result' => [$target]]),
    ]);

    $response = $this->post(route('settings.storage.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlash('pickedLocation.location', $target);

    expect(is_dir($target))->toBeFalse()
        ->and(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse()
        ->and(app(SettingsService::class)->has(SettingKey::StorageFolderName))->toBeFalse();
});

test('browse does not disturb an already-saved location or folder name', function () {
    config(['nativephp-internal.running' => true]);
    app(StoragePathService::class)->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'first', 'MyVault');

    $target = $this->tmp.DIRECTORY_SEPARATOR.'picked';

    Http::fake([
        '*dialog/open' => Http::response(['result' => [$target]]),
    ]);

    $this->post(route('settings.storage.browse'))->assertRedirect();

    expect(app(StoragePathService::class)->folderName())->toBe('MyVault')
        ->and(app(StoragePathService::class)->rootPath())->toBe(
            realpath($this->tmp.DIRECTORY_SEPARATOR.'first'.DIRECTORY_SEPARATOR.'MyVault')
        )
        ->and(is_dir($target))->toBeFalse();
});

test('cancelling the browse dialog changes nothing and flashes no picked location', function () {
    config(['nativephp-internal.running' => true]);
    app(StoragePathService::class)->changeRoot($this->tmp.DIRECTORY_SEPARATOR.'first', 'MyVault');

    Http::fake([
        '*dialog/open' => Http::response(['result' => []]),
    ]);

    $response = $this->post(route('settings.storage.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlashMissing('pickedLocation');

    expect(app(StoragePathService::class)->folderName())->toBe('MyVault')
        ->and(app(StoragePathService::class)->rootPath())->toBe(
            realpath($this->tmp.DIRECTORY_SEPARATOR.'first'.DIRECTORY_SEPARATOR.'MyVault')
        );
});

test('browsing to a file path is returned as-is and still creates nothing (validated only on save)', function () {
    config(['nativephp-internal.running' => true]);

    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::put($file, 'content');

    Http::fake([
        '*dialog/open' => Http::response(['result' => [$file]]),
    ]);

    $response = $this->post(route('settings.storage.browse'));

    $response->assertRedirect();
    $response->assertInertiaFlash('pickedLocation.location', $file);
    expect(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('canBrowse is true in the desktop runtime', function () {
    config(['nativephp-internal.running' => true]);

    $response = $this->get(route('settings.storage.edit'));

    $response->assertInertia(fn ($page) => $page->where('canBrowse', true));
});
