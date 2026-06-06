<?php

namespace App\Services\Automation\Enums;

enum TriggerType: string
{
    case ON_INSERT = 'on_insert';
    case ON_UPDATE = 'on_update';
    case ON_DELETE = 'on_delete';
    case TIME_BASED = 'time_based';
    case MANUAL = 'manual';
    case CONTENT_PUBLISHED = 'content_published';
    case CONTENT_UNPUBLISHED = 'content_unpublished';

    public function requiresScheduleConfig(): bool
    {
        return $this === self::TIME_BASED;
    }

    public function isContentLifecycle(): bool
    {
        return $this === self::CONTENT_PUBLISHED || $this === self::CONTENT_UNPUBLISHED;
    }
}
