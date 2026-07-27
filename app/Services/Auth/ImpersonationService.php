<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class ImpersonationService
{
    public const TOKEN_NAME_PREFIX = 'impersonation:';

    public const REAL_USER_TOKEN_NAME = 'real-user';

    public function impersonate(User $realUser, User $impersonatedUser): string
    {
        $token = $impersonatedUser->createToken(self::TOKEN_NAME_PREFIX.$realUser->getRouteKey(), ['*']);

        // The link back to the operator lives on the token row. The name is
        // only a label: users name their own tokens, so it cannot be trusted
        // to say who is behind an impersonation session.
        $token->accessToken->forceFill(['impersonator_id' => $realUser->getKey()])->save();

        return $token->plainTextToken;
    }

    public function stop(User $impersonatedUser, User $realUser): string
    {
        $impersonatedUser->currentAccessToken()?->delete();

        return $realUser->createToken(self::REAL_USER_TOKEN_NAME, ['*'])->plainTextToken;
    }

    public function getRealUserId(User $impersonatedUser): ?string
    {
        $token = $impersonatedUser->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $impersonatorId = $token->getAttribute('impersonator_id');

        return is_string($impersonatorId) && $impersonatorId !== '' ? $impersonatorId : null;
    }

    public function getRealUser(string $realUserId): ?User
    {
        return User::find($realUserId);
    }
}
