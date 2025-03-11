<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;

class ContentFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'language_iso', 'published_at', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function slug($value)
    {
        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    public function block_id($value)
    {
        $this->builder->where('block_id', $value);
    }

    public function parent_id($value)
    {
        if ($value === 'null') {
            $this->builder->whereNull('parent_id');
        } else {
            $this->builder->where('parent_id', $value);
        }
    }

    public function language_iso($value)
    {
        $this->builder->where('language_iso', $value);
    }

    public function i18n_parent_id($value)
    {
        if ($value === 'null') {
            $this->builder->whereNull('i18n_parent_id');
        } else {
            $this->builder->where('i18n_parent_id', $value);
        }
    }

    public function published($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereNotNull('published_at');
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereNull('published_at');
        }
    }

    public function published_at($value)
    {
        $this->applyRangeFilter('published_at', $value);
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
