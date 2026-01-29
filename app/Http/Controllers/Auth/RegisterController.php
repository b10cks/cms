<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\User\CreateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Management\Invite;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;

class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, CreateUser $createUser, AcceptInvite $acceptInvite): JsonResponse
    {
        try {
            $isInviteRegistration = $request->filled('invite_id');
            $invite = ($isInviteRegistration) ? Invite::findOrFail($request->input('invite_id')) : null;

            $data = [
                'email' => $request->input('email'),
                'firstname' => $request->input('firstname'),
                'lastname' => $request->input('lastname'),
                'password' => Hash::make($request->input('password')),
                'language_iso' => app()->getLocale(),
                'source' => $invite ? 'invite' : 'manual',
                'email_verified_at' => ($invite && strtolower($invite->email) === strtolower($request->input('email'))) ? now() : null,
            ];

            $user = $createUser->execute($data);
            if ($invite) {
                if (!$invite->isPending()) {
                    Log::warning('Attempt to accept non-pending invite during registration', [
                        'invite_id' => $invite->id,
                        'user_id' => $user->id,
                    ]);
                }

                $acceptInvite->execute($invite, $user);
            }

            return response()->json([
                'access_token' => JWTAuth::fromUser($user),
                'token_type' => 'bearer',
                'expires_in' => auth()->factory()->getTTL() * 60,
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
