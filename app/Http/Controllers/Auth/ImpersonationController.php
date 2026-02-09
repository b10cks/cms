<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;

class ImpersonationController extends AuthController
{
    public function __construct(public ImpersonationService $service)
    {
    }

    public function store(Request $request)
    {
        $realUser = auth()->user();
        $this->authorize('impersonate', [User::class, $realUser]);
        $targetUser = User::findOrFail($request->get('userId'));
        $token = $this->service->impersonate($realUser, $targetUser);

        return $this->responseWithAccessToken($token);
    }

    public function destroy(Request $request)
    {
        $impersonatedUser = $request->user();
        $realUserId = $this->service->getRealUserId($impersonatedUser);
        if (!$realUserId) {
            throw new AuthorizationException(__('auth.not_impersonating'));
        }

        $realUser = $this->service->getRealUser($realUserId);
        $this->authorize('impersonate', [User::class, $realUser]);
        $token = $this->service->stop($impersonatedUser, $realUser);

        return $this->responseWithAccessToken($token);
    }
}
