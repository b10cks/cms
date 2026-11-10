<?php

namespace App\Http\Middleware;

use App\Models\Management\Space;
use Closure;
use Illuminate\Http\Request;

class EnsureRevision
{
    private const int UNREACHED_REVISION_TTL = 10;

    public function handle(Request $request, Closure $next)
    {
        /** @var Space $space */
        $space = app('currentSpace');
        $revision = $request->query('rv');

        if (! $revision) {
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

        // A revision the space has not reached yet is served, since a unique
        // rv is the documented way to get past the CDN, but only cached briefly.
        // Cached for a day, the response would come back as current once the
        // space reaches that revision.
        if (is_numeric($revision) && (int) $revision > $space->rv) {
            $request->attributes->set(CacheDataApi::MAX_TTL_ATTRIBUTE, self::UNREACHED_REVISION_TTL);
        }

        return $next($request);
    }
}
