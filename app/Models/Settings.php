<?php

namespace App\Models;

use Illuminate\Contracts\Database\Eloquent\Castable;

/**
 * @property string $languageIso
 */
abstract class Settings implements Castable
{
    protected array $attributes;

    protected array $defaults = [
        // 'languageIso' => 'en', // use browser language
    ];

    public function __construct($attributes = [])
    {
        $this->attributes = is_array($attributes) ? $attributes : [];
    }

    public static function make($attributes = []): static
    {
        return new static($attributes);
    }

    public function toArray(): array
    {
        return $this->attributes + $this->defaults;
    }

    public function apply($attributes): void
    {
        $this->attributes = [...$this->attributes, ...(is_array($attributes) ? $attributes : [])];
    }

    /**
     * Build validation rules for this settings object.
     *
     * Child classes should override this to expose their supported structure.
     *
     * @param  string|null  $prefix
     * @param  bool  $partial
     * @return array<string, mixed>
     */
    public static function toValidator(?string $prefix = null, bool $partial = false): array
    {
        $rules = static::validationRules($partial);

        if ($prefix === null || $prefix === '') {
            return $rules;
        }

        return static::prefixRules($rules, $prefix);
    }

    /**
     * Validation rules for the concrete settings class.
     *
     * @param  bool  $partial
     * @return array<string, mixed>
     */
    public static function validationRules(bool $partial = false): array
    {
        return [];
    }

    /**
     * Optional OpenAPI / documentation metadata for settings fields.
     *
     * Supported shape:
     * - ['field' => ['description' => '...', 'example' => ..., 'format' => '...']]
     *
     * @return array<string, array<string, mixed>>
     */
    public static function schemaMetadata(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected static function prefixRules(array $rules, string $prefix): array
    {
        $prefixed = [];

        foreach ($rules as $key => $rule) {
            $prefixed[$prefix . '.' . $key] = $rule;
        }

        return $prefixed;
    }

    public function __get(string $name)
    {
        return data_get($this->attributes, $name, data_get($this->defaults, $name, false));
    }

    public function __set(string $name, $value): void
    {
        data_set($this->attributes, $name, $value);
    }

    public function __isset(string $name): bool
    {
        return \array_key_exists($name, $this->attributes);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }
}
