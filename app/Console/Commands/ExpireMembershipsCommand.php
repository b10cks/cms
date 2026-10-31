<?php

namespace App\Console\Commands;

use App\Models\Management\Invite;
use App\Models\Management\Space;
use App\Models\Management\Team;
use App\Services\Auth\MembershipService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Ends team and space memberships whose "access until" date has passed, and
 * drops pending invites whose access would already be over. Removal goes
 * through MembershipService, so it is audited and a team's last owner is kept:
 * that membership is logged and skipped, and its team shows up as needing
 * attention on the overview.
 */
class ExpireMembershipsCommand extends Command
{
    protected $signature = 'memberships:expire';

    protected $description = 'Remove memberships and invites whose access date has passed';

    public function handle(MembershipService $memberships): int
    {
        $removed = 0;
        $skipped = 0;

        $teamRows = DB::table('team_user')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get(['team_id', 'user_id']);

        $teams = Team::query()->whereIn('id', $teamRows->pluck('team_id')->unique())->get()->keyBy('id');
        foreach ($teamRows as $row) {
            $team = $teams->get($row->team_id);
            if (! $team) {
                continue;
            }

            try {
                $memberships->removeTeamMembership($team, $row->user_id, 'expired');
                $removed++;
            } catch (ValidationException) {
                $skipped++;
                Log::warning('Kept an expired membership of the last team owner', [
                    'team' => $team->id,
                    'user' => $row->user_id,
                ]);
            }
        }

        $spaceRows = DB::table('space_user')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get(['space_id', 'user_id']);

        $spaces = Space::query()->whereIn('id', $spaceRows->pluck('space_id')->unique())->get()->keyBy('id');
        foreach ($spaceRows as $row) {
            $space = $spaces->get($row->space_id);
            if ($space) {
                $memberships->removeSpaceMembership($space, $row->user_id, 'expired');
                $removed++;
            }
        }

        $invites = Invite::query()
            ->whereNull('accepted_at')
            ->whereNull('declined_at')
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', now())
            ->delete();

        $this->info("{$removed} membership(s) removed, {$skipped} last owner(s) kept, {$invites} invite(s) deleted.");

        return self::SUCCESS;
    }
}
