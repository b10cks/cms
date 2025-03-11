<?php

namespace App\Http\Filters\Mgmt;

use App\Models\Management\Space;
use CodersCantina\Filter\ExtendedFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ContentMenuFilter extends ExtendedFilter
{
    protected array $sortableColumns = ['name', 'slug', 'language_iso', 'published_at', 'created_at', 'updated_at'];

    protected Space $space;

    public function setSpace(Space $space): void
    {
        $this->space = $space;
    }

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

    public function apply(Relation|Builder|\Illuminate\Database\Query\Builder $builder): Builder|\Illuminate\Database\Query\Builder|Relation
    {
        $builder = parent::apply($builder);

        $filters = $this->getFilters();
        // if language_iso is not present, set it to null
        if (!array_key_exists('language_iso', $filters)) {
            $builder->where('language_iso', data_get($this->space->settings, 'default_language', null));
        }

        return $builder;
    }
}
