<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\ResendInvite;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use App\Models\Management\Team;
use Illuminate\Support\Facades\Log;
use Laminas\Diactoros\Response\JsonResponse;

class TeamInviteResendController extends Controller
{
    public function __invoke(Team $team, Invite $invite, ResendInvite $resendInvite): InviteResource|JsonResponse
    {
        $this->authorize('resend', $invite);
        abort_if($invite->team_id !== $team->id, 404);

        try {
            $invite = $resendInvite->execute($invite);

            return new InviteResource($invite);
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
