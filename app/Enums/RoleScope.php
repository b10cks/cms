<?php

namespace App\Enums;

enum RoleScope: string
{
    case TEAM = 'team';
    case SPACE = 'space';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
