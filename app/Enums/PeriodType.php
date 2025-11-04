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
            self::DAILY => 'day',
            self::WEEKLY => 'week',
            self::MONTHLY => 'month',
            self::YEARLY => 'year',
            default => null,
        };
    }

    public function toCarbonFormat(): string
    {
        return match ($this) {
            self::DAILY => 'Y-m-d',
            self::WEEKLY => 'Y-W',
            self::MONTHLY => 'Y-m',
            self::YEARLY => 'Y',
            default => null,
        };
    }
    public function toMysqlDateFormat(): string
    {
        return match ($this) {
            self::DAILY => '%Y-%m-%d',
            self::WEEKLY => '%Y-%u',
            self::MONTHLY => '%Y-%m',
            self::YEARLY => '%Y',
            default => '%Y-%m-%d',
        };
    }
}
