<?php

arch()->preset()->php();
arch()->preset()->security();

arch('services are final and suffixed with Service')
    ->expect('App\Services')
    ->classes()
    ->toBeFinal()
    ->toHaveSuffix('Service');

arch('services do not depend on the HTTP layer')
    ->expect('App\Services')
    ->not->toUse(['App\Http', 'Illuminate\Http', 'Inertia']);

arch('HTTP layer does not touch the filesystem directly')
    ->expect([
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
        'file_put_contents',
        'file_get_contents',
        'fopen',
        'unlink',
        'mkdir',
        'rmdir',
        'rename',
        'copy',
        'scandir',
    ])
    ->not->toBeUsedIn('App\Http');

arch('settings are only accessed through SettingsService')
    ->expect('App\Models\Setting')
    ->toOnlyBeUsedIn('App\Services\SettingsService');

arch('enums folder only contains enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('native dialogs only via NativeDialogService')
    ->expect('Native\Desktop\Dialog')
    ->toOnlyBeUsedIn('App\Services\NativeDialogService');

arch('the OS shell is only used via NativeTrash')
    ->expect(['Native\Desktop\Facades\Shell', 'Native\Desktop\Shell'])
    ->toOnlyBeUsedIn('App\Support\NativeTrash');

arch('vault records are only used by services, controllers and the factory')
    ->expect('App\Models\Vault')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'Database\Factories']);
