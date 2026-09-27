<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;
use App\Services\VaultService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('a guest can open the workspace with system status', function () {
    $response = $this->get(route('workspace'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Workspace')
        ->where('status.application', config('app.name'))
        ->where('status.runtime', 'browser')
        ->where('status.database.driver', 'sqlite')
        ->where('status.database.connected', true)
        ->has('status.version')
        ->where('editor.font_size', 16)
        ->where('editor.word_wrap', true)
        ->where('currentVault', null)
    );
});

test('the current vault persists across a restart', function () {
    $tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mdvault-workspace-'.Str::random(8);

    try {
        fakeDocumentsDirectory($tmp.DIRECTORY_SEPARATOR.'Documents');

        $vault = app(VaultService::class)->create('Work');
        app(VaultService::class)->open($vault);

        app()->forgetScopedInstances();

        $response = $this->get(route('workspace'));

        $response->assertInertia(fn ($page) => $page
            ->where('currentVault.uuid', $vault->uuid)
            ->where('currentVault.name', $vault->name));
    } finally {
        File::deleteDirectory($tmp);
    }
});

test('the workspace editor prop reflects a stored preference', function () {
    app(SettingsService::class)->set(SettingKey::EditorFontSize, 20);

    $response = $this->get(route('workspace'));

    $response->assertInertia(fn ($page) => $page->where('editor.font_size', 20));
});

test('removed auth and account routes are gone', function (string $uri) {
    $this->get($uri)->assertNotFound();
})->with([
    '/login',
    '/register',
    '/forgot-password',
    '/two-factor-challenge',
    '/user/confirm-password',
    '/dashboard',
    '/settings/profile',
    '/settings/security',
    '/.well-known/passkey-endpoints',
]);
