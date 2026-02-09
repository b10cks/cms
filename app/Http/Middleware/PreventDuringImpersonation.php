<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use App\Services\Auth\ImpersonationService;

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
        $tokenName = $user->currentAccessToken()?->name;

        return $tokenName && str_starts_with($tokenName, ImpersonationService::TOKEN_NAME_PREFIX);
    }
}
