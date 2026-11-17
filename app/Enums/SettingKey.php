<?php

namespace App\Enums;

use App\Services\MarkdownService;
use App\Services\StoragePathService;
use Illuminate\Support\Str;

enum SettingKey: string
{
    /**
     * Valid auto-lock intervals, in minutes (0 = never).
     *
     * @var list<int>
     */
    public const SECURITY_AUTO_LOCK_CHOICES = [0, 5, 15, 30, 60];

    case StorageRootPath = 'storage.root_path';
    case StorageFolderName = 'storage.folder_name';
    case AppearanceTheme = 'appearance.theme';
    case EditorFontSize = 'editor.font_size';
    case EditorFontFamily = 'editor.font_family';
    case EditorLineHeight = 'editor.line_height';
    case EditorWordWrap = 'editor.word_wrap';
    case EditorShowLineNumbers = 'editor.show_line_numbers';
    case EditorIndentSize = 'editor.indent_size';
    case EditorNewNoteTemplateEnabled = 'editor.new_note_template_enabled';
    case EditorNewNoteTemplate = 'editor.new_note_template';
    case EditorDefaultView = 'editor.default_view';
    case CheckExternalChanges = 'app.check_external_changes';
    case CurrentVault = 'app.current_vault';
    case SecurityAutoLockMinutes = 'security.auto_lock_minutes';
    case SecurityLockOnScreenLock = 'security.lock_on_screen_lock';

    public function type(): SettingType
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName, self::AppearanceTheme, self::EditorFontFamily, self::EditorNewNoteTemplate, self::EditorDefaultView, self::CurrentVault => SettingType::String,
            self::EditorFontSize, self::EditorIndentSize, self::SecurityAutoLockMinutes => SettingType::Integer,
            self::EditorLineHeight => SettingType::Float,
            self::EditorWordWrap, self::EditorShowLineNumbers, self::EditorNewNoteTemplateEnabled, self::CheckExternalChanges, self::SecurityLockOnScreenLock => SettingType::Boolean,
        };
    }

    public function group(): SettingGroup
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName => SettingGroup::Storage,
            self::AppearanceTheme => SettingGroup::Appearance,
            self::EditorFontSize, self::EditorFontFamily, self::EditorLineHeight, self::EditorWordWrap, self::EditorShowLineNumbers, self::EditorIndentSize, self::EditorNewNoteTemplateEnabled, self::EditorNewNoteTemplate, self::EditorDefaultView => SettingGroup::Editor,
            self::CheckExternalChanges, self::CurrentVault => SettingGroup::General,
            self::SecurityAutoLockMinutes, self::SecurityLockOnScreenLock => SettingGroup::Security,
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
            self::EditorIndentSize => 4,
            self::EditorNewNoteTemplateEnabled => true,
            self::EditorNewNoteTemplate => MarkdownService::DEFAULT_NEW_NOTE_TEMPLATE,
            self::EditorDefaultView => EditorDefaultView::Code->value,
            self::CheckExternalChanges => true,
            self::CurrentVault => null,
            self::SecurityAutoLockMinutes => 15,
            self::SecurityLockOnScreenLock => true,
        };
    }

    public function isPortable(): bool
    {
        return match ($this) {
            self::StorageRootPath, self::StorageFolderName, self::CurrentVault => false,
            self::AppearanceTheme,
            self::EditorFontSize,
            self::EditorFontFamily,
            self::EditorLineHeight,
            self::EditorWordWrap,
            self::EditorShowLineNumbers,
            self::EditorIndentSize,
            self::EditorNewNoteTemplateEnabled,
            self::EditorNewNoteTemplate,
            self::EditorDefaultView,
            self::CheckExternalChanges,
            self::SecurityAutoLockMinutes,
            self::SecurityLockOnScreenLock => true,
        };
    }

    /**
     * @return list<self>
     */
    public static function portableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $key): bool => $key->isPortable(),
        ));
    }

    /**
     * @return list<SettingGroup>
     */
    public static function portableGroups(): array
    {
        return [
            SettingGroup::Appearance,
            SettingGroup::Editor,
            SettingGroup::General,
            SettingGroup::Security,
        ];
    }

    /**
     * @return list<string>
     */
    public static function portableGroupValues(): array
    {
        return array_map(
            static fn (SettingGroup $group): string => $group->value,
            self::portableGroups(),
        );
    }

    /**
     * The part of the key after the group prefix, e.g. `root_path` for `storage.root_path`.
     */
    public function field(): string
    {
        return Str::after($this->value, '.');
    }
}
