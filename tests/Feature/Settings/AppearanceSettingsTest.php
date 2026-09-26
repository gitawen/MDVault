<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;

test('settings redirects to general', function () {
    $this->get('/settings')->assertRedirect(route('settings.general.edit'));
});

test('a guest can view the appearance settings page', function () {
    $response = $this->get(route('settings.appearance.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Appearance')
        ->where('theme', 'system'));
});

test('updating the theme persists it', function () {
    $this->patch(route('settings.appearance.update'), ['theme' => 'dark'])
        ->assertRedirect();

    expect(app(SettingsService::class)->string(SettingKey::AppearanceTheme))->toBe('dark');
});

test('an invalid theme is rejected', function (mixed $theme) {
    $this->patch(route('settings.appearance.update'), ['theme' => $theme])
        ->assertSessionHasErrors('theme');

    expect(app(SettingsService::class)->has(SettingKey::AppearanceTheme))->toBeFalse();
})->with([
    'unknown value' => ['blue'],
    'missing' => [null],
]);

test('the html renders the stored theme server-side', function () {
    app(SettingsService::class)->set(SettingKey::AppearanceTheme, 'dark');

    $response = $this->get(route('workspace'));

    $response->assertSee('data-appearance="dark"', false);
    $response->assertSee('class="dark"', false);
});

test('the default render has no dark class', function () {
    $response = $this->get(route('workspace'));

    $response->assertSee('data-appearance="system"', false);
    $response->assertDontSee('class="dark"', false);
});
