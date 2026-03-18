<?php

namespace App\Casts\Content;

use App\Services\Content\Schema\BlockSchema;
use App\Services\Content\Schema\SchemaNormalizer;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;

class SchemaCast implements CastsAttributes, SerializesCastableAttributes
{
    /**
     * Cast the given value.
     */
    public function get($model, string $key, $value, array $attributes)
    {
        if (empty($value)) {
            return new BlockSchema();
        }

        $data = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new BlockSchema();
        }

        return BlockSchema::fromArray($data);
    }

    /**
     * Prepare the given value for storage.
     */
    public function set($model, string $key, $value, array $attributes)
    {
        $normalizer = app(SchemaNormalizer::class);

        if ($value instanceof BlockSchema) {
            return json_encode($value->toArray());
        }

        if (is_array($value)) {
            return json_encode($normalizer->normalizeSchema($value));
        }

        return $value;
    }

    /**
     * Serialize the attribute when converting the model to an array.
     */
    public function serialize($model, string $key, $value, array $attributes)
    {
        if ($value instanceof BlockSchema) {
            return $value->toArray();
        }

        return $value;
    }
}
