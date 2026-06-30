<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class AssetTagFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'created_at', 'updated_at'];

    public function q($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function icon($value)
    {
        $this->builder->where('icon', $value);
    }

    public function color($value)
    {
        $this->builder->where('color', $value);
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }

    public function assets_count($value)
    {
        $this->builder->has('assets', '>=', (int) $value);
    }
}
