<?php

namespace App\Http\Controllers\Auth;

class IssueTokenController extends AuthController
{
    public function __invoke()
    {
        auth()->setDefaultDriver('api');
        $credentials = request(['email', 'password']);

        if ($token = auth()->attempt($credentials)) {
            $this->updateUserLogin(auth()->user());

            return $this->responseWithToken($token);
        }

        return response()->json(['message' => __('auth.failed')], 401);
    }
}
