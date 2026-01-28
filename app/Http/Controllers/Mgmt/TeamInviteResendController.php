<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\ResendInvite;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use App\Models\Management\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TeamInviteResendController extends Controller
{
    public function __invoke(Team $team, Invite $invite, ResendInvite $resendInvite): JsonResponse
    {
        $this->authorize('resend', $invite);

        if ($invite->team_id !== $team->id) {
            return response()->json([
                'message' => 'The invite does not belong to this team'
            ], 404);
        }

        try {
            $invite = $resendInvite->execute($invite);

            return response()->json(new InviteResource($invite));
        } catch (\Exception $e) {
            Log::error('Failed to resend team invite', [
                'invite_id' => $invite->id,
                'team_id' => $team->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
