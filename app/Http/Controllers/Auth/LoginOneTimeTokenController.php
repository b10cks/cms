<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Http\Request;

class LoginOneTimeTokenController extends AuthController
{
    public function __invoke(Request $request)
    {
        auth()->setDefaultDriver('api');
        $request->validate([
            'email' => 'required|email:rfc,filter|exists:users,email',
            'token' => 'required|string|min:6|max:6',
        ]);

        $user = User::where('email', $request->email)->firstOrFail();
        $cacheKey = 'user:' . $user->id . ':one-time-token';

        $tokens = \Cache::get($cacheKey, []);
        if (!in_array($request->token, $tokens)) {
            return response(['message' => __('auth.failed')], 401);
        }

        $token = auth()->tokenById($user->getRouteKey());
        $this->updateUserLogin($user);
        \Cache::delete($cacheKey);

        return $this->responseWithToken($token);
    }
}
