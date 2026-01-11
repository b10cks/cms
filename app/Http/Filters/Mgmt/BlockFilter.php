<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class BlockFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'type', 'external_id', 'folder.name', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function slug($value)
    {
        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function folder_id($value)
    {
        $this->builder->where('folder_id', $value);
    }

    public function type($value)
    {
        if (is_array($value)) {
            $this->builder->whereIn('type', $value);
        } else {
            $this->builder->where('type', $value);
        }
    }

    public function tags($value)
    {
        if (is_array($value)) {
            $this->builder->whereJsonContains('tags', $value);
        } else {
            $this->builder->whereJsonContains('tags', [$value]);
        }
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
