<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Thrown when a suspended account tries to sign in through any channel. */
class AccountSuspendedException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __('auth.suspended'),
            'error_code' => 'ACCOUNT_SUSPENDED',
        ], 403);
    }
}
