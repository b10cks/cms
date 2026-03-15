<?php

namespace App\Services\Team;

use App\Models\Management\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TeamUserPolicyService
{
    public function getTeamUserPivot(User $user, Team $team): ?Pivot
    {
        $cacheKey = $this->getCacheKey($user, $team);

        return cache()->remember(
            $cacheKey,
            now()->addMinutes(5),
            fn() => $user->Teams()
                ->where('team_id', $team->id)
                ->first()
                    ?->pivot
        );
    }

    public function clearTeamUserPivot(User $user, Team $team): void
    {
        $cacheKey = $this->getCacheKey($user, $team);

        cache()->forget($cacheKey);
    }

    private function getCacheKey(User $user, Team $team): string
    {
        return 'team_permissions_' . $user->id . '_' . $team->id;
    }
}
