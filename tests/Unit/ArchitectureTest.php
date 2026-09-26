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
