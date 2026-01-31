<?php

namespace App\Http\Controllers\Auth;

use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorStatusController extends AuthController
{

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $request->user()->hasEnabledTwoFactor()
        ]);
    }
}
