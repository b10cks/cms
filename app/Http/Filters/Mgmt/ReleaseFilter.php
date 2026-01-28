<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ReleaseFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'publish_at', 'committed_at', 'published_at', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function external_id($value)
    {
        $this->builder->where('external_id', $value);
    }

    public function publish_at($value)
    {
        $this->applyRangeFilter('publish_at', $value);
    }

    public function committed_at($value)
    {
        $this->applyRangeFilter('committed_at', $value);
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

    public function isPublished($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereNotNull('published_at');
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereNull('published_at');
        }
    }

    public function isCommitted($value)
    {
        if ($value === 'true' || $value === '1') {
            $this->builder->whereNotNull('committed_at');
        } elseif ($value === 'false' || $value === '0') {
            $this->builder->whereNull('committed_at');
        }
    }

    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $builder->whereNull('deleted_at');
        return parent::apply($builder);
    }
}
