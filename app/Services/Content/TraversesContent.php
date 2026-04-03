<?php

namespace App\Services\Content;

trait TraversesContent
{
    /**
     * @return \Generator<int, array{value: array<array-key, mixed>, path: array<int, int|string>}>
     */
    protected function walkContent(array $data, array $path = []): \Generator
    {
        yield [
            'value' => $data,
            'path' => $path,
        ];

        foreach ($data as $key => $value) {
            if (! \is_array($value)) {
                continue;
            }

            yield from $this->walkContent($value, [...$path, $key]);
        }
    }

    /**
     * @param  callable(array<array-key, mixed>, array<int, int|string>): ?array<array-key, mixed>  $callback
     * @return array<array-key, mixed>
     */
    protected function transformContent(array $data, callable $callback, array $path = []): array
    {
        $replacement = $callback($data, $path);

        if ($replacement !== null) {
            return $replacement;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $result[$key] = \is_array($value)
                ? $this->transformContent($value, $callback, [...$path, $key])
                : $value;
        }

        return $result;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @param  array<string, mixed>  $criteria
     */
    protected function matchesContentCriteria(array $value, array $criteria): bool
    {
        return array_all(
            $criteria,
            fn (mixed $expected, string $key): bool => array_key_exists($key, $value) && $value[$key] === $expected,
        );
    }
}
