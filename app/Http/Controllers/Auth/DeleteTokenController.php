<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class DeleteTokenController extends Controller
{
    public function __invoke(Request $request)
    {
        $user = $request->user();

        if ($user?->currentAccessToken()) {
            $user->currentAccessToken()->delete();
        }

        auth()->guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }
}
