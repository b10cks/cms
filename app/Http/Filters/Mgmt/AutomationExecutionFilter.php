<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class AutomationExecutionFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'status',
        'duration',
        'started_at',
        'completed_at',
        'created_at',
    ];

    public function q($value): void
    {
        $this->builder->whereHas('automation', function ($query) use ($value) {
            $query->where('name', 'like', "%{$value}%")
                ->orWhere('description', 'like', "%{$value}%");
        });
    }

    public function automation_id($value): void
    {
        $this->applyDynamicFilter('automation_id', $value);
    }

    public function status($value): void
    {
        $this->applyDynamicFilter('status', $value);
    }
}
