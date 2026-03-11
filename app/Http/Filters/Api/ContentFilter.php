<?php

namespace App\Http\Filters\Api;

use App\Models\Space\Block;
use CodersCantina\Filter\AdvancedFilter;

class ContentFilter extends AdvancedFilter
{
    public function published_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.published_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.updated_at', $value);
    }

    public function created_at($value)
    {
        $this->applyAdvancedRangeFilter('contents.created_at', $value);
    }

    public function language($value)
    {
        $this->builder->where('contents.language_iso', $value);
    }

    public function content_type($value)
    {
        $this->builder->whereIn('contents.block_id', Block::where('type', $value));
    }

    public function parent_id($value)
    {
        $this->applyDynamicFilter('contents.parent_id', $value);
    }
}
