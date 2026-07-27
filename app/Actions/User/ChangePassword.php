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

        // Personal access tokens deliberately survive this: they are long-lived
        // credentials with their own lifecycle, revoked by hand from account
        // settings rather than swept away by an unrelated action. Browser
        // sessions do not survive — every other session still carries the old
        // password hash, and AuthenticateSession logs them out on their next
        // request. The session performing the change re-stores the new hash on
        // the way out, so it stays signed in.

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
