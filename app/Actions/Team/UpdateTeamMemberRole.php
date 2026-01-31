<?php

namespace App\Actions\Team;

use App\Models\Management\Team;
use App\Models\User;

class UpdateTeamMemberRole
{
    public function execute(Team $team, User $user, ?string $role): void
    {
        $team->users()->updateExistingPivot($user->id, [
            'role' => $role,
            'updated_at' => now()
        ]);
    }
}
