<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class RedirectFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['external_id', 'source', 'target', 'status_code', 'hits', 'last_used_at', 'created_at', 'updated_at'];

    public function source($value)
    {
        $this->applyDynamicFilter('source', $value);
    }

    public function external_id($value)
    {
        $this->applyDynamicFilter('external_id', $value);
    }

    public function target($value)
    {
        $this->applyDynamicFilter('target', $value);
    }

    public function status_code($value)
    {
        $this->applyAdvancedRangeFilter('status_code', $value);
    }

    public function hits($value)
    {
        $this->applyAdvancedRangeFilter('hits', $value);
    }

    public function last_used_at($value)
    {
        $this->applyAdvancedDateFilter('last_used_at', $value);
    }

    public function created_at($value)
    {
        $this->applyAdvancedDateFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyAdvancedDateFilter('updated_at', $value);
    }
}
