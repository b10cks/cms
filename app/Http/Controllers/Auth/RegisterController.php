<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Management\Invite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, AcceptInvite $acceptInvite): JsonResponse
    {
        try {
            $isInviteRegistration = $request->filled('invite_id');

            $user = User::create([
                'email' => $request->input('email'),
                'firstname' => $request->input('firstname'),
                'lastname' => $request->input('lastname'),
                'password' => Hash::make($request->input('password')),
                'language_iso' => app()->getLocale(),
                'source' => $isInviteRegistration ? 'invite' : 'manual',
                'email_verified_at' => $isInviteRegistration ? now() : null,
            ]);

            if ($isInviteRegistration) {
                $invite = Invite::findOrFail($request->input('invite_id'));

                if (!$invite->isPending()) {
                    Log::warning('Attempt to accept non-pending invite during registration', [
                        'invite_id' => $invite->id,
                        'user_id' => $user->id,
                    ]);
                }

                $acceptInvite->execute($invite, $user);
            }

            $token = JWTAuth::fromUser($user);

            return response()->json([
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->display_name,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'email' => $request->input('email'),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'An error occurred during registration'
            ], 500);
        }
    }
}
