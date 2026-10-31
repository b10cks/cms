<?php

namespace App\Services\Provider;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Services\Team\TeamOwnershipService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** The provider console's view of users: segments, counts and the detail sheet. */
class UserDirectory
{
    public const SEGMENTS = ['all', 'active', 'suspended', 'root'];

    public function __construct(
        private readonly TeamOwnershipService $ownership,
    ) {}

    /** @return Builder<User> */
    public function query(?string $search, string $segment): Builder
    {
        $query = User::query()
            ->withCount(['teams', 'samlIdentities'])
            ->with(['socialLinks:id,user_id,service', 'teams' => fn ($q) => $q->select('teams.id', 'teams.name')->orderBy('teams.name')]);

        $this->applySegment($query, $segment);

        // Every word has to match a name part or the email, so "Jonas Berg" finds Jonas Berg.
        foreach (preg_split('/\s+/', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word).'%';
            $query->where(fn (Builder $q) => $q
                ->where('email', 'like', $term)
                ->orWhere('firstname', 'like', $term)
                ->orWhere('lastname', 'like', $term));
        }

        return $query->orderByDesc('last_login_at')->orderBy('email');
    }

    /** @return array<string, int> */
    public function segmentCounts(): array
    {
        return collect(self::SEGMENTS)
            ->mapWithKeys(fn (string $segment) => [$segment => $this->applySegment(User::query(), $segment)->count()])
            ->all();
    }

    /** @return array<string, mixed> */
    public function detail(User $user): array
    {
        $soleOwned = array_flip($this->ownership->soleOwnedTeamIds($user));

        $teams = $user->teams()->orderBy('name')->get(['teams.id', 'teams.name', 'teams.avatar', 'teams.color']);
        $roles = DB::table('roles')->whereIn('id', $teams->pluck('pivot.role_id')->merge($user->spaces()->pluck('space_user.role_id')))->get(['id', 'key', 'name'])->keyBy('id');
        $spaces = $user->spaces()->orderBy('name')->get(['spaces.id', 'spaces.name', 'spaces.team_id']);

        $ownedTeams = $teams->filter(fn (Team $team) => $roles->get($team->pivot->role_id)?->key === 'owner');

        return [
            'counts' => [
                'teams' => $teams->count(),
                'sessions' => $this->sessionCount($user),
                'tokens' => $user->tokens()->whereNull('impersonator_id')->count(),
            ],
            'memberships' => [
                ...$teams->map(fn (Team $team) => [
                    'kind' => 'team',
                    'id' => $team->id,
                    'name' => $team->name,
                    'avatar' => $team->avatar_url,
                    'role_key' => $roles->get($team->pivot->role_id)?->key,
                    'role_name' => $roles->get($team->pivot->role_id)?->name,
                    'is_sole_owner' => isset($soleOwned[$team->id]),
                ])->all(),
                ...$spaces->map(fn (Space $space) => [
                    'kind' => 'space',
                    'id' => $space->id,
                    'name' => $space->name,
                    'avatar' => null,
                    'role_key' => $roles->get($space->pivot->role_id)?->key,
                    'role_name' => $roles->get($space->pivot->role_id)?->name,
                    'is_sole_owner' => false,
                ])->all(),
            ],
            'owned_teams' => $ownedTeams->map(fn (Team $team) => [
                'id' => $team->id,
                'name' => $team->name,
                'is_sole_owner' => isset($soleOwned[$team->id]),
                'candidates' => $this->ownerCandidates($team, $user),
            ])->values()->all(),
            'activity' => $this->recentActivity($user),
        ];
    }

    /** @return array<int, array{id: string, name: string, email: string}> */
    private function ownerCandidates(Team $team, User $user): array
    {
        return $team->users()
            ->whereNull('users.suspended_at')
            ->where('users.id', '!=', $user->id)
            ->orderBy('firstname')
            ->limit(50)
            ->get(['users.id', 'users.firstname', 'users.lastname', 'users.email'])
            ->map(fn (User $candidate) => [
                'id' => $candidate->id,
                'name' => trim($candidate->name) !== '' ? trim($candidate->name) : $candidate->email,
                'email' => $candidate->email,
            ])
            ->all();
    }

    /** Browser sessions can only be counted on the database driver. */
    private function sessionCount(User $user): ?int
    {
        if (config('session.driver') !== 'database') {
            return null;
        }

        return DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->count();
    }

    /** @return array<int, array{action: string, created_at: string|null, by_self: bool}> */
    private function recentActivity(User $user): array
    {
        return DB::table('audit_logs')
            ->where(fn ($q) => $q
                ->where('user_id', $user->id)
                ->orWhere(fn ($q) => $q->where('entity_type', User::class)->where('entity_id', $user->id)))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['action', 'user_id', 'created_at'])
            ->map(fn ($row) => [
                'action' => $row->action,
                'created_at' => $row->created_at ? \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String() : null,
                'by_self' => $row->user_id === $user->id,
            ])
            ->all();
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function applySegment(Builder $query, string $segment): Builder
    {
        return match ($segment) {
            'active' => $query->whereNull('suspended_at')->where('last_login_at', '>=', now()->subDays(30)),
            'suspended' => $query->whereNotNull('suspended_at'),
            'root' => $query->where('is_root', true),
            default => $query,
        };
    }
}
