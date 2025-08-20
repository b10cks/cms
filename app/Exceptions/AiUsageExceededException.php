<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiUsageExceededException extends Exception
{
    protected $code = 429;

    public function __construct(string $message = 'AI usage limit exceeded', int $code = 429, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'AI Usage Limit Exceeded',
            'message' => $this->getMessage(),
            'code' => 'AI_USAGE_EXCEEDED',
            'suggestions' => [
                'Wait for your quota to reset',
            ],
        ], $this->code);
    }

    public function report(): bool
    {
        \Log::warning('AI usage limit exceeded', [
            'message' => $this->getMessage(),
            'user_id' => auth()->id(),
            'trace' => $this->getTraceAsString(),
        ]);

        return true;
    }
}
