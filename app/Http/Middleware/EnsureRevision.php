<?php

namespace App\Http\Middleware;

use App\Models\Management\Space;
use Closure;
use Illuminate\Http\Request;

class EnsureRevision
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->query('rv')) {
            /** @var Space $space */
            $space = app('currentSpace');
            $params = $request->query();
            $params['rv'] = $space->content_updated_at?->timestamp ?? $space->updated_at->timestamp;
            $url = $request->path() . '?' . http_build_query($params);

            return redirect($url, 301, [
                'cache-control' => 'public, max-age=60, s-maxage=10',
                'x-b10cks-version' => config('app.version'),
            ], app()->environment('production'));
        }
        return $next($request);
    }
}
