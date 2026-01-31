<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class TeamMemberFilter extends AdvancedFilter
{
    protected array $sortableColumns = [
        'firstname',
        'lastname',
        'email',
        'created_at',
        'last_login_at'
    ];

    public function name($value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('firstname', 'LIKE', "%{$value}%")
                ->orWhere('lastname', 'LIKE', "%{$value}%");
        });
    }

    public function email($value): void
    {
        $this->builder->where('email', 'LIKE', "%{$value}%");
    }

    public function isActive($value): void
    {
        if (filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            $this->builder->whereNull('deleted_at');
        } else {
            $this->builder->whereNotNull('deleted_at');
        }
    }

    public function created_at($value): void
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function last_login_at($value): void
    {
        $this->applyRangeFilter('last_login_at', $value);
    }
}
