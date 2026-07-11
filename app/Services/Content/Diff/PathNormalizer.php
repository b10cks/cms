<?php

namespace App\Services\Content\Diff;

class PathNormalizer
{
    public function buildPath(string $prefix, string|int $key): string
    {
        if ($prefix === '') {
            return (string)$key;
        }

        return $prefix . '.' . $key;
    }

    public function getPathParts(string $path): array
    {
        return explode('.', $path);
    }

    public function getParentPath(string $path): ?string
    {
        $parts = $this->getPathParts($path);

        if (count($parts) <= 1) {
            return null;
        }

        array_pop($parts);
        return implode('.', $parts);
    }

    public function getKey(string $path): string
    {
        $parts = $this->getPathParts($path);
        return end($parts);
    }
}
