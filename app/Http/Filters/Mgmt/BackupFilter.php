<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class BackupFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'state', 'progress', 'created_at', 'updated_at', 'expires_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function state($value)
    {
        $this->builder->where('state', $value);
    }

    public function created_at($value)
    {
        $this->applyRangeFilter('created_at', $value);
    }

    public function updated_at($value)
    {
        $this->applyRangeFilter('updated_at', $value);
    }

    public function expires_at($value)
    {
        $this->applyRangeFilter('expires_at', $value);
    }

    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        return parent::apply($builder);
    }
}
