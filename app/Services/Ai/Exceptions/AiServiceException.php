<?php

namespace App\Services\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A user-facing AI failure with a machine-readable {@see $reason} the frontend
 * can branch on (e.g. show an upgrade prompt for `plan_excluded`, or a "try
 * again" for transient cases) and a safe, already-localisable-by-key message.
 *
 * Never wrap raw provider/exception detail in here — those are logged and
 * surfaced generically elsewhere.
 */
class AiServiceException extends RuntimeException
{
    public const REASON_NOT_CONFIGURED = 'not_configured';

    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const REASON_NOT_PROVISIONED = 'not_provisioned';

    public const REASON_PLAN_EXCLUDED = 'plan_excluded';

    public const REASON_NO_RESULT = 'no_result';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::REASON_NOT_CONFIGURED,
            'This space has no AI configuration yet. Add one in the space AI settings to use AI features.',
            422,
        );
    }

    public static function providerUnavailable(): self
    {
        return new self(
            self::REASON_PROVIDER_UNAVAILABLE,
            'The AI provider is currently unavailable. Please try again later.',
            503,
        );
    }

    public static function notProvisioned(): self
    {
        return new self(
            self::REASON_NOT_PROVISIONED,
            'Your AI access is still being set up. Please try again in a moment.',
            503,
        );
    }

    public static function planExcluded(): self
    {
        return new self(
            self::REASON_PLAN_EXCLUDED,
            'Your current plan does not include AI features. Upgrade your plan to use them.',
            403,
        );
    }

    public static function noResult(): self
    {
        return new self(
            self::REASON_NO_RESULT,
            'The AI service did not return a usable result. Please try again.',
            422,
        );
    }

    public function render(Request $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'reason' => $this->reason,
        ], $this->status);
    }
}
