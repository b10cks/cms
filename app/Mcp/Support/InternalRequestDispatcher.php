<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dispatches a sub-request through the HTTP kernel so MCP tool calls reuse the
 * management API's routing, auth, validation, policies and resources instead
 * of duplicating them.
 */
class InternalRequestDispatcher
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     * @return array{status: int, body: string}
     */
    public function dispatch(string $method, string $uri, array $query = [], ?array $payload = null, ?string $bearerToken = null): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        if ($bearerToken !== null && $bearerToken !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearerToken;
        }

        $content = $payload !== null ? json_encode($payload, JSON_THROW_ON_ERROR) : null;

        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $request = Request::create($uri, $method, $query, [], [], $server, $content);
        // The outer /mcp/v1 request is already throttled; exempt the sub-request
        // so one tool call doesn't consume two mgmt rate-limit hits.
        $request->attributes->set('mcp-internal', true);

        $originalRequest = $this->app->bound('request') ? $this->app['request'] : null;

        try {
            // Deliberately no $kernel->terminate(): it would fire application
            // terminating callbacks (afterResponse jobs, …) mid-request and
            // again at the outer request's terminate. Terminable middleware in
            // the sub-request stack is skipped as a consequence — none of the
            // mgmt group's middleware is terminable.
            $response = $this->app->handle($request);
        } finally {
            if ($originalRequest !== null) {
                $this->app->instance('request', $originalRequest);
                Facade::clearResolvedInstance('request');
            }
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            ob_start();
            $response->sendContent();
            $body = (string) ob_get_clean();
        } else {
            $body = (string) $response->getContent();
        }

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }
}
