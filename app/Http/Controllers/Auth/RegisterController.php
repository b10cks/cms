<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\User\CreateUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Management\Invite;
use App\Notifications\User\VerifyEmailNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Tymon\JWTAuth\Facades\JWTAuth;

class RegisterController extends Controller
{
    private const VERIFICATION_CACHE_PREFIX = 'email_verification:';
    private const VERIFICATION_CACHE_TTL = 3600;

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

            if (!$user->email_verified_at) {
                $this->sendVerificationEmail($user);
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

    private function sendVerificationEmail($user): void
    {
        $verificationUrl = $this->generateVerificationUrl($user);
        $user->notify(new VerifyEmailNotification($verificationUrl));
    }

    private function generateVerificationUrl($user): string
    {
        $cacheKey = self::VERIFICATION_CACHE_PREFIX . $user->id;

        return Cache::remember($cacheKey, self::VERIFICATION_CACHE_TTL, function () use ($user) {
            return URL::signedRoute(
                'verification.verify',
                [
                    'id' => $user->getRouteKey(),
                    'hash' => sha1($user->email),
                ],
                now()->addMinutes(60)
            );
        });
    }
}
