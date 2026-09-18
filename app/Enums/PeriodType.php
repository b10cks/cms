<?php

namespace App\Enums;

enum PeriodType: string
{
    case DAILY = 'daily';
    case WEEKLY = 'weekly';
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';
    case CUSTOM = 'custom';

    public static function default(): array
    {
        return [
            self::DAILY,
            self::WEEKLY,
            self::MONTHLY,
            self::YEARLY,
        ];
    }

    public function toCarbonPeriod(): string
    {
        return match ($this) {
            self::WEEKLY => 'week',
            self::MONTHLY => 'month',
            self::YEARLY => 'year',
            default => 'day',
        };
    }

    /**
     * Bucket key format. Weeks are ISO-8601: `o` is the week-numbering year,
     * so 2027-01-01 lands in 2026-53 rather than 2027-53.
     */
    public function toCarbonFormat(): string
    {
        return match ($this) {
            self::WEEKLY => 'o-W',
            self::MONTHLY => 'Y-m',
            self::YEARLY => 'Y',
            default => 'Y-m-d',
        };
    }
}
