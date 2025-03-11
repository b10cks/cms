<?php

namespace App\Actions\User;

use App\Events\User\PasswordChanged;
use App\Models\User;

class ChangePassword
{
    public function execute(User $user, string $password, ?bool $silent = false): bool
    {
        $user->password = $password;
        $user->save();

        if (!$silent) {
            event(new PasswordChanged($user, [
                'date' => now()->isoFormat('LLLL'),
                'browser' => request()->header('User-Agent'),
                'ip' => request()->ip(),
            ]));
        }

        return true;
    }
}
