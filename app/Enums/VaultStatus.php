<?php

namespace App\Enums;

enum VaultStatus: string
{
    case Active = 'active';
    case Missing = 'missing';
}
