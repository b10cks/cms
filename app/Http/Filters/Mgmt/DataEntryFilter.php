<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class DataEntryFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['key', 'value', 'created_at', 'updated_at'];

    public function q($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('key', 'LIKE', "%{$value}%")
                ->orWhere('value', 'LIKE', "%{$value}%");
        });
    }

    public function key($value)
    {
        $this->builder->where('key', 'LIKE', "%{$value}%");
    }

    public function value($value)
    {
        $this->builder->where('value', 'LIKE', "%{$value}%");
    }

    public function active($value)
    {
        $this->builder->where('is_active', $value === 'true');
    }
}
