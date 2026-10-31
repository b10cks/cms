<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\StartImpersonationRequest;
use App\Services\Auth\ImpersonationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImpersonationController extends AuthController
{
    public function __construct(public ImpersonationService $service) {}

    public function store(StartImpersonationRequest $request): JsonResponse
    {
        ['impersonation' => $impersonation, 'token' => $token] = $this->service->start(
            $request,
            $request->user(),
            $request->target(),
            $request->string('reason')->trim()->toString(),
        );

        if ($token !== null) {
            return $this->responseWithAccessToken($token);
        }

        return response()->json([
            'data' => [
                'user_id' => $impersonation->user_id,
                'expires_at' => $impersonation->expires_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Ending an impersonation needs no elevation: the operator must always be
     * able to get back to their own account, even after the elevation lapsed.
     */
    public function destroy(Request $request): JsonResponse
    {
        $impersonatedUser = $request->user();
        $realUserId = $this->service->getRealUserId($impersonatedUser);
        $realUser = $realUserId ? $this->service->getRealUser($realUserId) : null;

        if (! $realUser?->is_root) {
            throw new AuthorizationException(__('auth.not_impersonating'));
        }

        $token = $this->service->stop($request, $impersonatedUser, $realUser);

        return $token !== null
            ? $this->responseWithAccessToken($token)
            : response()->json(null, 204);
    }
}
