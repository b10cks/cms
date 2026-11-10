<?php

namespace App\Enums;

/** Where a skill or knowledge document lives. Built-ins are the `system` layer on disk. */
enum AiScope: string
{
    case Space = 'space';
    case Team = 'team';
    case System = 'system';
}
