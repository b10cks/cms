<?php

namespace App\Services\Content;

trait ContentReplacer
{
    protected function replace(array $data, array $criteria, callable $cb): array
    {
        return $this->replaceRecursive($data, $criteria, $cb);
    }

    protected function replaceRecursive(array $data, array $criteria, callable $cb): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isMatchingReplaceStructure($value, $criteria)) {
                $result[$key] = $cb($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->replaceRecursive($value, $criteria, $cb);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function isMatchingReplaceStructure($value, array $criteria): bool
    {
        if (!is_array($value)) {
            return false;
        }

        return array_all($criteria, fn ($expected, $key) => isset($value[$key]) && $value[$key] === $expected);
    }
}
