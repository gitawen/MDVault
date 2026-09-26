<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateAppearanceRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AppearanceController extends Controller
{
    public function edit(SettingsService $settings): Response
    {
        return Inertia::render('settings/Appearance', [
            'theme' => $settings->string(SettingKey::AppearanceTheme),
        ]);
    }

    public function update(UpdateAppearanceRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->set(SettingKey::AppearanceTheme, $request->validated('theme'));

        return back();
    }
}
