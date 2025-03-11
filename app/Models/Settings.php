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
        $this->attributes = $attributes;
    }

    public static function make($attributes = [])
    {
        return new static($attributes);
    }

    public function toArray(): array
    {
        return $this->attributes + $this->defaults;
    }

    public function apply($attributes): void
    {
        $this->attributes = array_merge($this->attributes, $attributes);
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
        return array_key_exists($name, $this->attributes);
    }

    public function __unset(string $name): void
    {
        unset($this->attributes[$name]);
    }

}
