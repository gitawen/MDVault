<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;

test('the security page shows the defaults and the choices', function () {
    $this->get(route('settings.security.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Security')
            ->where('preferences', ['auto_lock_minutes' => 15, 'lock_on_screen_lock' => true])
            ->where('autoLockChoices', [0, 5, 15, 30, 60]));
});

test('valid security settings are saved and shared', function () {
    $this->patch(route('settings.security.update'), ['auto_lock_minutes' => 30, 'lock_on_screen_lock' => false])
        ->assertRedirect()
        ->assertInertiaFlash('toast.message', 'Security settings saved.');

    $settings = app(SettingsService::class);

    expect($settings->integer(SettingKey::SecurityAutoLockMinutes))->toBe(30)
        ->and($settings->boolean(SettingKey::SecurityLockOnScreenLock))->toBeFalse();

    $this->get(route('workspace'))->assertInertia(fn ($page) => $page
        ->where('security.auto_lock_minutes', 30)
        ->where('security.lock_on_screen_lock', false));
});

test('zero means never and is accepted', function () {
    $this->patch(route('settings.security.update'), ['auto_lock_minutes' => 0, 'lock_on_screen_lock' => true])->assertRedirect();

    expect(app(SettingsService::class)->integer(SettingKey::SecurityAutoLockMinutes))->toBe(0);
});

test('invalid security settings are refused and nothing changes', function (array $input, string $field) {
    $this->patch(route('settings.security.update'), [
        'auto_lock_minutes' => 15,
        'lock_on_screen_lock' => true,
        ...$input,
    ])->assertSessionHasErrors($field);

    expect(app(SettingsService::class)->integer(SettingKey::SecurityAutoLockMinutes))->toBe(15);
})->with([
    'not an allowed value' => [['auto_lock_minutes' => 7], 'auto_lock_minutes'],
    'negative' => [['auto_lock_minutes' => -1], 'auto_lock_minutes'],
    'missing minutes' => [['auto_lock_minutes' => null], 'auto_lock_minutes'],
    'not a boolean' => [['lock_on_screen_lock' => 'maybe'], 'lock_on_screen_lock'],
]);
