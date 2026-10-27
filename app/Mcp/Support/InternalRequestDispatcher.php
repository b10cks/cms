<?php

declare(strict_types=1);

namespace App\Mcp\Support;

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
     * @param  array<string, UploadedFile>  $files  sent as multipart form data along with the payload
     * @return array{status: int, body: string, contentType: string|null, filename: string|null}
     */
    public function dispatch(string $method, string $uri, array $query = [], ?array $payload = null, ?string $bearerToken = null, array $files = []): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];

        if ($bearerToken !== null && $bearerToken !== '') {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearerToken;
        }

        $content = $payload !== null && $files === [] ? json_encode($payload, JSON_THROW_ON_ERROR) : null;

        if ($content !== null) {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        // Build on APP_URL, not the bare path: Request::create() would default
        // the host to localhost, which TrustHosts rejects outside local/testing.
        $url = rtrim(config('app.url'), '/').$uri;
        $request = $files === []
            ? Request::create($url, $method, $query, [], [], $server, $content)
            : Request::create($url.($query !== [] ? '?'.http_build_query($query) : ''), $method, $payload ?? [], [], $files, $server);
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

        $disposition = (string) $response->headers->get('Content-Disposition', '');

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'contentType' => $response->headers->get('Content-Type'),
            'filename' => preg_match('/filename="?([^";]+)"?/', $disposition, $match) === 1 ? $match[1] : null,
        ];
    }
}
