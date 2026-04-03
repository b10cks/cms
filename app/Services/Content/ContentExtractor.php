<?php

namespace App\Services\Content;

trait ContentExtractor
{
    use TraversesContent;

    /**
     * @param  callable(array<array-key, mixed>, array<int, int|string>): mixed  $callback
     * @return array<int, mixed>
     */
    protected function extract(array $data, callable $callback): array
    {
        return collect(iterator_to_array($this->walkContent($data), false))
            ->flatMap(
                fn (array $node): array => $this->normalizeExtractedValues(
                    $callback($node['value'], $node['path'])
                )
            )
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @return array<int, mixed>
     */
    protected function extractMatchingField(array $data, array $criteria, string $field): array
    {
        return $this->extract(
            $data,
            fn (array $value): mixed => $this->matchesContentCriteria($value, $criteria)
                ? ($value[$field] ?? null)
                : null,
        );
    }

    /**
     * @return array<int, mixed>
     */
    protected function normalizeExtractedValues(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $values = \is_array($value) ? $value : [$value];

        return array_values(
            array_filter(
                $values,
                static fn (mixed $item): bool => $item !== null && $item !== '',
            )
        );
    }
}
