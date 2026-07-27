<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class DeleteTokenController extends AuthController
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // A bearer token outlives the session it was used with, so logging out
        // without revoking it leaves a stolen token working indefinitely.
        // Transient (already expired) tokens are skipped by currentAccessToken.
        $token = $user?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        $this->logoutSession($request);

        return response()->json(null, 204);
    }
}
