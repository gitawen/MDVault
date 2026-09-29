<?php

use App\Enums\SettingKey;
use App\Services\MarkdownService;
use App\Services\SettingsService;

function baseEditorSettingsPayload(): array
{
    return [
        'font_size' => 16,
        'font_family' => 'sans',
        'line_height' => 1.6,
        'word_wrap' => true,
        'show_line_numbers' => false,
        'new_note_template_enabled' => true,
        'new_note_template' => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE,
    ];
}

test('the editor page shows defaults, including the new-note template defaults', function () {
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
            'new_note_template_enabled' => true,
            'new_note_template' => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE,
        ])
        ->where('defaultNewNoteTemplate', MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE));
});

test('valid editor preferences are persisted', function () {
    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'font_size' => 20,
        'font_family' => 'mono',
        'line_height' => 1.8,
        'word_wrap' => false,
        'show_line_numbers' => true,
    ]))->assertRedirect();

    $settings = app(SettingsService::class);

    expect($settings->integer(SettingKey::EditorFontSize))->toBe(20)
        ->and($settings->string(SettingKey::EditorFontFamily))->toBe('mono')
        ->and($settings->float(SettingKey::EditorLineHeight))->toBe(1.8)
        ->and($settings->boolean(SettingKey::EditorWordWrap))->toBeFalse()
        ->and($settings->boolean(SettingKey::EditorShowLineNumbers))->toBeTrue();
});

test('invalid editor preferences are rejected', function (array $overrides, string $field) {
    $payload = array_merge(baseEditorSettingsPayload(), $overrides);

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

// --- Revision 4: new-note frontmatter template -----------------------------

test('the toggle and the template are saved, with CRLF normalised and trailing newlines trimmed', function () {
    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template_enabled' => false,
        'new_note_template' => "title: {{title}}\r\nstatus: draft\r\n\r\n",
    ]))->assertRedirect();

    $settings = app(SettingsService::class);

    expect($settings->boolean(SettingKey::EditorNewNoteTemplateEnabled))->toBeFalse();
    expect($settings->string(SettingKey::EditorNewNoteTemplate))->toBe("title: {{title}}\nstatus: draft");
});

test('a --- line in the template is rejected', function () {
    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template' => "title: X\n---\nstatus: draft",
    ]))->assertSessionHasErrors('new_note_template');
});

test('an empty template while enabled is rejected, but while disabled it is saved unchanged', function () {
    // First, store a known non-default template so we can prove it is left
    // alone.
    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template' => 'status: draft',
    ]))->assertRedirect();

    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template_enabled' => true,
        'new_note_template' => '',
    ]))->assertSessionHasErrors('new_note_template');

    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template_enabled' => false,
        'new_note_template' => '',
    ]))->assertRedirect();

    expect(app(SettingsService::class)->string(SettingKey::EditorNewNoteTemplate))->toBe('status: draft');
});

test('a template longer than 4000 characters is rejected', function () {
    $this->patch(route('settings.editor.update'), array_merge(baseEditorSettingsPayload(), [
        'new_note_template' => str_repeat('a', 4001),
    ]))->assertSessionHasErrors('new_note_template');
});
