<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Laravel\Sanctum\PersonalAccessToken;

class PersonalAccessTokenPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, PersonalAccessToken $token): bool
    {
        return $this->isOwner($user, $token);
    }

    public function delete(User $user, PersonalAccessToken $token): bool
    {
        return $this->isOwner($user, $token);
    }

    private function isOwner(User $user, PersonalAccessToken $token): bool
    {
        return $token->tokenable_type === $user->getMorphClass()
            && $token->tokenable_id === $user->getKey();
    }
}
