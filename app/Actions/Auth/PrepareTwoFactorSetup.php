<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthService;
use Illuminate\Support\Facades\Cache;

class PrepareTwoFactorSetup
{
    private const CACHE_PREFIX = '2fa_setup:';
    private const CACHE_TTL = 600;

    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function execute(User $user): array
    {
        $secret = $this->twoFactorService->generateSecret();

        $cacheKey = self::CACHE_PREFIX . $user->id;
        Cache::put($cacheKey, $secret, self::CACHE_TTL);

        return [
            'secret' => $secret,
        ];
    }

    public function getSecretFromCache(User $user): ?string
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;

        return Cache::get($cacheKey);
    }

    public function clearCache(User $user): void
    {
        $cacheKey = self::CACHE_PREFIX . $user->id;
        Cache::forget($cacheKey);
    }
}
