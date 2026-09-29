<?php

namespace App\Enums;

use App\Services\MarkdownService;
use App\Services\StoragePathService;
use Illuminate\Support\Str;

enum SettingKey: string
{
    case StorageRootPath = 'storage.root_path';
    case StorageFolderName = 'storage.folder_name';
    case AppearanceTheme = 'appearance.theme';
    case EditorFontSize = 'editor.font_size';
    case EditorFontFamily = 'editor.font_family';
    case EditorLineHeight = 'editor.line_height';
    case EditorWordWrap = 'editor.word_wrap';
    case EditorShowLineNumbers = 'editor.show_line_numbers';
    case EditorNewNoteTemplateEnabled = 'editor.new_note_template_enabled';
    case EditorNewNoteTemplate = 'editor.new_note_template';
    case CheckExternalChanges = 'app.check_external_changes';
    case CurrentVault = 'app.current_vault';

    public function type(): SettingType
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName, self::AppearanceTheme, self::EditorFontFamily, self::EditorNewNoteTemplate, self::CurrentVault => SettingType::String,
            self::EditorFontSize => SettingType::Integer,
            self::EditorLineHeight => SettingType::Float,
            self::EditorWordWrap, self::EditorShowLineNumbers, self::EditorNewNoteTemplateEnabled, self::CheckExternalChanges => SettingType::Boolean,
        };
    }

    public function group(): SettingGroup
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName => SettingGroup::Storage,
            self::AppearanceTheme => SettingGroup::Appearance,
            self::EditorFontSize, self::EditorFontFamily, self::EditorLineHeight, self::EditorWordWrap, self::EditorShowLineNumbers, self::EditorNewNoteTemplateEnabled, self::EditorNewNoteTemplate => SettingGroup::Editor,
            self::CheckExternalChanges, self::CurrentVault => SettingGroup::General,
        };
    }

    public function default(): mixed
    {
        return match ($this) {
            self::StorageRootPath => null,
            self::StorageFolderName => StoragePathService::FOLDER_NAME,
            self::AppearanceTheme => Theme::System->value,
            self::EditorFontSize => 16,
            self::EditorFontFamily => EditorFontFamily::Sans->value,
            self::EditorLineHeight => 1.6,
            self::EditorWordWrap => true,
            self::EditorShowLineNumbers => false,
            self::EditorNewNoteTemplateEnabled => true,
            self::EditorNewNoteTemplate => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE,
            self::CheckExternalChanges => true,
            self::CurrentVault => null,
        };
    }

    /**
     * The part of the key after the group prefix, e.g. `root_path` for `storage.root_path`.
     */
    public function field(): string
    {
        return Str::after($this->value, '.');
    }
}
