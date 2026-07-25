<?php

namespace App\Services\Content\Serial;

class SerialAllocation
{
    public function __construct(
        public readonly string $fieldKey,
        public readonly int $number,
        public readonly string $value,
    ) {}
}
