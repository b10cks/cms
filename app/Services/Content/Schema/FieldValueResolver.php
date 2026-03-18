<?php

namespace App\Services\Content\Schema;

use Illuminate\Support\Arr;

class FieldValueResolver
{
    public function overlay(array $base, array $overrides): array
    {
        return array_replace_recursive($base, $overrides);
    }

    public function get(array $data, string $path): mixed
    {
        return Arr::get($data, $path);
    }
}
