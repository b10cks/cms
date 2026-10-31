<?php

namespace App\Listeners\Auth;

use App\Exceptions\AccountSuspendedException;
use App\Http\Middleware\AuthenticateSession;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Runs on every session login (password, SAML, social, one-time token,
 * impersonation). Refuses suspended accounts and stamps the session with the
 * user's session epoch, so "sign out everywhere" can end it later.
 */
class GuardSessionLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if ($event->user->isSuspended()) {
            throw new AccountSuspendedException;
        }

        $request = request();
        if ($request->hasSession()) {
            $request->session()->put(AuthenticateSession::EPOCH_KEY, $event->user->session_epoch);
        }
    }
}
