<?php

namespace App\Enums;

enum AiRunState: string
{
    case Running = 'running';
    case AwaitingInput = 'awaiting_input';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
