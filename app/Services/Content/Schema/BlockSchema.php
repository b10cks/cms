<?php

namespace App\Services\Content\Schema;

use App\Services\Content\Schema\Types\TypeHandlerInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

class BlockSchema implements Arrayable
{
    protected Collection $fields;

    public function __construct(array $fields = [])
    {
        $this->fields = collect();

        foreach ($fields as $key => $fieldData) {
            // Make sure each field knows its key
            if (is_array($fieldData)) {
                $fieldData['key'] = $key;
            }

            $this->fields->put(
                $key,
                $fieldData instanceof SchemaField ? $fieldData : new SchemaField($key, $fieldData)
            );
        }
    }

    public function addField(string $key, array|SchemaField $field): self
    {
        if (is_array($field)) {
            $field['key'] = $key;
            $field = new SchemaField($key, $field);
        }

        $this->fields->put($key, $field);

        // Re-sort fields by position
        $this->fields = $this->fields->sortBy(function($field) {
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
