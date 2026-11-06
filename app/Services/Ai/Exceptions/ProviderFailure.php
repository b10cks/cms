<?php

namespace App\Services\Ai\Exceptions;

use App\Services\Ai\Run\TurnUsage;
use Aws\Exception\AwsException;
use Aws\Exception\EventStreamDataException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use JsonException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Exceptions\UnserializableResponse;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * The structured facts of a failed provider call. Exception messages are never
 * read: a failure with no recognized status, code or exception class stays
 * {@see ProviderFailureKind::Unknown}, which is not retried.
 *
 * `$usage` holds what the provider reported before the call ended, which can be
 * only part of what it bills.
 */
final readonly class ProviderFailure
{
    /** Codes that say more than their HTTP status, in lower case. OpenAI, OpenRouter and Bedrock event streams. */
    private const CODES = [
        'insufficient_quota' => ProviderFailureKind::Quota,
        'billing_hard_limit_reached' => ProviderFailureKind::Quota,
        'rate_limit_exceeded' => ProviderFailureKind::RateLimited,
        'rate_limit_error' => ProviderFailureKind::RateLimited,
        'server_error' => ProviderFailureKind::Unavailable,
        'overloaded_error' => ProviderFailureKind::Unavailable,
        'api_error' => ProviderFailureKind::Unavailable,
        'throttlingexception' => ProviderFailureKind::RateLimited,
        'modelnotreadyexception' => ProviderFailureKind::Unavailable,
        'modeltimeoutexception' => ProviderFailureKind::Unavailable,
        'modelstreamerrorexception' => ProviderFailureKind::Unavailable,
        'internalserverexception' => ProviderFailureKind::Unavailable,
        'serviceunavailableexception' => ProviderFailureKind::Unavailable,
        'servicequotaexceededexception' => ProviderFailureKind::Quota,
        'validationexception' => ProviderFailureKind::Rejected,
    ];

    /** cURL errors that end a request before anything was sent. A timeout can hit after sending, so it is not one. */
    private const NEVER_SENT = [\CURLE_COULDNT_RESOLVE_HOST, \CURLE_COULDNT_CONNECT, \CURLE_SSL_CONNECT_ERROR];

    public function __construct(
        public ProviderFailureKind $kind = ProviderFailureKind::Unknown,
        public ?int $status = null,
        public ?string $code = null,
        public ?int $retryAfterSeconds = null,
        public ?TurnUsage $usage = null,
    ) {}

    /**
     * @param  bool  $opened  whether the provider had accepted the call and started its stream
     */
    public static function classify(Throwable $e, bool $opened = false, ?TurnUsage $usage = null): self
    {
        $response = self::responseOf($e);
        $codes = self::codesOf($e);
        $status = $response?->getStatusCode();

        // A mid-stream error arrives on a 200; OpenRouter puts the real status in its code.
        $numeric = array_find($codes, fn (string $code) => ctype_digit($code));
        if ($numeric !== null && ($status === null || $status < 400)) {
            $status = (int) $numeric;
        }

        $known = array_find($codes, fn (string $code) => isset(self::CODES[strtolower($code)]));
        $kind = match (true) {
            $known !== null => self::CODES[strtolower($known)],
            $status !== null => self::fromStatus($status),
            $codes !== [] => ProviderFailureKind::Unknown,
            default => self::fromTransfer($e, $opened),
        };

        return new self(
            $opened && $kind->isTransient() ? ProviderFailureKind::Interrupted : $kind,
            $status,
            $codes !== [] ? mb_substr($codes[0], 0, 64) : null,
            self::retryAfter($response),
            $usage,
        );
    }

    /** The stream ended without the provider saying the response was complete. */
    public static function truncated(?TurnUsage $usage): self
    {
        return new self(ProviderFailureKind::Interrupted, usage: $usage);
    }

    /** @return array{kind: string, status: int|null, code: string|null} */
    public function toArray(): array
    {
        return ['kind' => $this->kind->value, 'status' => $this->status, 'code' => $this->code];
    }

    private static function responseOf(Throwable $e): ?ResponseInterface
    {
        return match (true) {
            $e instanceof RateLimitException, $e instanceof ServerException, $e instanceof ErrorException, $e instanceof UnserializableResponse => $e->response,
            // openai-php's default stream handler is Guzzle's send(), which wraps 5xx and non-JSON 4xx.
            $e instanceof TransporterException && $e->getPrevious() instanceof RequestException => $e->getPrevious()->getResponse(),
            $e instanceof AwsException => $e->getResponse(),
            default => null,
        };
    }

    /** @return list<string> */
    private static function codesOf(Throwable $e): array
    {
        $codes = match (true) {
            $e instanceof ErrorException => [$e->getErrorCode(), $e->getErrorType()],
            $e instanceof RateLimitException => self::bodyCodes($e->response),
            $e instanceof AwsException, $e instanceof EventStreamDataException => [$e->getAwsErrorCode()],
            default => [],
        };

        return array_values(array_map(strval(...), array_filter($codes, fn (mixed $code) => (\is_string($code) && $code !== '') || \is_int($code))));
    }

    /**
     * OpenAI's spent quota and its rate limit are both a 429. Only the body tells them apart.
     *
     * @return list<mixed>
     */
    private static function bodyCodes(ResponseInterface $response): array
    {
        $error = json_decode((string) $response->getBody(), true)['error'] ?? null;

        return \is_array($error) ? [$error['code'] ?? null, $error['type'] ?? null] : [];
    }

    private static function fromStatus(int $status): ProviderFailureKind
    {
        return match (true) {
            $status === 429 => ProviderFailureKind::RateLimited,
            $status === 408, $status === 425 => ProviderFailureKind::Unavailable,
            $status === 401, $status === 403 => ProviderFailureKind::Auth,
            $status === 402 => ProviderFailureKind::Quota,
            $status >= 400 && $status < 500 => ProviderFailureKind::Rejected,
            $status >= 500 && $status !== 501 && $status !== 505 => ProviderFailureKind::Unavailable,
            default => ProviderFailureKind::Unknown,
        };
    }

    /** No response and no code: a failed transfer, if the exception class says so. */
    private static function fromTransfer(Throwable $e, bool $opened): ProviderFailureKind
    {
        return match (true) {
            $e instanceof TransporterException => self::fromNetwork($e->getPrevious()) ?? ProviderFailureKind::Unknown,
            $e instanceof AwsException => self::fromNetwork($e->getPrevious()) ?? ProviderFailureKind::Interrupted,
            // Reading the body of an accepted call failed, or it stopped inside a JSON line.
            $opened && ($e instanceof RuntimeException || $e instanceof JsonException) => ProviderFailureKind::Interrupted,
            default => ProviderFailureKind::Unknown,
        };
    }

    private static function fromNetwork(?Throwable $cause): ?ProviderFailureKind
    {
        return match (true) {
            $cause instanceof ConnectException && \in_array($cause->getHandlerContext()['errno'] ?? null, self::NEVER_SENT, true) => ProviderFailureKind::Connection,
            $cause instanceof ProviderTransportException && ! $cause->reached => ProviderFailureKind::Connection,
            $cause instanceof NetworkExceptionInterface, $cause instanceof RequestException => ProviderFailureKind::Interrupted,
            default => null,
        };
    }

    private static function retryAfter(?ResponseInterface $response): ?int
    {
        $value = trim($response?->getHeaderLine('Retry-After') ?? '');
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0, (int) ceil((float) $value));
        }

        $at = strtotime($value);

        return $at !== false ? max(0, $at - now()->getTimestamp()) : null;
    }
}
