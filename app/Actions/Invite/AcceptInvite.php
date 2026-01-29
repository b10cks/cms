<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AcceptInvite
{
    public function execute(Invite $invite, Authenticatable|User $user): bool
    {
        if ($invite->isAccepted() || $invite->isExpired()) {
            return false;
        }

        $invite->accepted_at = now();
        $invite->invitee_id = $user->id;
        $invite->save();
        $this->handleInvite($invite, $user);

        return true;
    }

    private function handleInvite(Invite $invite, User $user): void
    {
        $team = null;
        if ($invite->space_id) {
            $this->attachUserToRelation($invite->space->users(), $user, $invite->role);
            $team = $invite->space->team;
            $teamRole = 'space';
        }
        if ($invite->team_id) {
            $team = $invite->team;
            $teamRole = $invite->role;
        }

        if ($team) {
            $this->attachUserToRelation($team->users(), $user, $teamRole);
        }
    }

    private function attachUserToRelation(BelongsToMany $relation, User $user, $role)
    {
        if (!$relation->wherePivot('user_id', $user->id)->exists()) {
            $relation->attach($user->id, [
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $relation->updateExistingPivot($user->id, [
                'role' => $role,
                'updated_at' => now(),
            ]);
        }
    }
}
