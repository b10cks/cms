<?php

namespace App\Services\Setup;

use App\Enums\InstallProfile;

class InstallProfileResolver
{
    public function __construct(
        private readonly InstallState $installState
    ) {}

    public function resolve(?string $override = null): InstallProfile
    {
        $profile = $override
            ?? config('setup.profile')
            ?? data_get($this->installState->read(), 'profile')
            ?? config('setup.default_profile', InstallProfile::STANDARD->value);

        return InstallProfile::tryFrom((string) $profile) ?? InstallProfile::STANDARD;
    }
}
