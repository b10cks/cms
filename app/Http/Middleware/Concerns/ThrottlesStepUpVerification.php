<?php

namespace App\Http\Middleware\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Attempt counter for step-up verification headers.
 *
 * `x-totp-code` and `x-password-confirmation` are checked inline on ordinary
 * management requests, which are throttled per minute in the thousands. That is
 * ample room to walk a six-digit TOTP (three codes are valid at any moment
 * given the ±1 window) or to brute-force the account password from a hijacked
 * session, so these checks need a counter of their own.
 */
trait ThrottlesStepUpVerification
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 900;

    private function stepUpKey(User $user, string $factor): string
    {
        return "step-up:{$factor}:{$user->getKey()}";
    }

    /**
     * A 429 response when the user has burned through their attempts, else null.
     */
    private function stepUpLockout(User $user, string $factor): ?JsonResponse
    {
        $key = $this->stepUpKey($user, $factor);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return null;
        }

        return response()->json([
            'message' => __('auth.too_many_verification_attempts', ['seconds' => RateLimiter::availableIn($key)]),
            'error_code' => 'TOO_MANY_ATTEMPTS',
            'retry_after' => RateLimiter::availableIn($key),
        ], 429);
    }

    private function recordStepUpFailure(User $user, string $factor): void
    {
        RateLimiter::hit($this->stepUpKey($user, $factor), self::DECAY_SECONDS);
    }

    private function clearStepUpAttempts(User $user, string $factor): void
    {
        RateLimiter::clear($this->stepUpKey($user, $factor));
    }
}
