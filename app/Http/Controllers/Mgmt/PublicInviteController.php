<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PublicInviteResource;
use App\Models\Management\Invite;
use Illuminate\Http\JsonResponse;

class PublicInviteController extends Controller
{
    public function show(Invite $invite): PublicInviteResource|JsonResponse
    {
        $invite->load(['inviter', 'space', 'team']);

        if ($invite->isExpired()) {
            return response()->json([
                'message' => 'The invitation has expired, please request a new one'
            ], 410);
        }

        if ($invite->isAccepted()) {
            return response()->json([
                'message' => 'The invitation has already been accepted'
            ], 410);
        }

        return new PublicInviteResource($invite);
    }
}
