<?php

namespace App\Http\Filters\Mgmt;

use App\Models\Management\Team;
use App\Services\Team\TeamScope;
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

    /**
     * Spaces owned by the team. With `sub_teams=true` also those of every team
     * below it. Access is still limited by the controller.
     */
    public function team_id($value)
    {
        $team = Team::query()->find($value);
        $withSubTeams = filter_var($this->filters['sub_teams'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $this->builder->whereIn('team_id', $team && $withSubTeams ? TeamScope::for($team)->teamIds : [$value]);
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
