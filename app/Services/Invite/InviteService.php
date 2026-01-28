<?php

namespace App\Services\Invite;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use Illuminate\Support\Str;
use Notification;

class InviteService
{
    public function createInvite(array $data, User $inviter): Invite
    {
        $inviteeId = null;
        if (isset($data['email'])) {
            $existingUser = User::where('email', $data['email'])->first();
            if ($existingUser) {
                $inviteeId = $existingUser->id;
            }
        }

        $invite = Invite::create([
            'space_id' => $data['space_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'invited_by' => $inviter->id,
            'invitee_id' => $inviteeId,
            'email' => $data['email'],
            'role' => $data['role'],
            'token' => Str::random(32),
            'message' => $data['message'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        $this->sendInviteNotification($invite);

        return $invite;
    }

    public function acceptInvite(Invite $invite, User $user): bool
    {
        if ($invite->isAccepted()) {
            return false;
        }

        if ($invite->isExpired()) {
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

    public function resendInvite(Invite $invite): Invite
    {
        if ($invite->isAccepted()) {
            throw new \Exception('Cannot resend an accepted invite.');
        }

        if ($invite->isExpired()) {
            $invite->update([
                'token' => Str::random(32),
                'expires_at' => now()->addDays(7),
            ]);
        }

        $this->sendInviteNotification($invite);

        return $invite->refresh();
    }

    public function revokeInvite(Invite $invite): bool
    {
        return $invite->delete();
    }

    public function checkAndUpdateInvitee(Invite $invite): Invite
    {
        if (!$invite->invitee_id) {
            $existingUser = User::where('email', $invite->email)->first();
            if ($existingUser) {
                $invite->update(['invitee_id' => $existingUser->id]);
            }
        }

        return $invite->refresh();
    }

    private function sendInviteNotification(Invite $invite): void
    {
        if ($invite->space_id) {
            $space = $invite->space;
            Notification::route('mail', $invite->email)
                ->notify(new InviteToSpaceNotification($invite, $space, $invite->inviter));
        } elseif ($invite->team_id) {
            $team = $invite->team;
            Notification::route('mail', $invite->email)
                ->notify(new InviteToTeamNotification($invite, $team, $invite->inviter));
        }
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
