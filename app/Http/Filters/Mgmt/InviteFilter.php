<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class InviteFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['email', 'role', 'created_at', 'expires_at'];

    public function email($value)
    {
        $this->applyDynamicFilter('email', $value);
    }

    public function role($value)
    {
        $this->applyDynamicFilter('role', $value);
    }

    public function status($value)
    {
        if ($value === 'pending') {
            $this->builder->pending();
        } elseif ($value === 'accepted') {
            $this->builder->accepted();
        } elseif ($value === 'expired') {
            $this->builder->expired();
        }
    }

    public function space_id($value)
    {
        $this->applyDynamicFilter('space_id', $value);
    }

    public function team_id($value)
    {
        $this->applyDynamicFilter('team_id', $value);
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function expires_at($value)
    {
        $this->applyRangeFilter('expires_at', $value);
    }
}
