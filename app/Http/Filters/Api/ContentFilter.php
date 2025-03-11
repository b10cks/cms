<?php

namespace App\Http\Filters\Api;

use App\Models\Space\Block;
use CodersCantina\Filter\AdvancedFilter;
use CodersCantina\Filter\ExtendedFilter;

class ContentFilter extends AdvancedFilter
{
    public function published_at($value)
    {
        $this->applyAdvancedRangeFilter('published_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyAdvancedRangeFilter('updated_at', $value);
    }

    public function created_at($value)
    {
        $this->applyAdvancedRangeFilter('created_at', $value);
    }

    public function language($value)
    {
        $this->builder->where('language_iso', $value);
    }

    public function content_type($value)
    {
        $this->builder->whereIn('block_id', Block::where('type', $value));
    }
}
