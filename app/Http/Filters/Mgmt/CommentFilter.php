<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class CommentFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['created_at', 'updated_at', 'is_resolved'];

    public function content_id($value)
    {
        $this->builder->where('content_id', $value);
    }

    public function content_version_id($value)
    {
        $this->builder->where('content_version_id', $value);
    }

    public function author_id($value)
    {
        $this->builder->where('author_id', $value);
    }

    public function is_resolved($value)
    {
        $this->builder->where('is_resolved', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function item_id($value)
    {
        $this->builder->where('item_id', $value);
    }

    public function field($value)
    {
        $this->builder->where('field', $value);
    }

    public function q($value)
    {
        $this->builder->where('body', 'LIKE', "%{$value}%");
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
