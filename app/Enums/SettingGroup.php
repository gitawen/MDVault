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
}
