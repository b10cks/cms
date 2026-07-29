<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Invite\AcceptInvite;
use App\Actions\User\CreateUser;
use App\Models\Management\Invite;
use App\Models\User;
use App\Models\User\UserSocialLink;
use App\Notifications\User\VerifyEmailNotification;
use App\Services\Auth\TwoFactorAuthService;
use App\Support\EditionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends AuthController
{
    private const string PENDING_2FA_USER_ID = 'auth.social.pending_2fa_user_id';

    private const string RETURN_TO = 'auth.social.return_to';

    private const string INVITE_ID = 'auth.social.invite_id';

    private const string INVITE_TOKEN = 'auth.social.invite_token';

    private const string LINK_RETURN_TO = 'auth.social.link_return_to';

    private const string VERIFICATION_CACHE_PREFIX = 'email_verification:';

    private const int VERIFICATION_CACHE_TTL = 3600;

    public function __construct(
        private readonly CreateUser $createUser,
        private readonly AcceptInvite $acceptInvite,
        private readonly TwoFactorAuthService $twoFactorService,
    ) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->isSupportedProvider($provider), 404);

        $request->session()->put(self::RETURN_TO, $this->safeReturnPath($request->query('return')));
        $request->session()->put(self::INVITE_ID, $request->query('invite_id'));
        $request->session()->put(self::INVITE_TOKEN, $request->query('invite_token'));

        return Socialite::driver($provider)
            ->redirectUrl(route('auth.social.callback', ['provider' => $provider]))
            ->scopes($this->scopesFor($provider))
            ->redirect();
    }

    public function linkRedirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->isSupportedProvider($provider), 404);

        $request->session()->put(
            self::LINK_RETURN_TO,
            $this->safeReturnPath($request->query('return', '/account/settings/security'))
        );

        return Socialite::driver($provider)
            ->redirectUrl(route('auth.social.link.callback', ['provider' => $provider]))
            ->scopes($this->scopesFor($provider))
            ->redirect();
    }

    public function linkCallback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->isSupportedProvider($provider), 404);

        $returnPath = $this->safeReturnPath($request->session()->pull(self::LINK_RETURN_TO));

        try {
            $socialUser = Socialite::driver($provider)
                ->redirectUrl(route('auth.social.link.callback', ['provider' => $provider]))
                ->user();

            $this->linkProviderToCurrentUser($provider, $socialUser, $request);

            return redirect()->to($this->withQuery($returnPath, [
                'social_link' => 'linked',
                'provider' => $provider,
            ]));
        } catch (ValidationException $exception) {
            Log::info('Social link validation failed', [
                'provider' => $provider,
                'user_id' => $request->user()?->id,
                'errors' => $exception->errors(),
            ]);

            return redirect()->to($this->withQuery($returnPath, [
                'social_link' => 'conflict',
                'provider' => $provider,
            ]));
        } catch (\Throwable $exception) {
            Log::warning('Social link failed', [
                'provider' => $provider,
                'user_id' => $request->user()?->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->to($this->withQuery($returnPath, [
            'social_link' => 'error',
            'provider' => $provider,
        ]));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->isSupportedProvider($provider), 404);

        try {
            $socialUser = Socialite::driver($provider)
                ->redirectUrl(route('auth.social.callback', ['provider' => $provider]))
                ->user();

            $user = DB::transaction(fn () => $this->resolveUser($provider, $socialUser, $request));

            if ($user->hasEnabledTwoFactor()) {
                $request->session()->put(self::PENDING_2FA_USER_ID, $user->id);

                return redirect()->to($this->loginPath($request, [
                    'social_2fa' => '1',
                    'return' => $this->safeReturnPath($request->session()->get(self::RETURN_TO)),
                ]));
            }

            Auth::guard('web')->login($user);
            $this->updateUserLogin($user);
            $request->session()->regenerate();

            return redirect()->to($this->safeReturnPath($request->session()->pull(self::RETURN_TO)));
        } catch (ValidationException $exception) {
            Log::info('Social login validation failed', [
                'provider' => $provider,
                'errors' => $exception->errors(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Social login failed', [
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ]);
        }

        return redirect()->to($this->loginPath($request, [
            'social_error' => '1',
            'return' => $this->safeReturnPath($request->session()->get(self::RETURN_TO)),
        ]));
    }

    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $userId = $request->session()->get(self::PENDING_2FA_USER_ID);
        if (! $userId) {
            return response()->json([
                'message' => __('auth.login_session_expired'),
                'error_code' => 'SOCIAL_LOGIN_SESSION_EXPIRED',
            ], 401);
        }

        $user = User::findOrFail($userId);

        if (! $this->twoFactorService->verifyTotp($user, $request->string('code')->toString())) {
            return response()->json([
                'message' => __('auth.invalid_2fa_code'),
                'error_code' => 'INVALID_TOTP_CODE',
            ], 403);
        }

        Auth::guard('web')->login($user);
        $this->updateUserLogin($user);
        $request->session()->forget(self::PENDING_2FA_USER_ID);
        $request->session()->regenerate();

        return response()->json([
            'message' => __('auth.login_successful'),
        ]);
    }

    private function resolveUser(string $provider, SocialiteUser $socialUser, Request $request): User
    {
        $externalId = (string) $socialUser->getId();
        $email = $this->emailFrom($socialUser);

        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => __('auth.social_email_missing'),
            ]);
        }

        $socialLink = UserSocialLink::query()
            ->where('service', $provider)
            ->where('external_id', $externalId)
            ->first();

        if ($socialLink) {
            return $this->acceptPendingInvite($socialLink->user, $request);
        }

        $user = User::query()
            ->where('email', $email)
            ->first();

        if ($user) {
            // Adopting an existing account on a bare email match is a
            // pre-registration hijack: an attacker registers under the
            // victim's address and waits for them to arrive via the provider,
            // landing them in an account the attacker still holds the password
            // to. Only an address both sides have verified is proof of
            // ownership; anything else has to be linked from a session that
            // already proved it owns the account.
            if (
                ! $user->hasVerifiedEmail()
                || $this->verifiedByTeamIdp($user)
                || ! $this->providerVerifiedEmail($provider, $socialUser)
            ) {
                throw ValidationException::withMessages([
                    'email' => __('auth.social_link_required'),
                ]);
            }
        }

        if (! $user) {
            $user = $this->createSocialUser($provider, $socialUser, $email, $request);
        }

        UserSocialLink::query()->create([
            'external_id' => $externalId,
            'service' => $provider,
            'token' => null,
            'user_id' => $user->id,
        ]);

        return $this->acceptPendingInvite($user, $request);
    }

    private function linkProviderToCurrentUser(
        string $provider,
        SocialiteUser $socialUser,
        Request $request,
    ): UserSocialLink {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $externalId = (string) $socialUser->getId();
        $existingLink = UserSocialLink::query()
            ->where('service', $provider)
            ->where('external_id', $externalId)
            ->first();

        if ($existingLink && $existingLink->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'provider' => __('auth.social_link_already_used'),
            ]);
        }

        if ($existingLink) {
            return $existingLink;
        }

        return UserSocialLink::query()->create([
            'external_id' => $externalId,
            'service' => $provider,
            'token' => null,
            'user_id' => $user->id,
        ]);
    }

    private function createSocialUser(
        string $provider,
        SocialiteUser $socialUser,
        string $email,
        Request $request,
    ): User {
        [$firstname, $lastname] = $this->namesFrom($socialUser);
        $invite = $this->pendingInvite($request);
        $verifiedByInvite = $invite && strcasecmp($invite->email, $email) === 0;

        // Same gate as RegisterController: once a self-hosted instance has its
        // first account, only invitees may create new ones. Without this the
        // provider redirect stays an open registration endpoint for anyone
        // holding an account with the configured IdP.
        if (! $verifiedByInvite && ! EditionGate::registrationOpen()) {
            throw ValidationException::withMessages([
                'email' => __('auth.registration_closed'),
            ]);
        }

        $user = $this->createUser->execute([
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'password' => Hash::make(Str::random(48)),
            'language_iso' => app()->getLocale(),
            'source' => "social:{$provider}",
            'email_verified_at' => $verifiedByInvite ? now() : null,
        ]);

        if (! $user->email_verified_at) {
            $this->sendVerificationEmail($user);
        }

        return $user;
    }

    private function acceptPendingInvite(User $user, Request $request): User
    {
        $invite = $this->pendingInvite($request);
        $token = $request->session()->get(self::INVITE_TOKEN);

        if ($invite && is_string($token) && $token !== '') {
            $this->acceptInvite->execute($invite, $user, $token);
            $request->session()->forget([self::INVITE_ID, self::INVITE_TOKEN]);
        }

        return $user->refresh();
    }

    private function pendingInvite(Request $request): ?Invite
    {
        $inviteId = $request->session()->get(self::INVITE_ID);

        if (! is_string($inviteId) || $inviteId === '') {
            return null;
        }

        return Invite::find($inviteId);
    }

    /**
     * Whether this account's verified flag came from a team's own identity
     * provider rather than from the mailbox.
     *
     * JIT provisioning marks an account verified on the strength of a SAML
     * assertion, and any team owner may configure a provider with a
     * certificate of their choosing. Such an account is therefore not proof
     * that the address belongs to whoever holds it, and must not be adopted on
     * an email match — otherwise an attacker JIT-creates an account for an
     * address they do not own and waits for its real owner to sign in with
     * Google. Linking from an authenticated session still works.
     */
    private function verifiedByTeamIdp(User $user): bool
    {
        return str_starts_with((string) $user->source, 'saml:');
    }

    /**
     * Whether the provider states that it verified the address itself.
     *
     * OIDC providers report this as `email_verified`. Socialite's GitHub driver
     * only ever returns the primary address that GitHub reports as verified, so
     * for GitHub the guarantee comes from the driver rather than the payload.
     */
    private function providerVerifiedEmail(string $provider, SocialiteUser $socialUser): bool
    {
        if ($provider === 'github') {
            return true;
        }

        $raw = $this->rawUser($socialUser);

        return (bool) (Arr::get($raw, 'email_verified') ?? Arr::get($raw, 'verified_email') ?? false);
    }

    private function emailFrom(SocialiteUser $socialUser): string
    {
        return Str::lower((string) ($socialUser->getEmail() ?: Arr::get($this->rawUser($socialUser), 'email', '')));
    }

    private function namesFrom(SocialiteUser $socialUser): array
    {
        $raw = $this->rawUser($socialUser);
        $firstname = trim((string) Arr::get($raw, 'given_name', Arr::get($raw, 'first_name', '')));
        $lastname = trim((string) Arr::get($raw, 'family_name', Arr::get($raw, 'last_name', '')));

        if ($firstname !== '' || $lastname !== '') {
            return [$firstname ?: 'b10cks', $lastname ?: 'User'];
        }

        $nameParts = preg_split('/\s+/', trim((string) $socialUser->getName()), 2) ?: [];

        return [
            $nameParts[0] ?? $socialUser->getNickname() ?? 'b10cks',
            $nameParts[1] ?? 'User',
        ];
    }

    private function rawUser(SocialiteUser $socialUser): array
    {
        return method_exists($socialUser, 'getRaw') ? $socialUser->getRaw() : [];
    }

    private function safeReturnPath(mixed $returnPath): string
    {
        if (! is_string($returnPath) || $returnPath === '' || ! str_starts_with($returnPath, '/')) {
            return '/';
        }

        if (str_starts_with($returnPath, '//')) {
            return '/';
        }

        return $returnPath;
    }

    private function loginPath(Request $request, array $query): string
    {
        $inviteId = $request->session()->get(self::INVITE_ID);
        $inviteToken = $request->session()->get(self::INVITE_TOKEN);

        if (is_string($inviteId) && $inviteId !== '') {
            $query['invite_id'] = $inviteId;
        }

        if (is_string($inviteToken) && $inviteToken !== '') {
            $query['invite_token'] = $inviteToken;
        }

        return '/login?'.http_build_query(array_filter($query));
    }

    private function withQuery(string $path, array $query): string
    {
        $separator = str_contains($path, '?') ? '&' : '?';

        return $path.$separator.http_build_query($query);
    }

    private function scopesFor(string $provider): array
    {
        return match ($provider) {
            UserSocialLink::SERVICE_GOOGLE => ['openid', 'profile', 'email'],
            UserSocialLink::SERVICE_GITHUB => ['read:user', 'user:email'],
            default => [],
        };
    }

    private function isSupportedProvider(string $provider): bool
    {
        return in_array($provider, UserSocialLink::SOCIAL_SERVICES, true);
    }

    private function sendVerificationEmail(User $user): void
    {
        $verificationUrl = Cache::remember(
            self::VERIFICATION_CACHE_PREFIX.$user->id,
            self::VERIFICATION_CACHE_TTL,
            fn () => URL::signedRoute(
                'verification.verify',
                [
                    'id' => $user->getRouteKey(),
                    'hash' => sha1($user->email),
                ],
                now()->addMinutes(60)
            )
        );

        $user->notify(new VerifyEmailNotification($verificationUrl));
    }
}
