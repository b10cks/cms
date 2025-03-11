<?php

namespace App\Http\Middleware;

use App\Models\Management\Token;
use App\Services\Token\TokenUsageService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthenticateDataApi
{
    /**
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next)
    {
        $start = now();
        $plainTextToken = $request->query('token');
        if (!$plainTextToken) {
            throw new AuthenticationException('No API token provided');
        }

        $usageService = app(abstract: TokenUsageService::class);
        $token = $this->validateToken($plainTextToken, $usageService);
        $request->route()->setParameter('space', $token->space);

        $execution = $usageService->startExecution($token, [
            'route' => $request->route()->getName(),
            'method' => $request->method(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ], $start);

        try {
            $response = $next($request);
            $usageService->completeExecution($execution, []);

            return $response;
        } catch (\Throwable $e) {
            $usageService->failExecution($execution, $e);
            throw $e;
        }
    }

    protected function validateToken(string $plainTextToken, TokenUsageService $usageService): Token
    {
        $token = Token::findValidToken($plainTextToken);
        if (!$token) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired api token');
        }

        if (!$usageService->canExecute($token)) {
            throw new UnauthorizedHttpException('Bearer', 'Token usage limit exceeded');
        }

        return $token;
    }
}
