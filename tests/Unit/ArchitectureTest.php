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

arch('vault records are only used by services, controllers, models and factories')
    ->expect('App\Models\Vault')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'App\Models', 'Database\Factories']);

arch('note records are only used by services, controllers, models and factories')
    ->expect('App\Models\Note')
    ->toOnlyBeUsedIn(['App\Services', 'App\Http\Controllers', 'App\Models', 'Database\Factories']);

arch('files are hashed only in FileHashService')
    ->expect('hash_file')
    ->toOnlyBeUsedIn('App\Services\FileHashService');

arch('only FileStorageService deletes, writes or syncs files')
    ->expect(['unlink', 'file_put_contents', 'fsync'])
    ->toOnlyBeUsedIn('App\Services\FileStorageService');

arch('index and note services use no raw filesystem functions')
    ->expect([
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'unlink',
        'rmdir',
        'mkdir',
        'rename',
        'copy',
        'scandir',
        'hash_file',
        'Illuminate\Support\Facades\File',
        'Illuminate\Support\Facades\Storage',
    ])
    ->not->toBeUsedIn(['App\Services\VaultIndexService', 'App\Services\NoteService', 'App\Services\MarkdownService']);
