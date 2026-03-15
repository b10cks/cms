<?php

namespace App\Services\Space;

use App\Models\Management\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Pivot;

class SpaceUserPolicyService
{
    public function getSpaceUserPivot(User $user, Space $space): ?Pivot
    {
        $cacheKey = $this->getCacheKey($user, $space);

        return cache()->remember(
            $cacheKey,
            now()->addMinutes(5),
            fn() => $user->Spaces()
                ->where('Space_id', $space->id)
                ->first()
                    ?->pivot
        );
    }

    public function clearSpaceUserPivot(User $user, Space $space): void
    {
        $cacheKey = $this->getCacheKey($user, $space);

        cache()->forget($cacheKey);
    }

    private function getCacheKey(User $user, Space $space): string
    {
        return 'space_permissions_' . $user->id . '_' . $space->id;
    }
}
