<?php

namespace App\Services\Content\Schema;

use App\Services\Content\Schema\Types\TypeHandlerInterface;
use Illuminate\Contracts\Support\Arrayable;

class SchemaField implements Arrayable
{
    protected string $key;
    protected array $attributes;

    public function __construct(string $key, array $attributes)
    {
        $this->key = $key;
        $this->attributes = $attributes;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getType(): string
    {
        return $this->attributes['type'] ?? '';
    }

    public function getLabel(): string
    {
        return $this->attributes['label'] ?? $this->key;
    }

    public function getAttribute(string $name, $default = null)
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function isRequired(): bool
    {
        return (bool) ($this->attributes['required'] ?? false);
    }

    public function isTranslatable(): bool
    {
        return (bool) ($this->attributes['translatable'] ?? false);
    }

    public function getDependencies(): array
    {
        return $this->attributes['dependencies'] ?? [];
    }

    public function toArray(): array
    {
        return $this->attributes;
    }
}
