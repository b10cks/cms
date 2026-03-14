<?php

namespace App\Models\Space;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class AssetFolderSettings extends Settings
{
    protected array $defaults = [
        'field_overrides' => [],
        'additional_fields' => [],
    ];

    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return AssetFolderSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, mixed $value, array $attributes)
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
