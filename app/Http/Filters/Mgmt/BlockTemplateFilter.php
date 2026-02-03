<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class BlockTemplateFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }
}
