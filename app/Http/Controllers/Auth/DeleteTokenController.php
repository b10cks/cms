<?php

namespace App\Http\Controllers\Auth;


use Illuminate\Http\Request;

class DeleteTokenController extends AuthController
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        // if ($user?->currentAccessToken()) {
            // $user->currentAccessToken()->delete();
        // }

        $this->logoutSession($request);

        return response()->json(null, 204);
    }
}
