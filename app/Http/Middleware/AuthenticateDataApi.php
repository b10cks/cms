<?php

namespace App\Http\Middleware;

use App\Models\Management\Token;
use App\Services\Token\TokenUsageService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthenticateDataApi
{
    /**
     * Delivery surface (path segment after api/v1) → ability resource.
     * Search, sitemaps and breadcrumbs all read content, so they share
     * the `contents` grant.
     */
    protected const array RESOURCE_MAP = [
        'contents' => 'contents',
        'breadcrumbs' => 'contents',
        'search' => 'contents',
        'sitemap' => 'contents',
        'sitemaps' => 'contents',
        'redirects' => 'redirects',
        'spaces' => 'spaces',
        'blocks' => 'blocks',
        'datasources' => 'data_sources',
        'iconify' => 'icons',
    ];

    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        $start = now();
        $plainTextToken = $request->query('token');
        if (! $plainTextToken) {
            throw new AuthenticationException('No API token provided');
        }

        $usageService = app(abstract: TokenUsageService::class);
        $token = $this->validateToken($plainTextToken, $usageService);
        $this->authorizeRequest($request, $token);
        app()->offsetSet('currentSpace', $token->space);

        $execution = $usageService->startExecution($token, $start);

        try {
            $response = $next($request);
            $usageService->completeExecution($execution);

            return $response;
        } catch (\Throwable $e) {
            $usageService->failExecution($execution, $e);
            throw $e;
        }
    }

    /**
     * Tokens issued before ability enforcement were grandfathered to
     * `*:read` + `*:preview` by migration, so nothing that worked keeps
     * working by accident here — an explicit grant is always required.
     */
    protected function authorizeRequest(Request $request, Token $token): void
    {
        $resource = self::RESOURCE_MAP[$request->segment(3)] ?? null;

        // Unknown surfaces require an unrestricted read grant so a future
        // route can never silently widen a resource-scoped token.
        $canRead = $resource === null
            ? $token->hasAbility('*', 'read')
            : $token->hasAbility($resource, 'read');

        if (! $canRead) {
            throw new AccessDeniedHttpException('Token lacks read access to this resource');
        }

        // Anything but the published scope exposes unreleased versions and
        // needs the dedicated preview grant.
        if ($request->query('vid', 'published') !== 'published'
            && ! $token->hasAbility($resource ?? '*', 'preview')) {
            throw new AccessDeniedHttpException('Token lacks preview access to unpublished versions');
        }
    }

    protected function validateToken(string $plainTextToken, TokenUsageService $usageService): Token
    {
        $token = Token::findValidToken($plainTextToken);
        if (! $token) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired api token');
        }

        if ($token->space->state !== 'live') {
            throw new NotFoundHttpException;
        }

        if (! $usageService->canExecute($token)) {
            throw new UnauthorizedHttpException('Bearer', 'Token usage limit exceeded');
        }

        return $token;
    }
}
