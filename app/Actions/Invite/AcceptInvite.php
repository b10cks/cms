<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;

class AcceptInvite
{
    public function execute(Invite $invite, Authenticatable|User $user): bool
    {
        if ($invite->isAccepted() || $invite->isExpired()) {
            return false;
        }

        if ($invite->email !== $user->email) {
            return false;
        }

        $invite->update([
            'accepted_at' => now(),
            'invitee_id' => $user->id,
        ]);

        $this->attachUserToResource($invite, $user);

        return true;
    }

    private function attachUserToResource(Invite $invite, User $user): void
    {
        if ($invite->space_id) {
            $space = $invite->space;
            if (!$space->users()->where('users.id', $user->id)->exists()) {
                $space->users()->attach($user->id, [
                    'role' => $invite->role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $space->users()->updateExistingPivot($user->id, [
                    'role' => $invite->role,
                    'updated_at' => now(),
                ]);
            }
        } elseif ($invite->team_id) {
            $team = $invite->team;
            if (!$team->users()->where('users.id', $user->id)->exists()) {
                $team->users()->attach($user->id, [
                    'role' => $invite->role,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $team->users()->updateExistingPivot($user->id, [
                    'role' => $invite->role,
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
