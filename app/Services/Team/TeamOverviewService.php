<?php

namespace App\Services\Team;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\Management\TeamSamlProvider;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use App\Services\Team\Attention\Checks\ExpiringAccess;
use App\Services\Team\Attention\Checks\StaleInvites;
use App\Services\Team\Attention\TeamAttentionCollector;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything the team overview shows in one response: the KPI row, the
 * structure chart of the subtree and the "Needs attention" items.
 */
class TeamOverviewService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly TeamAttentionCollector $attention,
    ) {}

    public function build(Team $team, User $actor): array
    {
        $scope = TeamScope::for($team);

        return [
            'kpis' => $this->kpis($scope),
            'structure' => $this->structure($scope, $actor),
            'attention' => array_map(
                fn ($item) => $item->toArray(),
                $this->attention->collect($scope, $actor),
            ),
        ];
    }

    private function kpis(TeamScope $scope): array
    {
        $memberIds = DB::table('team_user')
            ->whereIn('team_id', [...$scope->ancestorIds, ...$scope->teamIds])
            ->select('user_id')
            ->union(DB::table('space_user')->whereIn('space_id', $scope->spaceIds)->select('user_id'))
            ->pluck('user_id')
            ->unique();

        $activeMembers = User::query()
            ->whereIn('id', $memberIds)
            ->where('last_login_at', '>=', now()->subDays(30))
            ->count();

        $pendingInvites = Invite::query()
            ->pending()
            ->where(fn ($query) => $query
                ->whereIn('team_id', $scope->teamIds)
                ->orWhereIn('space_id', $scope->spaceIds))
            ->get(['id', 'created_at']);

        $until = now()->addDays(ExpiringAccess::DAYS);
        $expiring = DB::table('team_user')
            ->whereIn('team_id', $scope->teamIds)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $until)
            ->count()
            + DB::table('space_user')
                ->whereIn('space_id', $scope->spaceIds)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $until)
                ->count();

        $saml = TeamSamlProvider::query()->where('team_id', $scope->team->id)->first(['id', 'enabled']);

        return [
            'members' => [
                'total' => $memberIds->count(),
                'active_30d' => $activeMembers,
            ],
            'spaces' => [
                'total' => count($scope->spaceIds),
                'published' => Space::query()
                    ->whereIn('id', $scope->spaceIds)
                    ->whereNotNull('content_updated_at')
                    ->count(),
            ],
            'pending_invites' => [
                'total' => $pendingInvites->count(),
                'stale' => $pendingInvites
                    ->filter(fn (Invite $invite) => $invite->created_at?->lt(now()->subDays(StaleInvites::DAYS)))
                    ->count(),
            ],
            'expiring_access' => [
                'total' => $expiring,
            ],
            'sso' => [
                'configured' => $saml !== null,
                'enabled' => (bool) $saml?->enabled,
            ],
        ];
    }

    /**
     * The subtree as a flat list, the team first. The scope team's parent is
     * reported as null so the chart is rooted at the team being viewed.
     */
    private function structure(TeamScope $scope, User $actor): array
    {
        $teams = Team::query()
            ->whereIn('id', $scope->teamIds)
            ->get()
            ->keyBy('id');

        $members = User::query()
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->whereIn('team_user.team_id', $scope->teamIds)
            ->orderBy('team_user.created_at')
            ->select('users.*', 'team_user.team_id as scope_team_id')
            ->get()
            ->groupBy('scope_team_id');

        $spaces = Space::query()
            ->whereIn('id', $scope->spaceIds)
            ->orderBy('name')
            ->get(['id', 'team_id', 'name', 'content_updated_at'])
            ->groupBy('team_id');

        return array_values(array_map(function (string $teamId) use ($teams, $members, $spaces, $scope, $actor) {
            /** @var Team $team */
            $team = $teams->get($teamId);
            /** @var Collection<int, User> $teamMembers */
            $teamMembers = $members->get($teamId, collect());

            return [
                'id' => $team->id,
                'parent_id' => $teamId === $scope->team->id ? null : $team->parent_id,
                'name' => $team->name,
                'icon' => $team->icon,
                'color' => $team->color,
                'avatar' => $team->avatar_url,
                'type' => $team->type,
                'people_count' => $teamMembers->count(),
                'people' => $teamMembers->take(3)->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar_url,
                ])->values()->all(),
                'spaces' => $spaces->get($teamId, collect())
                    ->map(fn (Space $space) => [
                        'id' => $space->id,
                        'name' => $space->name,
                        'published' => $space->content_updated_at !== null,
                    ])
                    ->values()
                    ->all(),
                'can_move' => $teamId !== $scope->team->id
                    && $this->authorization->canInTeam($actor, $team, 'team.update'),
                'can_adopt' => $this->authorization->canInTeam($actor, $team, 'team.children.manage'),
            ];
        }, $scope->teamIds));
    }
}
