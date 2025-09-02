<?php

namespace App\Http\Filters\Api;

use CodersCantina\Filter\AdvancedFilter;

class RedirectFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['source', 'target', 'status_code'];

    public function source($value): void
    {
        $this->applyDynamicFilter('source', $value);
    }

    public function target($value): void
    {
        $this->applyDynamicFilter('target', $value);
    }

    public function status_code($value): void
    {
        $this->applyDynamicFilter('status_code', $value);
    }

    public function q($value): void
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('source', 'LIKE', "%{$value}%")
                ->orWhere('target', 'LIKE', "%{$value}%");
        });
    }
}
