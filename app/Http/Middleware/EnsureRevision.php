<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRevision
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->query('rv')) {
            $space = $request->route('space');
            $params = $request->query();
            $params['rv'] = $space->content_updated_at?->timestamp ?? $space->updated_at->timestamp;
            $url = $request->path() . '?' . http_build_query($params);

            return redirect($url, 301, [
                'Cache-Control' => 'public, max-age=3600, s-maxage=60',
                'X-b10cks-version' => config('app.version'),
            ], app()->environment('production'));
        }
        return $next($request);
    }
}

