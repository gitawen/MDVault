<?php

namespace App\Enums;

enum SettingGroup: string
{
    case General = 'general';
    case Storage = 'storage';
    case Editor = 'editor';
    case Appearance = 'appearance';
    case Backup = 'backup';
    case Security = 'security';

    public function label(): string
    {
        return match ($this) {
            self::General => 'General',
            self::Storage => 'Storage',
            self::Editor => 'Editor',
            self::Appearance => 'Appearance',
            self::Backup => 'Backup',
            self::Security => 'Security',
        };
    }

    public function isPortable(): bool
    {
        return match ($this) {
            self::Appearance, self::Editor, self::General, self::Security => true,
            self::Storage, self::Backup => false,
        };
    }
}
