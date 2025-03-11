<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function impersonate(?User $user, ?User $realUser): bool
    {
        return $realUser && $realUser->is_root;
    }
}
