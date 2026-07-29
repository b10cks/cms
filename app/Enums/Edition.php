<?php

namespace App\Enums;

enum Edition: string
{
    case SAAS = 'saas';
    case SELF_HOSTED = 'self-hosted';
}
