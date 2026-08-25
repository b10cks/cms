<?php

namespace App\Actions\Invite;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Models\User;
use App\Notifications\Management\InviteToSpaceNotification;
use App\Notifications\Management\InviteToTeamNotification;
use App\Services\Auth\MembershipGuard;
use App\Services\Auth\RoleService;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Notification;

class CreateInvite
{
    public function __construct(
        private readonly RoleService $roleService,
        private readonly MembershipGuard $guard,
    ) {}

    public function execute(array $data, Authenticatable|User $inviter): Invite
    {
        // Acceptance matches the address case-insensitively; store it
        // normalized so duplicate detection behaves the same way.
        $data['email'] = mb_strtolower(trim($data['email'] ?? ''));

        $inviteeId = User::where('email', $data['email'])->first()?->id;

        $space = ! empty($data['space_id']) ? Space::query()->findOrFail($data['space_id']) : null;
        $team = $space?->team;
        if (! $space && ! empty($data['team_id'])) {
            $team = Team::query()->findOrFail($data['team_id']);
        }

        $role = $space
            ? $this->roleService->resolveSpaceRole($data['role'], $team)
            : $this->roleService->resolveTeamRole($data['role']);

        if ($inviter instanceof User && ! $inviter->is_root) {
            $space
                ? $this->guard->ensureCanAssignSpaceRole($inviter, $space, $role)
                : $this->guard->ensureCanAssignTeamRole($inviter, $team, $role);
        }

        // A fresh invite supersedes any expired or declined one for the same
        // address and target; accepted invites are kept as history.
        Invite::query()
            ->where('email', $data['email'])
            ->when($space, fn ($query) => $query->where('space_id', $space->id))
            ->when(! $space, fn ($query) => $query->where('team_id', $data['team_id']))
            ->whereNull('accepted_at')
            ->delete();

        $invite = Invite::forceCreate([
            'space_id' => $data['space_id'] ?? null,
            'team_id' => $data['team_id'] ?? null,
            'invited_by' => $inviter->id,
            'invitee_id' => $inviteeId,
            'role_id' => $role->id,
            'email' => $data['email'],
            'language' => $data['language'] ?? app()->getLocale(),
            'token' => Str::random(32),
            'message' => $data['message'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        $this->sendNotification($invite->loadMissing(['roleDefinition', 'team', 'space', 'inviter', 'invitee']));

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
        $notification->locale($invite->notificationLocale());

        if ($invite->invitee_id && ($invitee = $invite->invitee)) {
            $invitee->notify($notification);
        } else {
            Notification::route('mail', $invite->email)->notify($notification);
        }
    }
}
