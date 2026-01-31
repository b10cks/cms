<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\EnableTwoFactor;
use App\Actions\Auth\PrepareTwoFactorSetup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorSetupController extends AuthController
{
    public function __construct(
        private PrepareTwoFactorSetup $prepareSetup,
        private EnableTwoFactor $enableTwoFactor
    ) {
    }

    public function start(): JsonResponse
    {
        $user = auth()->user();

        if ($user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_already_enabled'),
            ], 400);
        }

        $result = $this->prepareSetup->execute($user);

        return response()->json($result);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = auth()->user();

        if ($user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_already_enabled'),
            ], 400);
        }

        $secret = $this->prepareSetup->getSecretFromCache($user);
        if (!$secret) {
            return response()->json([
                'message' => __('auth.2fa_setup_expired'),
            ], 400);
        }

        $result = $this->enableTwoFactor->execute($user, $request->input('code'), $secret);

        if (!$result['success']) {
            return response()->json([
                'message' => $result['message'],
            ], 422);
        }

        $this->prepareSetup->clearCache($user);

        return response()->json([
            'message' => $result['message'],
            'backup_codes' => $result['backup_codes'],
        ]);
    }
}
