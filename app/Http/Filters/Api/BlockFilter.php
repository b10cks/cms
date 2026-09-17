<?php

namespace App\Http\Filters\Api;

use CodersCantina\Filter\AdvancedFilter;
use Illuminate\Support\Str;

class BlockFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'is_nestable', 'is_root', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function slug($value)
    {
        // `slug` is an ASCII column; MySQL errors (3988) on non-ASCII input instead of matching nothing.
        if (is_string($value) && ! Str::isAscii($value)) {
            $this->builder->whereRaw('1 = 0');

            return;
        }

        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    public function folder_id($value)
    {
        $this->builder->where('folder_id', $value);
    }

    public function is_nestable($value)
    {
        $this->builder->where('is_nestable', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function is_root($value)
    {
        $this->builder->where('is_root', filter_var($value, FILTER_VALIDATE_BOOLEAN));
    }

    public function tags($value)
    {
        if (is_array($value)) {
            $this->builder->whereJsonContains('tags', $value);
        } else {
            $this->builder->whereJsonContains('tags', [$value]);
        }
    }

    public function created_at($value)
    {
        $this->applyAdvancedRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyAdvancedRangeFilter('updated_at', $value);
    }
}
