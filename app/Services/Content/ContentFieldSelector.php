<?php

namespace App\Services\Content;

class ContentFieldSelector
{
    /**
     * Parse a comma-separated path string into a validated list of dot-notation paths.
     * Each segment must match [a-zA-Z0-9_]; invalid paths are silently dropped.
     */
    public static function parsePaths(string $param): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $param)),
            fn (string $path) => self::isValidPath($path),
        ));
    }

    public static function isValidPath(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        foreach (explode('.', $path) as $segment) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $segment)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Return a new array containing only the given dot-notation paths.
     */
    public static function take(array $content, array $paths): array
    {
        $result = [];
        $missing = new \stdClass();

        foreach ($paths as $path) {
            $segments = explode('.', $path);
            $value = self::getNested($content, $segments, $missing);
            if ($value !== $missing) {
                self::setNested($result, $segments, $value);
            }
        }

        return $result;
    }

    /**
     * Return a new array with the given dot-notation paths removed.
     */
    public static function except(array $content, array $paths): array
    {
        foreach ($paths as $path) {
            self::unsetNested($content, explode('.', $path));
        }

        return $content;
    }

    private static function getNested(array $data, array $segments, mixed $default): mixed
    {
        foreach ($segments as $segment) {
            if (!is_array($data) || !array_key_exists($segment, $data)) {
                return $default;
            }
            $data = $data[$segment];
        }

        return $data;
    }

    private static function setNested(array &$target, array $segments, mixed $value): void
    {
        $key = array_shift($segments);
        if (empty($segments)) {
            $target[$key] = $value;
            return;
        }
        if (!isset($target[$key]) || !is_array($target[$key])) {
            $target[$key] = [];
        }
        self::setNested($target[$key], $segments, $value);
    }

    private static function unsetNested(array &$data, array $segments): void
    {
        $key = array_shift($segments);
        if (!array_key_exists($key, $data)) {
            return;
        }
        if (empty($segments)) {
            unset($data[$key]);
            return;
        }
        if (is_array($data[$key])) {
            self::unsetNested($data[$key], $segments);
        }
    }
}
