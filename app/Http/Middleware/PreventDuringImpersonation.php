<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Tymon\JWTAuth\JWTGuard;

class PreventDuringImpersonation
{
    public function handle(Request $request, \Closure $next)
    {
        /** @var JWTGuard $guard */
        $guard = app('auth')->guard('api');

        if ($guard->check() && $this->isImpersonating($guard->payload())) {
            throw new AuthorizationException(__('auth.cannotImpersonate'));
        }

        return $next($request);
    }

    protected function isImpersonating(\Tymon\JWTAuth\Payload $payload): bool
    {
        return $payload->hasKey('ruid') && $payload->get('sub') != $payload->get('ruid');
    }
}
