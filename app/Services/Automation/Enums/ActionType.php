<?php

namespace App\Services\Automation\Enums;

enum ActionType: string
{
    case WEBHOOK = 'webhook';
    case EMAIL = 'email';
    case VOID = 'void';
}
