<?php

namespace App\Services\Content\Diff;

interface DiffInterface
{
    public function diff(array $old, array $new): DiffResult;
}
