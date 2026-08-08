<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'auth/v1/saml/*/acs',
        'auth/v1/saml/*/sls',
        // Public share endpoints are anonymous by design (token in the URL is
        // the capability, unlock returns a bearer token, nothing touches the
        // session) — but the share page is served from the SPA origin, so
        // Sanctum classifies its requests as stateful and would demand a CSRF
        // token the cookie-less client cannot have.
        'mgmt/v1/shares/*',
    ];
}
