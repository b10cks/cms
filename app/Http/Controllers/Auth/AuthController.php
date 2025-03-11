<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;

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

    protected function responseWithToken(string $token): \Illuminate\Http\JsonResponse
    {
        return response()
            ->json([
                       'access_token' => $token,
                       'token_type' => 'bearer',
                       'expires_in' => auth()->factory()->getTTL() * 60,
                   ]);
    }
}
