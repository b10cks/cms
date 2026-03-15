<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class AutomationFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'name',
        'trigger_type',
        'is_active',
        'execution_limit',
        'execution_count',
        'last_triggered_at',
        'created_at',
        'updated_at',
    ];

    public function q($value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $q = "%{$value}%";
            $query->where('name', 'like', $q)
                ->orWhere('description', 'like', $q);
        });
    }

    public function name($value): void
    {
        $this->applyDynamicFilter('name', $value);
    }

    public function is_active($value): void
    {
        $this->builder->where('is_active', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function trigger_type($value): void
    {
        $this->applyDynamicFilter('trigger_type', $value);
    }

    public function action_id($value): void
    {
        $this->applyDynamicFilter('action_id', $value);
    }

    public function action_type($value): void
    {
        $this->builder->whereHas('action', function ($query) use ($value) {
            $query->where('type', $value);
        });
    }

    public function table($value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('trigger_config->table', $value)
                ->orWhere('trigger_config->resource', $value);
        });
    }

    public function execution_limit($value): void
    {
        if ($value === null) {
            $this->builder->whereNull('execution_limit');
        } else {
            $this->applyRangeFilter('execution_limit', $value);
        }
    }

    public function execution_count($value): void
    {
        $this->applyRangeFilter('execution_count', $value);
    }
}
