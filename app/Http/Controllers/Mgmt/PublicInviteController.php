<?php

namespace App\Http\Controllers\Mgmt;

use App\Http\Controllers\Controller;
use App\Http\Resources\Management\PublicInviteResource;
use App\Models\Management\Invite;

class PublicInviteController extends Controller
{
    public function show(Invite $invite): PublicInviteResource
    {
        $invite->load(['inviter', 'space', 'team', 'roleDefinition']);

        return new PublicInviteResource($invite);
    }
}
