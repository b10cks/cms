<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class AutomationActionFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'name',
        'type',
        'is_active',
        'last_executed_at',
        'created_at',
        'updated_at',
    ];

    public function q($value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('name', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%");
        });
    }

    public function name($value): void
    {
        $this->applyDynamicFilter('name', $value);
    }

    public function type($value): void
    {
        $this->applyDynamicFilter('type', $value);
    }

    public function is_active($value): void
    {
        $this->builder->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }
}
