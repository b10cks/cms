<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Tymon\JWTAuth\Exceptions\JWTException;

class RefreshTokenController extends Controller
{
    public function __invoke()
    {
        try {
            $token = auth()->refresh();
        } catch (JWTException $e) {
            return response(['message' => __('auth.failed')], 401);
        }
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth()->factory()->getTTL() * 60
        ]);
    }

}
