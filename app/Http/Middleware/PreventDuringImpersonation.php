<?php

namespace App\Http\Middleware;

use App\Services\Auth\ImpersonationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

class PreventDuringImpersonation
{
    public function handle(Request $request, \Closure $next)
    {
        $user = $request->user();

        if ($user && $this->isImpersonating($user)) {
            throw new AuthorizationException(__('auth.cannotImpersonate'));
        }

        return $next($request);
    }

    protected function isImpersonating($user): bool
    {
        // Read the recorded impersonator, not the token name — users name
        // their own tokens and could otherwise dodge (or forge) this check.
        return app(ImpersonationService::class)->getRealUserId($user) !== null;
    }
}
