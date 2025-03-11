<?php

namespace App\Models\User;

use App\Models\Settings;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

/**
 * @property bool $space
 * @property bool $user
 */
class UserSettings extends Settings
{
    protected array $defaults = [
    ];

    public static function castUsing(array $arguments)
    {
        return new class implements CastsAttributes, SerializesCastableAttributes {
            public function get($model, string $key, $value, array $attributes)
            {
                return UserSettings::make($value ? json_decode($value, true) : []);
            }

            public function set($model, string $key, $value, array $attributes)
            {
                return json_encode($value->toArray() ?? []);
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return json_encode($value->toArray());
            }
        };
    }
}
