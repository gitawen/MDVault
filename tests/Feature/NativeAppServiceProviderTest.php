<?php

use App\Providers\NativeAppServiceProvider;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Window;
use Native\Desktop\Windows\Window as WindowInstance;

test('the main window opens with the MDVault configuration', function () {
    // The window returned by the fake is a plain Window instance (not a
    // PendingOpenWindow), so calling ->title() on it issues a real HTTP
    // call to the NativePHP driver process. Fake HTTP so the test stays
    // offline; the window configuration is still verified via toArray().
    Http::fake();

    $window = new WindowInstance('main');

    Window::fake()->alwaysReturnWindows([$window]);

    (new NativeAppServiceProvider)->boot();

    Window::assertOpened('main');

    expect($window->toArray())->toMatchArray([
        'title' => config('app.name'),
        'width' => 1280,
        'height' => 800,
        'minWidth' => 960,
        'minHeight' => 600,
        'rememberState' => true,
        'preventLeaveDomain' => true,
    ]);
});
