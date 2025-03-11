<?php

namespace App\Http\Middleware;

class AcceptHeader
{
    public function handle($request, \Closure $next)
    {
        $locale = $this->findLocale($request->header('accept-language'), config('app.locales', '*'), 'en');
        app()->setLocale($locale);

        return $next($request)->setVary('Accept-Language');
    }

    /**
     * Find a supported locale
     *
     * @param string $header example: de-AT,de;q=0.9,en-US;q=0.8,en;q=0.7
     * @param array $supportedLocales
     * @param null $fallback
     *
     * @return null|string
     */
    protected function findLocale($header, array $supportedLocales, $fallback = null)
    {
        $locales = array_map(function ($localeString) {
            @list ($locale, $q) = explode(';', $localeString);

            return [
                $locale,
                $q ? (float)str_replace('q=', '', $q) : 1,
            ];
        }, explode(',', $header));

        foreach ($locales as $locale) {
            if ($locale = $this->isSupportedLocale($locale[0], $supportedLocales)) {
                return $locale;
            }
        }

        return $fallback;
    }

    protected function isSupportedLocale($locale, $supportedLocales = '*'): ?string
    {
        $locale = strtolower($locale);
        if ($supportedLocales === '*') {
            return $locale;
        }

        if (in_array($locale, $supportedLocales)) {
            return $locale;
        }

        return null;
    }
}
