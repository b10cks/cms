<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class ContentSettings extends Settings
{
    protected array $defaults = [
        'disablePreview' => false,
        'i18n_mode_override' => 'inherit',
    ];

    /**
     * {@inheritDoc}
     */
    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes
        {
            public function get($model, string $key, $value, array $attributes)
            {
                return ContentSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return json_encode($value ?? []);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode($value);
            }
        };
    }
}
