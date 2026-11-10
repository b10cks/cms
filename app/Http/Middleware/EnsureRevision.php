<?php

namespace App\Http\Middleware;

use App\Models\Management\Space;
use Closure;
use Illuminate\Http\Request;

class EnsureRevision
{
    public function handle(Request $request, Closure $next)
    {
        /** @var Space $space */
        $space = app('currentSpace');
        $revision = $request->query('rv');

        // A revision the space has not reached yet is answered like a missing
        // one. Served as asked, the response would sit in the CDN under that
        // revision and come back as current once the space gets there.
        if (! $revision || (is_numeric($revision) && (int) $revision > $space->rv)) {
            $params = $request->query();
            $params['rv'] = $space->rv;
            $url = $request->path() . '?' . http_build_query($params);

            // No $secure argument: it is an absolute override, not a hint, so
            // passing one here pins the redirect to a scheme the instance may
            // not serve. Leaving it out follows APP_URL (see AppServiceProvider).
            return redirect($url, 301, [
                'cache-control' => 'public, max-age=60, s-maxage=10',
                'x-b10cks-version' => config('app.version'),
            ]);
        }

        return $next($request);
    }
}
