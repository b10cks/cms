<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class IconFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['key', 'name', 'external_id', 'created_at', 'updated_at'];

    public function key($value)
    {
        $this->builder->where('key', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function tags($value)
    {
        foreach ($this->ensureArray($value) as $tag) {
            $this->builder->whereJsonContains('tags', $tag);
        }
    }

    public function q($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('key', 'LIKE', "%{$value}%")
                ->orWhere('name', 'LIKE', "%{$value}%")
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
