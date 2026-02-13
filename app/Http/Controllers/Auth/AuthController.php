<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

abstract class AuthController extends Controller
{

    protected function updateUserLogin(User $user): void
    {
        $user->forceFill([
            'login_count' => $user->login_count + 1,
            'last_login_at' => now(),
        ])
            ->save();
    }

    protected function responseWithAccessToken(string $token): \Illuminate\Http\JsonResponse
    {
        return response()
            ->json([
                'access_token' => $token,
                'token_type' => 'bearer',
            ]);
    }

    protected function logoutSession(Request $request): void
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
