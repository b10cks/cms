<?php

namespace App\Services\Provider;

use App\Models\Management\Team;
use App\Services\Team\TeamOwnershipService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every team on the instance with the figures the Tenants page shows. Figures
 * are per team (direct members and spaces), not rolled up into parents.
 */
class TenantDirectory
{
    public const FLAG_NO_OWNER = 'no_owner';

    public const FLAG_TRIAL_ENDED = 'trial_ended';

    public const FLAG_PAYMENT_FAILED = 'payment_failed';

    public function __construct(
        private readonly SubscriptionRevenue $revenue,
        private readonly TeamOwnershipService $ownership,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $teams = Team::query()->orderBy('name')->get(['id', 'name', 'type', 'parent_id', 'avatar', 'color', 'created_at']);

        $people = DB::table('team_user')->select('team_id', DB::raw('count(*) as total'))->groupBy('team_id')->pluck('total', 'team_id');
        $lastActive = DB::table('team_user')
            ->join('users', 'users.id', '=', 'team_user.user_id')
            ->select('team_user.team_id', DB::raw('max(users.last_login_at) as last_active'))
            ->groupBy('team_user.team_id')
            ->pluck('last_active', 'team_id');
        $spaces = DB::table('spaces')->whereNull('deleted_at')->whereNotNull('team_id')->get(['id', 'team_id'])->groupBy('team_id');
        $sso = DB::table('team_saml_providers')->where('enabled', true)->pluck('team_id')->flip();
        $owned = array_flip($this->ownership->ownedTeamIds());
        $subscriptions = $this->revenue->currentBySpace();

        return $teams->map(function (Team $team) use ($people, $lastActive, $spaces, $sso, $owned, $subscriptions) {
            $teamSubscriptions = collect($spaces->get($team->id, collect()))
                ->map(fn ($space) => $subscriptions->get($space->id))
                ->filter();

            $flags = array_values(array_filter([
                isset($owned[$team->id]) ? null : self::FLAG_NO_OWNER,
                $teamSubscriptions->contains(fn ($s) => $this->revenue->trialEnded($s)) ? self::FLAG_TRIAL_ENDED : null,
                $teamSubscriptions->contains(fn ($s) => $this->revenue->paymentFailed($s)) ? self::FLAG_PAYMENT_FAILED : null,
            ]));

            $lastActiveAt = $lastActive->get($team->id);

            return [
                'id' => $team->id,
                'name' => $team->name,
                'type' => $team->type,
                'parent_id' => $team->parent_id,
                'avatar' => $team->avatar_url,
                'color' => $team->color,
                'spaces_count' => count($spaces->get($team->id, [])),
                'people_count' => (int) ($people->get($team->id) ?? 0),
                'mrr' => round($teamSubscriptions->sum(fn ($s) => $this->revenue->monthly($s)), 2),
                'is_trial' => $teamSubscriptions->contains(fn ($s) => $this->revenue->isTrial($s)),
                'sso' => isset($sso[$team->id]),
                'last_active_at' => $lastActiveAt ? Carbon::parse($lastActiveAt)->toIso8601String() : null,
                'created_at' => $team->created_at?->toIso8601String(),
                'flags' => $flags,
            ];
        })->values()->all();
    }
}
