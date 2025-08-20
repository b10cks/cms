<?php

namespace App\Http\Filters\Mgmt;

use CodersCantina\Filter\AdvancedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class AiModelFilter extends AdvancedFilter
{
    protected array $sortableColumns = ['name', 'model', 'provider', 'created_at', 'updated_at'];

    public function name($value)
    {
        $this->builder->where('name', 'LIKE', "%{$value}%");
    }

    public function model($value)
    {
        $this->builder->where('model', 'LIKE', "%{$value}%");
    }

    public function q($value)
    {
        $this->builder->where(function ($query) use ($value) {
            $query->where('name', 'LIKE', "%{$value}%")
                ->orWhere('model', 'LIKE', "%{$value}%")
                ->orWhere('provider', 'LIKE', "%{$value}%");
        });
    }

    public function provider($value)
    {
        $this->builder->where('provider', 'LIKE', "%{$value}%");
    }

    public function is_free($value)
    {
        if ($value === 'true') {
            $this->builder->where('is_free', true);
        } elseif ($value === 'false') {
            $this->builder->where('is_free', false);
        }
    }
    
    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $builder = parent::apply($builder);
        $builder->where('is_active', true);

        return $builder;
    }
}
