<?php

namespace App\Enums;

enum SpaceRoleKey: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case EDITOR = 'editor';
    case MEMBER = 'member';
    case BILLING = 'billing';
    case VIEWER = 'viewer';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
