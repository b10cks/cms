<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class TeamFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'name',
        'created_at',
        'updated_at'
    ];

    public function name($value): void
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function parent_id($value): void
    {
        if ($value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    public function hasUsers($value): void
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->has('users');
        } else {
            $this->builder->doesntHave('users');
        }
    }

    public function hasSpaces($value): void
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->has('spaces');
        } else {
            $this->builder->doesntHave('spaces');
        }
    }
}
