<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PublicInviteResource;
use App\Models\Management\Invite;
use Illuminate\Http\JsonResponse;

class PublicInviteController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $invite = Invite::where('token', $token)->first();

        if (!$invite) {
            return response()->json([
                'message' => 'The invitation was not found'
            ], 404);
        }

        if ($invite->isExpired()) {
            return response()->json([
                'message' => 'The invitation has expired'
            ], 410);
        }

        if ($invite->isAccepted()) {
            return response()->json([
                'message' => 'The invitation has already been accepted'
            ], 410);
        }

        return response()->json(new PublicInviteResource(
            $invite->load(['inviter', 'space', 'team'])
        ));
    }
}
