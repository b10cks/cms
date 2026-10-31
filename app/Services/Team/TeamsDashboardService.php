<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Models\User;
use App\Services\Auth\AuthorizationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The teams start page: totals across every team the user can reach, and one
 * card per top of their reachable forest (a team whose parent they cannot
 * see), each summing its whole subtree. Every figure comes from a handful of
 * grouped queries, so the cost does not grow with the number of teams.
 */
class TeamsDashboardService
{
    private const SERIES_MONTHS = 12;

    private const PREVIEW_PEOPLE = 4;

    private const PREVIEW_CHILDREN = 4;

    public function __construct(private readonly AuthorizationService $authorization) {}

    public function build(User $user): array
    {
        $teamIds = $this->authorization->accessibleTeamIds($user);

        $teams = Team::query()
            ->whereIn('id', $teamIds)
            ->orderBy('name')
            ->get()
            ->keyBy('id');

        $childrenOf = $teams->groupBy(fn (Team $team) => $teams->has((string) $team->parent_id) ? $team->parent_id : '');
        $roots = $childrenOf->get('', collect());

        $members = DB::table('team_user')
            ->join('roles', 'roles.id', '=', 'team_user.role_id')
            ->whereIn('team_user.team_id', $teams->keys())
            ->orderBy('team_user.created_at')
            ->get(['team_user.team_id', 'team_user.user_id', 'roles.key as role']);
        $membersByTeam = $members->groupBy('team_id');

        $spaces = DB::table('spaces')
            ->whereIn('team_id', $teams->keys())
            ->whereNull('deleted_at')
            ->get(['id', 'team_id', 'created_at', 'content_updated_at']);
        $spacesByTeam = $spaces->groupBy('team_id');

        $invitesByTeam = $this->pendingInvitesByTeam($teams->keys()->all(), $spaces);

        $ssoTeams = DB::table('team_saml_providers')
            ->whereIn('team_id', $teams->keys())
            ->where('enabled', true)
            ->pluck('team_id')
            ->flip();

        $previewUsers = User::query()
            ->whereIn('id', $members->pluck('user_id')->unique())
            ->get(['id', 'firstname', 'lastname', 'email', 'avatar', 'last_login_at'])
            ->keyBy('id');

        $subtree = function (string $rootId) use ($childrenOf): array {
            $ids = [];
            $stack = [$rootId];
            while ($stack !== []) {
                $id = array_pop($stack);
                $ids[] = $id;
                foreach ($childrenOf->get($id, collect()) as $child) {
                    $stack[] = $child->id;
                }
            }

            return $ids;
        };

        $withoutOwner = $teams->keys()
            ->reject(fn (string $id) => $membersByTeam->get($id, collect())->contains('role', 'owner'))
            ->flip();

        $cards = $roots->map(function (Team $root) use (
            $subtree, $childrenOf, $membersByTeam, $spacesByTeam, $invitesByTeam, $ssoTeams, $previewUsers, $withoutOwner, $user
        ) {
            $ids = $subtree($root->id);
            $peopleIds = collect($ids)
                ->flatMap(fn (string $id) => $membersByTeam->get($id, collect())->pluck('user_id'))
                ->unique()
                ->values();
            $subtreeSpaces = collect($ids)->flatMap(fn (string $id) => $spacesByTeam->get($id, collect()));

            return [
                'id' => $root->id,
                'name' => $root->name,
                'description' => $root->description,
                'icon' => $root->icon,
                'color' => $root->color,
                'avatar' => $root->avatar_url,
                'type' => $root->type,
                'created_at' => $root->created_at?->toIso8601String(),
                'people_count' => $peopleIds->count(),
                'spaces_count' => $subtreeSpaces->count(),
                'sub_team_count' => count($ids) - 1,
                'pending_invites' => collect($ids)->sum(fn (string $id) => $invitesByTeam[$id] ?? 0),
                'teams_without_owner' => collect($ids)->filter(fn (string $id) => $withoutOwner->has($id))->count(),
                'sso' => $ssoTeams->has($root->id),
                'content_updated_at' => $subtreeSpaces->max('content_updated_at')
                    ? Carbon::parse($subtreeSpaces->max('content_updated_at'))->toIso8601String()
                    : null,
                'people' => $peopleIds
                    ->take(self::PREVIEW_PEOPLE)
                    ->map(fn (string $id) => $previewUsers->get($id))
                    ->filter()
                    ->map(fn (User $person) => [
                        'id' => $person->id,
                        'name' => $person->name,
                        'avatar' => $person->avatar_url,
                    ])
                    ->values()
                    ->all(),
                'children' => $childrenOf->get($root->id, collect())
                    ->take(self::PREVIEW_CHILDREN)
                    ->map(fn (Team $child) => [
                        'id' => $child->id,
                        'name' => $child->name,
                        'icon' => $child->icon,
                        'color' => $child->color,
                        'avatar' => $child->avatar_url,
                    ])
                    ->values()
                    ->all(),
                'can_update' => $this->authorization->canInTeam($user, $root, 'team.update'),
                'can_delete' => $this->authorization->canInTeam($user, $root, 'team.delete'),
            ];
        })->values();

        $peopleIds = $members->pluck('user_id')->unique();

        return [
            'summary' => [
                'teams' => $teams->count(),
                'teams_series' => $this->cumulativeSeries($teams->pluck('created_at')),
                'people' => $peopleIds->count(),
                'active_people' => $previewUsers
                    ->filter(fn (User $person) => $person->last_login_at?->isAfter(now()->subDays(30)))
                    ->count(),
                'spaces' => $spaces->count(),
                'spaces_series' => $this->cumulativeSeries($spaces->pluck('created_at')),
                'pending_invites' => array_sum($invitesByTeam),
                'sso_teams' => $ssoTeams->count(),
                'teams_without_owner' => $withoutOwner->count(),
            ],
            'teams' => $cards->all(),
        ];
    }

    /**
     * Pending invites per team, counting space invites on the space's team.
     *
     * @param  array<int, string>  $teamIds
     * @return array<string, int>
     */
    private function pendingInvitesByTeam(array $teamIds, Collection $spaces): array
    {
        $teamOfSpace = $spaces->pluck('team_id', 'id');

        $invites = DB::table('invites')
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now())
            ->where(fn ($query) => $query
                ->whereIn('team_id', $teamIds)
                ->orWhereIn('space_id', $teamOfSpace->keys()))
            ->get(['team_id', 'space_id']);

        $counts = [];
        foreach ($invites as $invite) {
            $teamId = $invite->team_id ?? $teamOfSpace->get($invite->space_id);
            if ($teamId) {
                $counts[$teamId] = ($counts[$teamId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * Running totals at the end of each of the last twelve months.
     *
     * @return array<int, int>
     */
    private function cumulativeSeries(Collection $createdAt): array
    {
        $dates = $createdAt->filter()->map(fn ($date) => Carbon::parse($date));

        return collect(range(self::SERIES_MONTHS - 1, 0))
            ->map(function (int $monthsAgo) use ($dates) {
                $end = now()->subMonthsNoOverflow($monthsAgo)->endOfMonth();

                return $dates->filter(fn (Carbon $date) => $date->lte($end))->count();
            })
            ->all();
    }
}
