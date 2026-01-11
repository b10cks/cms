<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class AssetFolderFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'external_id', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function storage_id($value)
    {
        $this->builder->where('storage_id', $value);
    }

    public function parent_id($value)
    {
        if ($value === null || $value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    public function search($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('name', 'LIKE', "%{$value}%")
                ->orWhere('description', 'LIKE', "%{$value}%");
        });
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
