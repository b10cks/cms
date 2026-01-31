<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\RegenerateBackupCodes;
use Illuminate\Http\JsonResponse;

class TwoFactorBackupCodesController extends AuthController
{
    public function __construct(private RegenerateBackupCodes $regenerateBackupCodes)
    {
    }

    public function regenerate(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->hasEnabledTwoFactor()) {
            return response()->json([
                'message' => __('auth.2fa_not_enabled'),
            ], 400);
        }

        $result = $this->regenerateBackupCodes->execute($user);

        return response()->json([
            'backup_codes' => $result['backup_codes'],
        ]);
    }
}