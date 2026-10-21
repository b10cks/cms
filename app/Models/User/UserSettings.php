<?php

namespace App\Models\User;

use App\Models\Settings;
use DateTimeZone;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Validation\Rule;

class UserSettings extends Settings
{
    protected array $defaults = [
        'languageIso' => 'en',
        'extendedSidebar' => true,
        'dateOrder' => 'auto',
        'clock' => 'auto',
        'timeZone' => 'auto',
    ];

    public static function validationRules(bool $partial = false): array
    {
        return [
            'languageIso' => [
                ...($partial ? ['sometimes'] : []),
                'nullable',
                'string',
                'min:2',
                'max:5',
            ],
            'extendedSidebar' => [
                ...($partial ? ['sometimes'] : []),
                'boolean',
            ],
            'dateOrder' => [
                ...($partial ? ['sometimes'] : []),
                'string',
                Rule::in(['auto', 'dmy', 'mdy', 'ymd']),
            ],
            'clock' => [
                ...($partial ? ['sometimes'] : []),
                'string',
                Rule::in(['auto', '24h', '12h']),
            ],
            // Backward-compatible names too: browsers still report zones such as Asia/Calcutta.
            'timeZone' => [
                ...($partial ? ['sometimes'] : []),
                'string',
                'max:64',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== 'auto' && ! \in_array($value, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
                        $fail(__('validation.timezone', ['attribute' => $attribute]));
                    }
                },
            ],
        ];
    }

    public static function schemaMetadata(): array
    {
        return [
            'languageIso' => [
                'description' => 'Preferred UI language as ISO language code.',
                'example' => 'en',
            ],
            'extendedSidebar' => [
                'description' => 'Whether the management sidebar should be shown in its extended state.',
                'example' => true,
            ],
            'dateOrder' => [
                'description' => 'Order of day, month and year in displayed dates: auto (follow the language), dmy, mdy or ymd.',
                'example' => 'auto',
            ],
            'clock' => [
                'description' => 'Clock for displayed times: auto (follow the language), 24h or 12h.',
                'example' => 'auto',
            ],
            'timeZone' => [
                'description' => 'Time zone for displayed times: auto (the browser zone) or an IANA zone name.',
                'example' => 'Europe/Vienna',
            ],
        ];
    }

    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return UserSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return json_encode(\is_array($value) ? $value : $value?->toArray() ?? []);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode(\is_array($value) ? $value : $value?->toArray() ?? []);
            }
        };
    }
}
