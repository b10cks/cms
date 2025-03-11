<?php

namespace App\Services\Auth;

use App\Models\User;

class ImpersonationService
{
    public const CLAIM_REAL_USER_ID = 'ruid';

    public function impersonate(User $realUser, User $impersonatedUser): string
    {
        return auth()->claims([self::CLAIM_REAL_USER_ID => $realUser->getRouteKey()])
            ->tokenById($impersonatedUser->getRouteKey());
    }

    public function stop(): string
    {
        return auth()->tokenById($this->getRealUserId());
    }

    public function getRealUserId(): string
    {
        return auth()->payload()->get(self::CLAIM_REAL_USER_ID);
    }

    public function getRealUser(): User
    {
        return User::findByHashId($this->getRealUserId());
    }
}
