<?php

namespace App\Services\Content\Schema;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

class BlockSchema implements Arrayable
{
    protected Collection $fields;

    public function __construct(array $fields = [])
    {
        $this->fields = collect();

        foreach ($fields as $key => $fieldData) {
            $this->fields->put(
                $key,
                $fieldData instanceof SchemaField ? $fieldData : new SchemaField($key, $fieldData)
            );
        }

        $this->fields = $this->fields->sortBy(
            static fn (SchemaField $field): int => (int) $field->getAttribute('order', 999)
        );
    }

    public function addField(string $key, array|SchemaField $field): self
    {
        if (is_array($field)) {
            $field = new SchemaField($key, $field);
        }

        $this->fields->put($key, $field);

        // Re-sort fields by position
        $this->fields = $this->fields->sortBy(function (SchemaField $field) {
            return $field->getAttribute('order', 999);
        });

        return $this;
    }

    public function getField(string $key): ?SchemaField
    {
        return $this->fields->get($key);
    }

    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function hasField(string $key): bool
    {
        return $this->fields->has($key);
    }

    public function toArray(): array
    {
        $result = [];

        foreach ($this->fields as $key => $field) {
            $result[$key] = $field->toArray();
        }

        return $result;
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }
}
