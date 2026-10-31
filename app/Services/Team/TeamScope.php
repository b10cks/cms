<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use Illuminate\Support\Facades\DB;

/**
 * A team with everything below it: the ids of its subtree teams and their
 * spaces, plus its ancestor chain. Team roles cascade downward, so this is
 * the set a team owner looks after on the overview, the matrix and the
 * activity log.
 */
final readonly class TeamScope
{
    /**
     * @param  array<int, string>  $teamIds  The team first, then its descendants.
     * @param  array<int, string>  $ancestorIds  Nearest parent first.
     * @param  array<int, string>  $spaceIds
     * @param  array<string, string|null>  $parents  Parent id per subtree team.
     */
    private function __construct(
        public Team $team,
        public array $teamIds,
        public array $ancestorIds,
        public array $spaceIds,
        public array $parents,
    ) {}

    public static function for(Team $team): self
    {
        $teams = DB::table('teams')
            ->select('id', 'parent_id')
            ->whereNull('deleted_at')
            ->get();

        $childrenByParent = $teams->groupBy('parent_id');
        $parentById = $teams->pluck('parent_id', 'id');

        $teamIds = [];
        $parents = [];
        $stack = [$team->id];
        while ($stack !== []) {
            $current = array_shift($stack);
            if (array_key_exists($current, $parents)) {
                continue;
            }
            $teamIds[] = $current;
            $parents[$current] = $current === $team->id ? $team->parent_id : $parentById->get($current);
            foreach ($childrenByParent->get($current, collect()) as $child) {
                $stack[] = $child->id;
            }
        }

        $ancestorIds = [];
        $current = $team->parent_id;
        while ($current !== null && ! in_array($current, $ancestorIds, true) && $parentById->has($current)) {
            $ancestorIds[] = $current;
            $current = $parentById->get($current);
        }

        $spaceIds = DB::table('spaces')
            ->whereIn('team_id', $teamIds)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        return new self($team, $teamIds, $ancestorIds, $spaceIds, $parents);
    }
}
