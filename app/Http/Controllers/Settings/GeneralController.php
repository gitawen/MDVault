<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateGeneralSettingsRequest;
use App\Services\SettingsService;
use App\Services\SystemStatusService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class GeneralController extends Controller
{
    public function edit(SettingsService $settings, SystemStatusService $status): Response
    {
        return Inertia::render('settings/General', [
            'settings' => $settings->group(SettingGroup::General),
            'status' => $status->summary(),
        ]);
    }

    public function update(UpdateGeneralSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->set(SettingKey::CheckExternalChanges, $request->boolean('check_external_changes'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Settings saved.']);

        return back();
    }
}
