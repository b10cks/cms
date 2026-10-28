<?php

namespace App\Enums;

enum AiThreadKind: string
{
    /** A user's conversation, private until shared. */
    case Chat = 'chat';
    /** The standing thread of one schedule; each run appends a turn. */
    case Schedule = 'schedule';
    /** The space's standing thread of learning suggestions. */
    case Suggestions = 'suggestions';
}
