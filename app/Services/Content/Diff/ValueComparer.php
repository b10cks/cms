<?php

namespace App\Services\Content\Diff;

class ValueComparer
{
    public function areEqual(mixed $value1, mixed $value2): bool
    {
        if (is_array($value1) && is_array($value2)) {
            return $this->compareArrays($value1, $value2);
        }

        if (is_object($value1) && is_object($value2)) {
            return $this->compareObjects($value1, $value2);
        }

        return $value1 === $value2;
    }

    private function compareArrays(array $array1, array $array2): bool
    {
        if (count($array1) !== count($array2)) {
            return false;
        }

        return array_all($array1, fn ($value, $key) => array_key_exists($key, $array2) && $this->areEqual($value, $array2[$key]));

    }

    private function compareObjects(object $obj1, object $obj2): bool
    {
        return serialize($obj1) === serialize($obj2);
    }
}
