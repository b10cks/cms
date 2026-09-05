<?php

namespace App\Http\Controllers;

use App\Models\Management\Space;
use App\Services\Auth\AuthorizationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;

    /**
     * Abort with 403 unless the authenticated user holds the ability in the space.
     */
    protected function authorizeSpace(Space $space, string $ability): void
    {
        abort_unless(app(AuthorizationService::class)->canInSpace(auth()->user(), $space, $ability), 403);
    }

    /**
     * Resolve a bounded per-page value from the request so a client can't force
     * an unbounded query with e.g. ?per_page=1000000.
     */
    protected function perPage(Request $request, int $default = 20, int $max = 100): int
    {
        $value = $request->input('per_page', $default);

        abort_unless(
            (\is_int($value) || (\is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1))
                && (int) $value > 0,
            422,
            'Parameter "per_page" must be a positive integer.',
        );

        return min((int) $value, $max);
    }

    /**
     * Log an unexpected failure with a support-safe identifier without exposing
     * exception details to the client.
     *
     * @param  array<string, mixed>  $context
     */
    protected function internalServerError(
        Throwable $exception,
        string $message,
        array $context = [],
    ): JsonResponse {
        $errorId = (string) Str::uuid();

        Log::error($message, [
            ...$context,
            'error_id' => $errorId,
            'exception' => $exception,
        ]);

        return response()->json([
            'message' => $message,
            'error_id' => $errorId,
        ], 500);
    }
}
