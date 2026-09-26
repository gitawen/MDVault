<?php

use App\Enums\SettingKey;
use App\Services\SettingsService;

test('the editor page shows defaults', function () {
    $response = $this->get(route('settings.editor.edit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/Editor')
        ->where('preferences', [
            'font_size' => 16,
            'font_family' => 'sans',
            'line_height' => 1.6,
            'word_wrap' => true,
            'show_line_numbers' => false,
        ]));
});

test('valid editor preferences are persisted', function () {
    $this->patch(route('settings.editor.update'), [
        'font_size' => 20,
        'font_family' => 'mono',
        'line_height' => 1.8,
        'word_wrap' => false,
        'show_line_numbers' => true,
    ])->assertRedirect();

    $settings = app(SettingsService::class);

    expect($settings->integer(SettingKey::EditorFontSize))->toBe(20)
        ->and($settings->string(SettingKey::EditorFontFamily))->toBe('mono')
        ->and($settings->float(SettingKey::EditorLineHeight))->toBe(1.8)
        ->and($settings->boolean(SettingKey::EditorWordWrap))->toBeFalse()
        ->and($settings->boolean(SettingKey::EditorShowLineNumbers))->toBeTrue();
});

test('invalid editor preferences are rejected', function (array $overrides, string $field) {
    $payload = array_merge([
        'font_size' => 16,
        'font_family' => 'sans',
        'line_height' => 1.6,
        'word_wrap' => true,
        'show_line_numbers' => false,
    ], $overrides);

    $this->patch(route('settings.editor.update'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'font size too small' => [['font_size' => 11], 'font_size'],
    'font size too large' => [['font_size' => 25], 'font_size'],
    'font size not numeric' => [['font_size' => 'abc'], 'font_size'],
    'unknown font family' => [['font_family' => 'comic'], 'font_family'],
    'line height too small' => [['line_height' => 1.0], 'line_height'],
    'line height too large' => [['line_height' => 3], 'line_height'],
    'word wrap not boolean' => [['word_wrap' => 'maybe'], 'word_wrap'],
    'missing font size' => [['font_size' => null], 'font_size'],
]);
