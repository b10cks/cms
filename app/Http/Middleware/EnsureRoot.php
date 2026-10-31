<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The provider console is root-only. Reads need no elevation; writes check it themselves. */
class EnsureRoot
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->is_root, 403);

        return $next($request);
    }
}
