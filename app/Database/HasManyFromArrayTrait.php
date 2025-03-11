<?php

namespace App\Database;

trait HasManyFromArrayTrait
{
    /**
     * Define a HasManyFromArray relationship.
     */
    public function hasManyFromArray(string $related, string $arrayAttribute, ?string $foreignKey = null, ?string $localKey = null): HasManyFromArray
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $instance->getKeyName();
        $localKey = $localKey ?: $this->getKeyName();

        return new HasManyFromArray(
            $instance->newQuery(),
            $this,
            $arrayAttribute,
            $foreignKey,
            $localKey
        );
    }
}
