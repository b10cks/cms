<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession as SanctumAuthenticateSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum's session check (a session dies when the password changes) plus two
 * root controls:
 *
 * - Session epoch. Sessions carry the user's `session_epoch` from login on.
 *   Bumping it ("sign out everywhere", suspension) ends every older session,
 *   whatever the session driver. Sessions from before the epoch existed carry
 *   no stamp and survive only while the epoch is still 0.
 * - Impersonation lapse. A browser impersonation that expired or was ended
 *   elsewhere hands the session back to the operator before anything else runs.
 */
class AuthenticateSession extends SanctumAuthenticateSession
{
    public const EPOCH_KEY = 'auth_session_epoch';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && $request->user() instanceof User) {
            app(ImpersonationService::class)->reconcileSession($request);

            $user = Auth::guard('web')->user();

            if ($user instanceof User && ! $this->epochMatches($request, $user)) {
                Auth::guard('web')->logoutCurrentDevice();
                $request->session()->flush();

                throw new AuthenticationException('Unauthenticated.', ['web', 'sanctum']);
            }
        }

        return parent::handle($request, $next);
    }

    private function epochMatches(Request $request, User $user): bool
    {
        $stamped = $request->session()->get(self::EPOCH_KEY);

        return $stamped === null
            ? $user->session_epoch === 0
            : (int) $stamped === $user->session_epoch;
    }
}
