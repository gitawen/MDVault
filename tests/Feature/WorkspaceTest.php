<?php

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
    );
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
