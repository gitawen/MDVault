<?php

namespace App\Enums;

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
    case CheckExternalChanges = 'app.check_external_changes';

    public function type(): SettingType
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName, self::AppearanceTheme, self::EditorFontFamily => SettingType::String,
            self::EditorFontSize => SettingType::Integer,
            self::EditorLineHeight => SettingType::Float,
            self::EditorWordWrap, self::EditorShowLineNumbers, self::CheckExternalChanges => SettingType::Boolean,
        };
    }

    public function group(): SettingGroup
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName => SettingGroup::Storage,
            self::AppearanceTheme => SettingGroup::Appearance,
            self::EditorFontSize, self::EditorFontFamily, self::EditorLineHeight, self::EditorWordWrap, self::EditorShowLineNumbers => SettingGroup::Editor,
            self::CheckExternalChanges => SettingGroup::General,
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
            self::CheckExternalChanges => true,
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
