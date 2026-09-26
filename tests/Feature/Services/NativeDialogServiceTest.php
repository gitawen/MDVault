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
