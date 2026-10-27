<?php

namespace App\Policies;

use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Policies\Concerns\AuthorizesWithAbilities;

/**
 * Skills, knowledge documents and glossaries belong to a space or a team.
 * Spaces use `ai.view` / `ai.manage`; teams use `team.view` / `team.ai.manage`.
 * Rows are always loaded through the owner (`ownedBy`), so a row of another
 * owner is never reachable through these checks.
 */
abstract class AiOwnedPolicy
{
    use AuthorizesWithAbilities;

    public function viewAny(User $user, Space|Team $owner): bool
    {
        return $owner instanceof Space
            ? $this->canInSpace($user, $owner, 'ai.view')
            : $this->canInTeam($user, $owner, 'team.view');
    }

    public function manage(User $user, Space|Team $owner): bool
    {
        return $owner instanceof Space
            ? $this->canInSpace($user, $owner, 'ai.manage')
            : $this->canInTeam($user, $owner, 'team.ai.manage');
    }
}
