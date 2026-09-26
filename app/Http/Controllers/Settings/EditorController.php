<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateEditorSettingsRequest;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EditorController extends Controller
{
    public function edit(SettingsService $settings): Response
    {
        return Inertia::render('settings/Editor', [
            'preferences' => $settings->group(SettingGroup::Editor),
        ]);
    }

    public function update(UpdateEditorSettingsRequest $request, SettingsService $settings): RedirectResponse
    {
        $settings->setMany([
            SettingKey::EditorFontSize->value => (int) $request->validated('font_size'),
            SettingKey::EditorFontFamily->value => $request->validated('font_family'),
            SettingKey::EditorLineHeight->value => (float) $request->validated('line_height'),
            SettingKey::EditorWordWrap->value => $request->boolean('word_wrap'),
            SettingKey::EditorShowLineNumbers->value => $request->boolean('show_line_numbers'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Editor preferences saved.']);

        return back();
    }
}
