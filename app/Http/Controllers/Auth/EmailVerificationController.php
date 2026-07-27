<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Notifications\User\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;

class EmailVerificationController extends AuthController
{
    private const CACHE_PREFIX = 'email_verification:';

    private const CACHE_TTL = 3600;

    private const RATE_LIMIT_TTL = 60;

    public function send(): JsonResponse
    {
        $user = auth()->user();

        if ($user->email_verified_at) {
            return response()->json([
                'message' => __('auth.email_already_verified'),
            ], 400);
        }

        $rateLimitKey = self::CACHE_PREFIX.'rate_limit:'.$user->id;
        if (Cache::has($rateLimitKey)) {
            return response()->json([
                'message' => __('auth.email_verification_rate_limit'),
                'retry_after' => Cache::get($rateLimitKey) - now()->timestamp,
            ], 429);
        }

        Cache::forget(self::CACHE_PREFIX.$user->id);
        $verificationUrl = $this->generateVerificationUrl($user);
        $user->notify(new VerifyEmailNotification($verificationUrl));

        Cache::put($rateLimitKey, now()->addSeconds(self::RATE_LIMIT_TTL)->timestamp, self::RATE_LIMIT_TTL);

        return response()->json([
            'message' => __('auth.email_verification_sent'),
        ]);
    }

    // Verification is fulfilled by the signed route in routes/web.php
    // (Web\VerifyEmailController). The unsigned POST twin that used to live
    // here proved only knowledge of the user id and sha1(email), both of which
    // an attacker can derive, so anyone could verify any account.

    private function generateVerificationUrl(User $user): string
    {
        $cacheKey = self::CACHE_PREFIX.$user->id;

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($user) {
            return URL::signedRoute(
                'verification.verify',
                [
                    'id' => $user->getRouteKey(),
                    'hash' => sha1($user->email),
                ],
                now()->addMinutes(60)
            );
        });
    }
}
