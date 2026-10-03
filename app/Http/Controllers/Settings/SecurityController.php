<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSecuritySettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    public function edit(SettingsService $settings): Response
    {
        return Inertia::render('settings/Security', [
            'preferences' => $settings->group(SettingGroup::Security),
            'autoLockChoices' => UpdateSecuritySettingsRequest::AUTO_LOCK_CHOICES,
        ]);
    }

    public function update(UpdateSecuritySettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->setMany([
            SettingKey::SecurityAutoLockMinutes->value => (int) $request->validated('auto_lock_minutes'),
            SettingKey::SecurityLockOnScreenLock->value => $request->boolean('lock_on_screen_lock'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Security settings saved.']);

        return back();
    }
}
