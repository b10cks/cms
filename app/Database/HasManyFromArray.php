<?php

namespace App\Database;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

class HasManyFromArray extends Relation
{
    protected string $foreignKey;

    protected string $localKey;

    protected string $arrayAttribute;

    public function __construct(Builder $query, Model $parent, string $arrayAttribute, string $foreignKey, string $localKey)
    {
        $this->arrayAttribute = $arrayAttribute;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;

        parent::__construct($query, $parent);
    }

    public function addConstraints(): void
    {
        if (static::$constraints) {
            $ids = $this->getArrayValue();

            if (empty($ids)) {
                // If no IDs, return empty result
                $this->query->whereRaw('1 = 0');
            } else {
                $this->query->whereIn($this->foreignKey, $ids);
            }
        }
    }

    public function addEagerConstraints(array $models): void
    {
        $ids = collect($models)
            ->map(fn($model) => $this->getArrayValueFromModel($model))
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (empty($ids)) {
            $this->query->whereRaw('1 = 0');
        } else {
            $this->query->whereIn($this->foreignKey, $ids);
        }
    }

    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, $relation): array
    {
        if ($results->isEmpty()) {
            return $models;
        }

        // Create a dictionary of related models by their key
        $dictionary = $results->keyBy($this->foreignKey);

        // Match each model with its related models
        foreach ($models as $model) {
            $ids = $this->getArrayValueFromModel($model);
            $matched = collect($ids)
                ->map(fn($id) => $dictionary->get($id))
                ->filter()
                ->values();

            $model->setRelation($relation, $matched);
        }

        return $models;
    }

    public function getResults(): Collection
    {
        if (is_null($this->parent->{$this->arrayAttribute})) {
            return $this->related->newCollection();
        }

        return $this->query->get();
    }

    protected function getArrayValue(): array
    {
        return $this->getArrayValueFromModel($this->parent);
    }

    protected function getArrayValueFromModel(Model $model): array
    {
        $value = $model->{$this->arrayAttribute};

        if (is_null($value)) {
            return [];
        }

        if (is_string($value)) {
            // Handle JSON string
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        if (is_array($value)) {
            return $value;
        }

        // Handle single value
        return [$value];
    }

    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->getTable() . '.' . $this->foreignKey;
    }

    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        return $query->select($columns);
    }
}
