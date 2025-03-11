<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class SpaceFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'state', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function slug($value)
    {
        $this->builder->where('slug', 'LIKE', "%{$value}%");
    }

    public function archived($value)
    {
        $this->builder->where('state', $value ? 'archived' : 'live');
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }

    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        if (!isset($this->filters['archived'])) {
            $builder->whereIn('state', ['live']);
        }

        return parent::apply($builder);
    }
}
