<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\Request;

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

        return $this->responseWithToken($token);
    }

    public function destroy()
    {
        $this->authorize('impersonate', [User::class, $this->service->getRealUser()]);
        $token = $this->service->stop();

        return $this->responseWithToken($token);
    }
}
