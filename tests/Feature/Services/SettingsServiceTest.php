<?php

use App\Enums\EditorFontFamily;
use App\Enums\SettingGroup;
use App\Enums\SettingKey;
use App\Enums\SettingType;
use App\Models\Setting;
use App\Services\MarkdownService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Schema;

test('every key returns its default on an empty table', function (SettingKey $key) {
    expect(app(SettingsService::class)->get($key))->toBe($key->default());
})->with(fn () => SettingKey::cases());

test('a value round trips for each type', function (SettingKey $key, mixed $value) {
    $settings = app(SettingsService::class);

    $settings->set($key, $value);

    expect($settings->get($key))->toBe($value);

    $row = Setting::query()->where('key', $key->value)->sole();

    expect($row->type)->toBe($key->type())
        ->and($row->group)->toBe($key->group());
})->with([
    'string' => [SettingKey::AppearanceTheme, 'dark'],
    'integer' => [SettingKey::EditorFontSize, 20],
    'float' => [SettingKey::EditorLineHeight, 1.8],
    'boolean true' => [SettingKey::EditorWordWrap, true],
    'boolean false' => [SettingKey::EditorShowLineNumbers, false],
]);

test('setting a key twice updates the same row', function () {
    $settings = app(SettingsService::class);

    $settings->set(SettingKey::EditorFontSize, 18);
    $settings->set(SettingKey::EditorFontSize, 22);

    expect(Setting::query()->count())->toBe(1)
        ->and($settings->get(SettingKey::EditorFontSize))->toBe(22);
});

test('set rejects a value of the wrong type and writes nothing', function (SettingKey $key, mixed $value) {
    $settings = app(SettingsService::class);

    expect(fn () => $settings->set($key, $value))->toThrow(InvalidArgumentException::class);

    expect(Setting::query()->where('key', $key->value)->exists())->toBeFalse();
})->with([
    'font size as string' => [SettingKey::EditorFontSize, '16'],
    'word wrap as int' => [SettingKey::EditorWordWrap, 1],
    'theme as bool' => [SettingKey::AppearanceTheme, true],
]);

test('setting null and forgetting remove the row and restore the default', function () {
    $settings = app(SettingsService::class);

    $settings->set(SettingKey::EditorFontSize, 20);
    $settings->set(SettingKey::EditorFontSize, null);

    expect(Setting::query()->where('key', SettingKey::EditorFontSize->value)->exists())->toBeFalse()
        ->and($settings->get(SettingKey::EditorFontSize))->toBe(16);

    $settings->set(SettingKey::EditorFontSize, 20);
    $settings->forget(SettingKey::EditorFontSize);

    expect(Setting::query()->where('key', SettingKey::EditorFontSize->value)->exists())->toBeFalse()
        ->and($settings->get(SettingKey::EditorFontSize))->toBe(16);
});

test('setMany writes nothing when one value is invalid', function () {
    $settings = app(SettingsService::class);

    expect(fn () => $settings->setMany([
        'editor.font_size' => 20,
        'editor.word_wrap' => 'nope',
    ]))->toThrow(InvalidArgumentException::class);

    expect(Setting::query()->count())->toBe(0);
});

test('setMany throws on an unknown key', function () {
    $settings = app(SettingsService::class);

    expect(fn () => $settings->setMany([
        'not.a.key' => 'value',
    ]))->toThrow(ValueError::class);

    expect(Setting::query()->count())->toBe(0);
});

test('a corrupt stored value falls back to the default', function () {
    Setting::query()->create([
        'key' => SettingKey::EditorFontSize->value,
        'value' => 'abc',
        'type' => SettingType::Integer,
        'group' => SettingGroup::Editor,
    ]);

    expect(app(SettingsService::class)->get(SettingKey::EditorFontSize))->toBe(16);
});

test('a row with a mismatched type falls back to the default', function () {
    Setting::query()->create([
        'key' => SettingKey::EditorFontSize->value,
        'value' => '20',
        'type' => SettingType::String,
        'group' => SettingGroup::Editor,
    ]);

    expect(app(SettingsService::class)->get(SettingKey::EditorFontSize))->toBe(16);
});

test('the memo is invalidated after a write', function () {
    $settings = app(SettingsService::class);

    expect($settings->get(SettingKey::EditorFontSize))->toBe(16);

    $settings->set(SettingKey::EditorFontSize, 24);

    expect($settings->get(SettingKey::EditorFontSize))->toBe(24);
});

test('a value persists for a fresh service instance', function () {
    app(SettingsService::class)->set(SettingKey::AppearanceTheme, 'dark');

    app()->forgetScopedInstances();

    expect(app(SettingsService::class)->string(SettingKey::AppearanceTheme))->toBe('dark');
});

test('group returns every field of the group with defaults merged with overrides', function () {
    $settings = app(SettingsService::class);

    $settings->set(SettingKey::EditorFontSize, 20);

    expect($settings->group(SettingGroup::Editor))->toBe([
        'font_size' => 20,
        'font_family' => EditorFontFamily::Sans->value,
        'line_height' => 1.6,
        'word_wrap' => true,
        'show_line_numbers' => false,
        'indent_size' => 4,
        'new_note_template_enabled' => true,
        'new_note_template' => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE,
    ]);
});

test('reads degrade to defaults when the settings table is unavailable', function () {
    Schema::drop('settings');

    expect(app(SettingsService::class)->get(SettingKey::AppearanceTheme))->toBe('system');
});

test('a typed accessor on the wrong type throws', function () {
    expect(fn () => app(SettingsService::class)->integer(SettingKey::AppearanceTheme))
        ->toThrow(LogicException::class);
});
