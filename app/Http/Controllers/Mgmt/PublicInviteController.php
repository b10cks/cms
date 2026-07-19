<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PublicInviteResource;
use App\Models\Management\Invite;
use Illuminate\Http\Request;

class PublicInviteController extends Controller
{
    public function show(Request $request, Invite $invite): PublicInviteResource
    {
        // Unauthenticated endpoint: without proof of possessing the mailed
        // link, respond as if the invite does not exist — otherwise anyone
        // enumerating ULIDs could harvest inviter/team/space names.
        $token = (string) ($request->query('invite_token') ?? $request->query('token', ''));
        abort_unless($token !== '' && hash_equals($invite->token, $token), 404);

        $invite->load(['inviter', 'space', 'team', 'roleDefinition']);

        return new PublicInviteResource($invite);
    }
}
