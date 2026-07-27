<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;

class DeleteTokenController extends AuthController
{
    public function __invoke(Request $request)
    {
        // Logging out ends the browser session and nothing else. A personal
        // access token is a standing credential a user issued on purpose —
        // often to a script that has no idea a browser signed out — so it is
        // revoked deliberately from account settings, never as a side effect.
        $this->logoutSession($request);

        return response()->json(null, 204);
    }
}
