<?php

namespace App\Http\Middleware;

use App\Http\Middleware\Concerns\ThrottlesStepUpVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordVerification
{
    use ThrottlesStepUpVerification;

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (! $user) {
            return response()->json([
                'message' => __('auth.unauthenticated'),
            ], 401);
        }

        return $this->handlePasswordVerification($request, $next, $user);
    }

    private function handlePasswordVerification(Request $request, Closure $next, $user): Response
    {
        $password = $request->header('x-password-confirmation');

        if (! $password) {
            return response()->json([
                'message' => __('auth.password_confirmation_required'),
                'error_code' => 'PASSWORD_CONFIRMATION_REQUIRED',
                'requires_password' => true,
            ], 423);
        }

        if ($lockout = $this->stepUpLockout($user, 'password')) {
            return $lockout;
        }

        if (! password_verify($password, $user->password)) {
            $this->recordStepUpFailure($user, 'password');

            return response()->json([
                'message' => __('auth.invalid_password'),
                'error_code' => 'INVALID_PASSWORD',
            ], 403);
        }

        $this->clearStepUpAttempts($user, 'password');

        return $next($request);
    }
}
