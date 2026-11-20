<?php

namespace App\Http\Controllers\Auth;

use App\Enums\PasskeyCeremony;
use App\Exceptions\AccountSuspendedException;
use App\Services\Auth\PasskeyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PasskeyLoginController extends AuthController
{
    public function __construct(private PasskeyService $passkeys) {}

    /**
     * Start a passkey sign-in. Returns WebAuthn request options for any
     * passkey registered on this instance.
     */
    public function options(Request $request): JsonResponse
    {
        return response()->json($this->passkeys->requestOptions($request, PasskeyCeremony::Login));
    }

    /**
     * Finish a passkey sign-in. A passkey proves possession and user
     * verification, so no TOTP code is asked for.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'credential' => ['required', 'array'],
        ]);

        $user = $this->passkeys->verify($request, PasskeyCeremony::Login, $request->input('credential'))->user;

        if ($user->isSuspended()) {
            throw new AccountSuspendedException;
        }

        if (! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => __('auth.email_not_verified'),
                'error_code' => 'EMAIL_NOT_VERIFIED',
                'requires_verification' => true,
            ], 409);
        }

        Auth::guard('web')->login($user);
        $this->updateUserLogin($user);
        $request->session()->regenerate();

        return response()->json([
            'message' => __('auth.login_successful'),
        ]);
    }
}
