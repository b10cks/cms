<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\DisableTwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorDisableController extends AuthController
{
    public function __construct(private DisableTwoFactor $disableTwoFactor)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = auth()->user();

        if (!$user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_not_enabled'),
            ], 400);
        }

        if (!password_verify($request->input('password'), $user->password)) {
            return response()->json([
                'message' => __('auth.invalid_password'),
            ], 403);
        }

        $result = $this->disableTwoFactor->execute($user);

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}
