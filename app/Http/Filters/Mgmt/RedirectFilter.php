<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;

class RedirectFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['source', 'target', 'status_code', 'hits', 'last_used_at', 'created_at', 'updated_at'];

    public function source($value)
    {
        $this->applyDynamicFilter('source', $value);
    }

    public function target($value)
    {
        $this->applyDynamicFilter('target', $value);
    }

    public function status_code($value)
    {
        $this->applyRangeFilter('status_code', $value);
    }

    public function hits($value)
    {
        $this->applyRangeFilter('hits', $value);
    }

    public function last_used_at($value)
    {
        $this->applyRangeFilter('last_used_at', $value);
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
