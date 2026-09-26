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
        ->where('storage.default_path', fn (string $path) => str_ends_with($path, 'MDVault')));
});

test('patching a new path creates it and persists the canonical path', function () {
    $target = $this->tmp.DIRECTORY_SEPARATOR.'chosen';

    $this->patch(route('settings.storage.update'), ['root_path' => $target])
        ->assertRedirect();

    expect(is_dir($target))->toBeTrue()
        ->and(app(StoragePathService::class)->rootPath())->toBe(realpath($target));
});

test('a relative path is rejected and the setting is unchanged', function () {
    $this->patch(route('settings.storage.update'), ['root_path' => 'relative/dir'])
        ->assertSessionHasErrors('root_path');

    expect(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('an empty path is rejected', function () {
    $this->patch(route('settings.storage.update'), ['root_path' => ''])
        ->assertSessionHasErrors('root_path');
});

test('a file path is rejected', function () {
    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::put($file, 'content');

    $this->patch(route('settings.storage.update'), ['root_path' => $file])
        ->assertSessionHasErrors('root_path');
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

test('browse persists the picked folder in the desktop runtime', function () {
    config(['nativephp-internal.running' => true]);

    $target = $this->tmp.DIRECTORY_SEPARATOR.'picked';
    File::makeDirectory($target, 0755, true);

    Http::fake([
        '*dialog/open' => Http::response(['result' => [$target]]),
    ]);

    $this->post(route('settings.storage.browse'))->assertRedirect();

    expect(app(StoragePathService::class)->rootPath())->toBe(realpath($target));
});

test('cancelling the browse dialog changes nothing', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => []]),
    ]);

    $this->post(route('settings.storage.browse'))->assertRedirect();

    expect(app(SettingsService::class)->has(SettingKey::StorageRootPath))->toBeFalse();
});

test('browsing to a file path produces a session error', function () {
    config(['nativephp-internal.running' => true]);

    File::makeDirectory($this->tmp, 0755, true);
    $file = $this->tmp.DIRECTORY_SEPARATOR.'blocker.txt';
    File::put($file, 'content');

    Http::fake([
        '*dialog/open' => Http::response(['result' => [$file]]),
    ]);

    $this->post(route('settings.storage.browse'))->assertSessionHasErrors('root_path');
});

test('canBrowse is true in the desktop runtime', function () {
    config(['nativephp-internal.running' => true]);

    $response = $this->get(route('settings.storage.edit'));

    $response->assertInertia(fn ($page) => $page->where('canBrowse', true));
});
