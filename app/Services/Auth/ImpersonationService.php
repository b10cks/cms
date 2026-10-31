<?php

namespace App\Services\Auth;

use App\Enums\InstanceAuditAction;
use App\Models\Management\Impersonation;
use App\Models\User;
use App\Services\Audit\InstanceAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * "Sign in as" for root operators. Two transports share one record
 * ({@see Impersonation}):
 *
 * - Browser: the session switches to the target user and remembers the
 *   impersonation id. The operator gets the session back on stop or once
 *   the record lapses ({@see reconcileSession()}).
 * - API: a personal access token for the target, expiring with the record.
 */
class ImpersonationService
{
    public const TOKEN_NAME_PREFIX = 'impersonation:';

    public const REAL_USER_TOKEN_NAME = 'real-user';

    public const SESSION_KEY = 'impersonation_id';

    public function __construct(private readonly InstanceAuditService $audit) {}

    /**
     * @return array{impersonation: Impersonation, token: string|null}
     */
    public function start(Request $request, User $operator, User $target, string $reason): array
    {
        $impersonation = Impersonation::query()->create([
            'impersonator_id' => $operator->id,
            'user_id' => $target->id,
            'reason' => $reason,
            'expires_at' => now()->addMinutes(Impersonation::LIFETIME_MINUTES),
        ]);

        $token = null;

        if ($request->hasSession()) {
            $this->switchSession($request, $target);
            $request->session()->put(self::SESSION_KEY, $impersonation->id);
        } else {
            $newToken = $target->createToken(
                self::TOKEN_NAME_PREFIX.$operator->getRouteKey(),
                ['*'],
                $impersonation->expires_at,
            );

            // The link back to the operator lives on the token row. The name is
            // only a label: users name their own tokens, so it cannot be trusted
            // to say who is behind an impersonation session.
            $newToken->accessToken->forceFill(['impersonator_id' => $operator->getKey()])->save();
            $impersonation->forceFill(['token_id' => $newToken->accessToken->getKey()])->save();
            $token = $newToken->plainTextToken;
        }

        $this->audit->record(InstanceAuditAction::ImpersonationStarted, $impersonation, $operator, [
            'user_id' => $target->id,
            'user_email' => $target->email,
            'reason' => $reason,
            'expires_at' => $impersonation->expires_at->toIso8601String(),
        ]);

        return ['impersonation' => $impersonation, 'token' => $token];
    }

    /**
     * Ends the impersonation behind the current request. Returns a fresh
     * token for the operator on the API transport, null for the browser.
     */
    public function stop(Request $request, User $impersonatedUser, User $operator): ?string
    {
        $impersonation = $this->currentFor($request, $impersonatedUser);
        $this->end($impersonation, $operator, 'stopped');

        $token = $impersonatedUser->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return $operator->createToken(self::REAL_USER_TOKEN_NAME, ['*'])->plainTextToken;
        }

        $this->restoreOperator($request, $operator);

        return null;
    }

    /**
     * Hands a browser session back to its operator once the impersonation
     * expired, was ended elsewhere, or no longer matches the session user.
     */
    public function reconcileSession(Request $request): void
    {
        $impersonationId = $request->session()->get(self::SESSION_KEY);
        if (! is_string($impersonationId)) {
            return;
        }

        $impersonation = Impersonation::query()->with('impersonator')->find($impersonationId);
        $sessionUserId = Auth::guard('web')->id();

        if ($impersonation?->isActive() && $impersonation->user_id === $sessionUserId) {
            return;
        }

        $operator = $impersonation?->impersonator;
        $this->end($impersonation, $operator, 'expired');
        $this->restoreOperator($request, $operator);
    }

    public function currentFor(Request $request, User $user): ?Impersonation
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            return Impersonation::query()
                ->where('token_id', $token->getKey())
                ->where('user_id', $user->id)
                ->first();
        }

        $impersonationId = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_string($impersonationId)
            ? Impersonation::query()->whereKey($impersonationId)->where('user_id', $user->id)->first()
            : null;
    }

    /**
     * The operator behind an impersonated request, read from the token row or
     * the session record; never from anything the user can name.
     */
    public function getRealUserId(User $impersonatedUser): ?string
    {
        $token = $impersonatedUser->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $impersonatorId = $token->getAttribute('impersonator_id');

            return is_string($impersonatorId) && $impersonatorId !== '' ? $impersonatorId : null;
        }

        return $this->currentFor(request(), $impersonatedUser)?->impersonator_id;
    }

    public function getRealUser(string $realUserId): ?User
    {
        return User::find($realUserId);
    }

    private function end(?Impersonation $impersonation, ?User $operator, string $why): void
    {
        if ($impersonation === null || $impersonation->ended_at !== null) {
            return;
        }

        $impersonation->forceFill(['ended_at' => now()])->save();

        if ($operator !== null) {
            $this->audit->record(InstanceAuditAction::ImpersonationEnded, $impersonation, $operator, [
                'user_id' => $impersonation->user_id,
                'ended' => $why,
            ]);
        }
    }

    private function restoreOperator(Request $request, ?User $operator): void
    {
        $request->session()->forget(self::SESSION_KEY);

        if ($operator === null || $operator->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return;
        }

        $this->switchSession($request, $operator);
    }

    /**
     * Logs the session into another user. The stored password hash belongs to
     * the previous user, so it is dropped and re-stamped on the next request.
     */
    private function switchSession(Request $request, User $user): void
    {
        $request->session()->forget(['password_hash_web', 'password_hash_sanctum']);
        Auth::guard('web')->login($user);
    }
}
