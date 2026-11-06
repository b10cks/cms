<?php

namespace App\Services\Ai\Drivers;

use App\Services\Ai\Exceptions\ProviderTransportException;
use App\Services\Ai\Support\AiSseStream;
use GuzzleHttp\Psr7\Response;
use OpenAI\Factory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends streamed provider calls and hands their body on as it arrives.
 *
 * Guzzle streams only through PHP's http wrapper, which needs `allow_url_fopen`.
 * The image turns that off, so Guzzle fell back to curl and buffered the whole
 * response: nothing reached the browser until the model had finished, and
 * CloudFront dropped calls that stayed silent for 30s. Symfony's client
 * streams over curl. Its progress callback keeps an open SSE stream alive,
 * also while the provider is still thinking and sends nothing.
 */
final class StreamingTransport
{
    public function __construct(private readonly HttpClientInterface $client) {}

    public static function create(float $connectTimeout, float $idleTimeout): self
    {
        return new self(HttpClient::create([
            'max_connect_duration' => $connectTimeout,
            'timeout' => $idleTimeout,
            'on_progress' => static fn () => AiSseStream::keepAlive(),
        ]));
    }

    /** The stream handler for {@see Factory::withStreamHandler()}. */
    public function send(RequestInterface $request): ResponseInterface
    {
        $response = $this->client->request($request->getMethod(), (string) $request->getUri(), [
            'headers' => $request->getHeaders(),
            'body' => (string) $request->getBody(),
        ]);

        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            // Provider calls always carry a JSON body; none of it sent means the call never left.
            throw new ProviderTransportException($request, (float) $response->getInfo('size_upload') > 0, $e);
        }

        return new Response($status, $response->getHeaders(false), new ChunkStream($this->client->stream($response)));
    }
}
