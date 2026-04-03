<?php

namespace App\Services\Content;

trait ContentReplacer
{
    use TraversesContent;

    /**
     * @param  callable(array<array-key, mixed>, array<int, int|string>): bool  $matches
     * @param  callable(array<array-key, mixed>, array<int, int|string>): array<array-key, mixed>  $cb
     * @return array<array-key, mixed>
     */
    protected function replace(array $data, callable $matches, callable $cb): array
    {
        return $this->transformContent(
            $data,
            fn (array $value, array $path): ?array => $matches($value, $path)
                ? $cb($value, $path)
                : null,
        );
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  callable(array<array-key, mixed>, array<int, int|string>): array<array-key, mixed>  $cb
     * @return array<array-key, mixed>
     */
    protected function replaceMatching(array $data, array $criteria, callable $cb): array
    {
        return $this->replace(
            $data,
            fn (array $value): bool => $this->matchesContentCriteria($value, $criteria),
            $cb,
        );
    }
}
