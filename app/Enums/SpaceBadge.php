<?php

namespace App\Enums;

enum SpaceBadge: string
{
    case Sandbox    = 'sandbox';
    case Development = 'development';
    case Staging    = 'staging';
    case Production = 'production';

    /**
     * Return the Tailwind / CSS color token associated with this badge.
     * These map to the badge variant names used on the frontend.
     */
    public function color(): string
    {
        return match ($this) {
            self::Sandbox    => 'secondary',
            self::Development => 'info',
            self::Staging    => 'warning',
            self::Production => 'success',
        };
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sandbox    => 'Sandbox',
            self::Development => 'Development',
            self::Staging    => 'Staging',
            self::Production => 'Production',
        };
    }

    /**
     * Return all predefined badge values as a plain array of strings.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Return a map of value => color for all predefined badges.
     *
     * @return array<string, string>
     */
    public static function colorMap(): array
    {
        return array_combine(
            self::values(),
            array_map(fn(self $case) => $case->color(), self::cases()),
        );
    }

    /**
     * Try to resolve a string to a predefined badge; returns null for custom values.
     */
    public static function tryFromValue(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
