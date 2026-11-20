<?php

namespace App\Http\Controllers\Auth;

use App\Enums\PasskeyCeremony;
use App\Services\Auth\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasskeyConfirmController extends AuthController
{
    public function __construct(private PasskeyService $passkeys) {}

    /**
     * Start a passkey confirmation for the signed-in user. Returns WebAuthn
     * request options limited to the user's own passkeys.
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->passkeys()->exists()) {
            return response()->json([
                'message' => __('auth.passkey_none'),
                'error_code' => 'NO_PASSKEYS',
            ], 400);
        }

        return response()->json($this->passkeys->requestOptions($request, PasskeyCeremony::Confirm, $user));
    }

    /**
     * Finish a passkey confirmation. For the next few minutes the session
     * passes the TOTP and password step-up checks without a code.
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'credential' => ['required', 'array'],
        ]);

        $user = $request->user();
        $this->passkeys->verify($request, PasskeyCeremony::Confirm, $request->input('credential'), $user);
        $this->passkeys->markConfirmed($request, $user);

        return response()->json([
            'message' => __('auth.passkey_confirmed'),
        ]);
    }
}
