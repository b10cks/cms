<?php

namespace App\Services\Ai\Knowledge;

use App\Enums\AiScope;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Auth\RoleService;
use Closure;

/** The owner layers a space resolves AI skills and knowledge through. */
class AiLayers
{
    public function __construct(private readonly RoleService $roles) {}

    /**
     * For a space its owning team, then that team's ancestors, nearest first.
     * For a team itself and its ancestors.
     *
     * @return list<string>
     */
    public function teamIds(Space|Team $owner): array
    {
        $team = $owner instanceof Space
            ? ($owner->team_id ? Team::query()->find($owner->team_id) : null)
            : $owner;

        return $team ? array_values($this->roles->ancestorTeamIds($team)) : [];
    }

    /**
     * Sort key per row: space first, then teams in ancestry order.
     *
     * @param  list<string>  $teamIds
     * @return Closure(AiScope, string|null): int
     */
    public function rank(array $teamIds): Closure
    {
        $positions = array_flip($teamIds);

        return static fn (AiScope $scope, ?string $ownerId): int => match ($scope) {
            AiScope::Space => 0,
            AiScope::Team => 1 + ($positions[$ownerId] ?? 999),
            AiScope::System => 10_000,
        };
    }
}
