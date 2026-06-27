<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use App\Services\Auth\RoleService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Notification;

class CreateInvite
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    public function execute(array $data, Authenticatable|User $inviter): Invite
    {
        $inviteeId = null;
        if (isset($data['email'])) {
            $existingUser = User::where('email', $data['email'])->first();
            if ($existingUser) {
                $inviteeId = $existingUser->id;
            }
        }

        $team = null;
        if (! empty($data['space_id'])) {
            $team = Space::query()->findOrFail($data['space_id'])->team;
        } elseif (! empty($data['team_id'])) {
            $team = Team::query()->findOrFail($data['team_id']);
        }

        $role = ! empty($data['space_id'])
            ? $this->roleService->resolveSpaceRole($data['role'], $team)
            : $this->roleService->resolveTeamRole($data['role']);

        $invite = Invite::forceCreate([
            'space_id' => $data['space_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'invited_by' => $inviter->id,
            'invitee_id' => $inviteeId,
            'role_id' => $role->id,
            'email' => $data['email'],
            'token' => Str::random(32),
            'message' => $data['message'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        $this->sendNotification($invite->loadMissing(['roleDefinition', 'team', 'space', 'inviter']));

        return $invite;
    }

    private function sendNotification(Invite $invite): void
    {
        if ($invite->space_id) {
            $notification = new InviteToSpaceNotification($invite, $invite->space, $invite->inviter);
        } elseif ($invite->team_id) {
            $notification = new InviteToTeamNotification($invite, $invite->team, $invite->inviter);
        } else {
            return;
        }

        // Registered invitees get the notification in-app, with a read-gated
        // email fallback. Invites to addresses without an account are mailed.
        if ($invite->invitee_id && ($invitee = $invite->invitee)) {
            $invitee->notify($notification);
        } else {
            Notification::route('mail', $invite->email)->notify($notification);
        }
    }
}
