<?php

use App\Support\NativeTrash;
use Illuminate\Support\Facades\Http;

test('it is not available and sends nothing outside the desktop runtime', function () {
    Http::fake();

    app(NativeTrash::class)->moveToTrash('/some/path');

    Http::assertNothingSent();
});

test('it sends a DELETE to shell/trash-item in the desktop runtime', function () {
    config(['nativephp-internal.running' => true]);
    Http::fake(['*shell/trash-item' => Http::response(null, 200)]);

    app(NativeTrash::class)->moveToTrash('/some/path');

    Http::assertSent(function ($request) {
        return $request->method() === 'DELETE'
            && str_ends_with($request->url(), 'shell/trash-item')
            && $request['path'] === '/some/path';
    });
});
