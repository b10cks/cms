<?php

namespace App\Services\Auth;

use App\Models\User;

class ImpersonationService
{
    public const TOKEN_NAME_PREFIX = 'impersonation:';
    public const REAL_USER_TOKEN_NAME = 'real-user';

    public function impersonate(User $realUser, User $impersonatedUser): string
    {
        $tokenName = self::TOKEN_NAME_PREFIX . $realUser->getRouteKey();

        return $impersonatedUser->createToken($tokenName, ['*'])->plainTextToken;
    }

    public function stop(User $impersonatedUser, User $realUser): string
    {
        $impersonatedUser->currentAccessToken()?->delete();

        return $realUser->createToken(self::REAL_USER_TOKEN_NAME, ['*'])->plainTextToken;
    }

    public function getRealUserId(User $impersonatedUser): ?string
    {
        $tokenName = $impersonatedUser->currentAccessToken()?->name;

        if (!$tokenName || !str_starts_with($tokenName, self::TOKEN_NAME_PREFIX)) {
            return null;
        }

        return substr($tokenName, strlen(self::TOKEN_NAME_PREFIX));
    }

    public function getRealUser(string $realUserId): ?User
    {
        return User::findByHashId($realUserId) ?? User::find($realUserId);
    }
}
