<?php

use App\Services\NativeDialogService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('the dialog is not available outside the desktop runtime', function () {
    Http::fake();

    $result = app(NativeDialogService::class)->chooseDirectory('Choose a folder');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

test('a picked folder is returned', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => ['/picked/dir']]),
    ]);

    $result = app(NativeDialogService::class)->chooseDirectory('Choose where MDVault stores your vaults');

    expect($result)->toBe('/picked/dir');

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), 'dialog/open')
            && $request['title'] === 'Choose where MDVault stores your vaults'
            && in_array('openDirectory', $request['properties'], true);
    });
});

test('cancelling the dialog returns null', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => []]),
    ]);

    $result = app(NativeDialogService::class)->chooseDirectory('Choose a folder');

    expect($result)->toBeNull();
});

test('defaultPath is only sent when the directory exists', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => []]),
    ]);

    app(NativeDialogService::class)->chooseDirectory('Choose a folder', sys_get_temp_dir());

    Http::assertSent(fn (Request $request) => $request['defaultPath'] === sys_get_temp_dir());

    app(NativeDialogService::class)->chooseDirectory('Choose a folder', sys_get_temp_dir().'/does-not-exist-'.Str::random(8));

    Http::assertSent(fn (Request $request) => $request['defaultPath'] === null);
});

// --- chooseSaveFile ---------------------------------------------------------

test('chooseSaveFile returns the picked path', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/save' => Http::response(['result' => 'C:\\b\\x.zip']),
    ]);

    $result = app(NativeDialogService::class)->chooseSaveFile('Save MDVault backup', 'C:\\b\\default.zip', 'MDVault backup', ['zip']);

    expect($result)->toBe('C:\\b\\x.zip');
});

test('chooseSaveFile returns null for an empty result (cancel)', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/save' => Http::response(['result' => null]),
    ]);

    $result = app(NativeDialogService::class)->chooseSaveFile('Save MDVault backup', 'C:\\b\\default.zip', 'MDVault backup', ['zip']);

    expect($result)->toBeNull();
});

test('chooseSaveFile returns null and sends nothing when unavailable', function () {
    Http::fake();

    $result = app(NativeDialogService::class)->chooseSaveFile('Save MDVault backup', 'C:\\b\\default.zip', 'MDVault backup', ['zip']);

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

// --- chooseFile --------------------------------------------------------------

test('chooseFile returns the first picked path and sends the filter', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => ['C:\\b\\backup.zip']]),
    ]);

    $result = app(NativeDialogService::class)->chooseFile('Choose an MDVault backup', 'MDVault backup', ['zip']);

    expect($result)->toBe('C:\\b\\backup.zip');

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), 'dialog/open')
            && $request['filters'][0]['name'] === 'MDVault backup'
            && $request['filters'][0]['extensions'] === ['zip'];
    });
});

test('chooseFile returns null on cancel', function () {
    config(['nativephp-internal.running' => true]);

    Http::fake([
        '*dialog/open' => Http::response(['result' => []]),
    ]);

    $result = app(NativeDialogService::class)->chooseFile('Choose an MDVault backup', 'MDVault backup', ['zip']);

    expect($result)->toBeNull();
});
