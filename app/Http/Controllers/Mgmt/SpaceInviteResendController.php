<?php

namespace App\Http\Controllers\Mgmt;

use App\Actions\Invite\ResendInvite;
use App\Http\Controllers\Controller;
use App\Http\Resources\Management\InviteResource;
use App\Models\Management\Invite;
use App\Models\Management\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SpaceInviteResendController extends Controller
{
    public function __invoke(Space $space, Invite $invite, ResendInvite $resendInvite): JsonResponse
    {
        $this->authorize('resend', $invite);

        if ($invite->space_id !== $space->id) {
            return response()->json([
                'message' => 'The invite does not belong to this space'
            ], 404);
        }

        try {
            $invite = $resendInvite->execute($invite);

            return response()->json(new InviteResource($invite));
        } catch (\Exception $e) {
            Log::error('Failed to resend space invite', [
                'invite_id' => $invite->id,
                'space_id' => $space->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }
}
