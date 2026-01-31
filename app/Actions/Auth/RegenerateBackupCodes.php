<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\User\TwoFactorBackupCodesRegeneratedNotification;
use App\Services\Auth\TwoFactorAuthService;

class RegenerateBackupCodes
{
    public function __construct(private TwoFactorAuthService $twoFactorService)
    {
    }

    public function execute(User $user): array
    {
        $backupCodes = $this->twoFactorService->generateBackupCodes();

        $user->forceFill([
            'two_factor_backup_codes' => $backupCodes,
        ])->save();

        $user->notify(new TwoFactorBackupCodesRegeneratedNotification());

        return [
            'success' => true,
            'backup_codes' => $backupCodes,
        ];
    }
}
