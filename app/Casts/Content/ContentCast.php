<?php

namespace App\Casts\Content;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class ContentCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Cast the given value.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (empty($value)) {
            return [];
        }

        $data = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return $data;
    }

    /**
     * Prepare the given value for storage.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }

    /**
     * Serialize the attribute when converting the model to an array.
     */
    public function serialize($model, string $key, $value, array $attributes)
    {
        return $value;
    }
}
