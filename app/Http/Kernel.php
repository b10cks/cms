<?php

namespace App\Http;

use App\Http\Middleware\AcceptHeader;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateDataApi;
use App\Http\Middleware\CacheDataApi;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureRevision;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequirePasswordVerification;
use App\Http\Middleware\RequireTotpVerification;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\ValidateSignature;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\VerifyLemonSqueezyWebhook;
use App\Http\Middleware\VersionHeader;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [
        // \App\Http\Middleware\TrustHosts::class,
        VersionHeader::class,
        TrustProxies::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        // \App\Http\Middleware\TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
        ],

        'mgmt' => [
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class.':mgmt',
            AcceptHeader::class,
            SubstituteBindings::class,
        ],

        'api' => [
            ThrottleRequests::class.':api',
            AcceptHeader::class,
            SubstituteBindings::class,
        ],

        'ilum' => [
            SubstituteBindings::class,
        ],
    ];

    /**
     * The application's middleware aliases.
     *
     * Aliases may be used instead of class names to conveniently assign middleware to routes and groups.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [
        'auth' => Authenticate::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'auth.session' => AuthenticateSession::class,
        'auth.data' => AuthenticateDataApi::class,
        'cache.data' => CacheDataApi::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'signed' => ValidateSignature::class,
        'throttle' => ThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'revision' => EnsureRevision::class,
        '2fa' => RequireTotpVerification::class,
        'stateful' => EnsureFrontendRequestsAreStateful::class,
        'password' => RequirePasswordVerification::class,
        'webhook.lemonsqueezy' => VerifyLemonSqueezyWebhook::class,
    ];
}
