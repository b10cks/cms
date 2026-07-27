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

        // Changing a password is how someone locks an intruder out, so every
        // API token minted before this point has to stop working. The session
        // that performed the change is re-authenticated by the caller.
        $user->tokens()->delete();

        if (! $silent) {
            event(new PasswordChanged($user, [
                'date' => now()->isoFormat('LLLL'),
                'browser' => request()->header('User-Agent'),
                'ip' => request()->ip(),
            ]));
        }

        return true;
    }
}
