<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class SpaceBlueprintFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['name', 'created_at', 'updated_at'];

    public function name($value): void
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function team_id($value): void
    {
        $this->builder->where('team_id', $value);
    }

    public function created_by_id($value): void
    {
        $this->builder->where('created_by_id', $value);
    }

    public function created_at($value): void
    {
        $this->applyAdvancedDateFilter('created_at', $value);
    }

    public function updated_at($value): void
    {
        $this->applyAdvancedDateFilter('updated_at', $value);
    }
}
