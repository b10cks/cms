<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class BlockVersionFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['created_at'];

    public function commit_message($value)
    {
        $this->builder->where('commit_message', 'LIKE', "%{$value}%");
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }
}
