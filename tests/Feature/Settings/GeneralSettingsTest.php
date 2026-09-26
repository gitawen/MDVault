<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;

test('the general page shows settings and status', function () {
    $response = $this->get(route('settings.general.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/General')
        ->where('settings.check_external_changes', true)
        ->where('status.database.driver', 'sqlite'));
});

test('patching check_external_changes persists it', function () {
    $this->patch(route('settings.general.update'), ['check_external_changes' => false])
        ->assertRedirect();

    expect(app(SettingsService::class)->boolean(SettingKey::CheckExternalChanges))->toBeFalse();
});

test('an invalid value is rejected', function () {
    $this->patch(route('settings.general.update'), ['check_external_changes' => 'nope'])
        ->assertSessionHasErrors('check_external_changes');
});
