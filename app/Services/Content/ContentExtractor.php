<?php

namespace App\Services\Content;

trait ContentExtractor
{
    protected function extract(array $data, array $criteria, string $field): array
    {
        $results = [];
        $this->extractRecursive($data, $criteria, $field, $results);

        return array_unique($results);
    }

    protected function extractRecursive(array $data, array $criteria, string $field, array &$results): void
    {
        foreach ($data as $value) {
            if ($this->isMatchingExtractStructure($value, $criteria, $field)) {
                $extractedValue = $value[$field] ?? null;
                if (!empty($extractedValue)) {
                    $results[] = $extractedValue;
                }
            }

            if (is_array($value)) {
                $this->extractRecursive($value, $criteria, $field, $results);
            }
        }
    }

    private function isMatchingExtractStructure($value, array $criteria, $field): bool
    {
        if (!is_array($value) || !isset($value[$field])) {
            return false;
        }

        return array_all($criteria, fn ($expected, $key) => isset($value[$key]) && $value[$key] === $expected);
    }
}
