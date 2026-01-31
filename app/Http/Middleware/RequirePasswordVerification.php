<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordVerification
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json([
                'message' => __('auth.unauthenticated'),
            ], 401);
        }

        return $this->handlePasswordVerification($request, $next, $user);
    }

    private function handlePasswordVerification(Request $request, Closure $next, $user): Response
    {
        $password = $request->header('X-Password-Confirmation');

        if (!$password) {
            return response()->json([
                'message' => __('auth.password_confirmation_required'),
                'error_code' => 'PASSWORD_CONFIRMATION_REQUIRED',
                'requires_password' => true,
            ], 423);
        }

        if (!password_verify($password, $user->password)) {
            return response()->json([
                'message' => __('auth.invalid_password'),
                'error_code' => 'INVALID_PASSWORD',
            ], 403);
        }

        return $next($request);
    }
}
