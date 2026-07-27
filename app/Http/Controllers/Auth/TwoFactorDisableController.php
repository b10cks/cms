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
        // The password is proven by the `password` middleware, which counts
        // failures and locks out. Verifying it inline here instead gave an
        // unlimited number of guesses against the account password.
        $user = auth()->user();

        if (!$user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_not_enabled'),
            ], 400);
        }

        $result = $this->disableTwoFactor->execute($user);

        return response()->json([
            'message' => $result['message'],
        ]);
    }
}
