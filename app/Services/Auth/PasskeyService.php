<?php

namespace App\Services\Auth;

use App\Enums\PasskeyCeremony;
use App\Models\User;
use App\Models\User\UserPasskey;
use Cose\Algorithms;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\SerializerInterface;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Runs the WebAuthn ceremonies behind passkeys.
 *
 * Every ceremony is two requests: the options carry a fresh challenge that is
 * kept in the session, and the browser's answer is checked against them. The
 * pending options are single-use, so a replayed answer fails. User
 * verification is required throughout, which is why a passkey sign-in stands
 * in for both the password and the TOTP code.
 *
 * The relying party is the host of `app.frontend_url`, the origin the SPA
 * runs on.
 */
class PasskeyService
{
    private const string PENDING_KEY = 'auth.passkey.pending.';

    private const string CONFIRMED_KEY = 'auth.passkey.confirmed_until.';

    /** Seconds a challenge stays valid. Long enough for the sign-in autofill to sit open a while. */
    private const int PENDING_TTL = 300;

    private ?SerializerInterface $serializer = null;

    /** @return array<string, mixed> */
    public function creationOptions(Request $request, User $user): array
    {
        $options = PublicKeyCredentialCreationOptions::create(
            rp: PublicKeyCredentialRpEntity::create(config('app.name'), $this->relyingPartyId()),
            user: PublicKeyCredentialUserEntity::create($user->email, $user->id, $user->display_name),
            challenge: random_bytes(32),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::createPk(Algorithms::COSE_ALGORITHM_RS256),
            ],
            authenticatorSelection: AuthenticatorSelectionCriteria::create(
                userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
            ),
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->descriptors($user),
            timeout: config('auth.passkeys.timeout'),
        );

        return $this->remember($request, PasskeyCeremony::Register, $options, $user);
    }

    /**
     * Verifies the browser's answer to {@see creationOptions()} and stores the
     * new passkey.
     *
     * @param  array<string, mixed>  $credential  The `PublicKeyCredential.toJSON()` of the browser
     *
     * @throws ValidationException
     */
    public function register(Request $request, User $user, array $credential, string $name): UserPasskey
    {
        $options = $this->pull($request, PasskeyCeremony::Register, $user);
        \assert($options instanceof PublicKeyCredentialCreationOptions);

        $response = $this->credential($credential)->response;
        $response instanceof AuthenticatorAttestationResponse || throw $this->invalid();

        try {
            $record = AuthenticatorAttestationResponseValidator::create($this->ceremonies()->creationCeremony())
                ->check($response, $options, $this->relyingPartyId());
        } catch (Throwable $e) {
            throw $this->invalid($e);
        }

        try {
            return $user->passkeys()->create([
                'name' => $name,
                'credential_hash' => $this->hash($record->publicKeyCredentialId),
                'record' => $this->serialize($record),
                'aaguid' => $record->aaguid->toRfc4122(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['credential' => __('auth.passkey_already_registered')]);
        }
    }

    /**
     * Options to sign in with any passkey ({@see PasskeyCeremony::Login}, no
     * user) or to confirm with one of `$user`'s ({@see PasskeyCeremony::Confirm}).
     *
     * @return array<string, mixed>
     */
    public function requestOptions(Request $request, PasskeyCeremony $ceremony, ?User $user = null): array
    {
        \assert($ceremony !== PasskeyCeremony::Register);
        \assert($ceremony === PasskeyCeremony::Login || $user !== null);

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: $this->relyingPartyId(),
            allowCredentials: $user ? $this->descriptors($user) : [],
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: config('auth.passkeys.timeout'),
        );

        return $this->remember($request, $ceremony, $options, $user);
    }

    /**
     * Verifies the browser's answer to {@see requestOptions()} and returns the
     * passkey it was made with. For a confirmation the passkey must belong to
     * `$user`.
     *
     * @param  array<string, mixed>  $credential  The `PublicKeyCredential.toJSON()` of the browser
     *
     * @throws ValidationException
     */
    public function verify(Request $request, PasskeyCeremony $ceremony, array $credential, ?User $user = null): UserPasskey
    {
        $options = $this->pull($request, $ceremony, $user);
        \assert($options instanceof PublicKeyCredentialRequestOptions);

        $publicKeyCredential = $this->credential($credential);
        $response = $publicKeyCredential->response;
        $response instanceof AuthenticatorAssertionResponse || throw $this->invalid();

        $passkey = UserPasskey::query()
            ->where('credential_hash', $this->hash($publicKeyCredential->rawId))
            ->first();

        if (! $passkey || ($user && $passkey->user_id !== $user->id)) {
            throw $this->invalid();
        }

        try {
            $record = AuthenticatorAssertionResponseValidator::create($this->ceremonies()->requestCeremony())
                ->check($this->record($passkey), $response, $options, $this->relyingPartyId(), $response->userHandle);
        } catch (Throwable $e) {
            throw $this->invalid($e);
        }

        $passkey->forceFill([
            'record' => $this->serialize($record),
            'last_used_at' => now(),
        ])->save();

        return $passkey;
    }

    /**
     * A passkey confirmation stands in for the TOTP code and the password at
     * the step-up middleware for a few minutes, in this session only.
     */
    public function markConfirmed(Request $request, User $user): void
    {
        $request->session()->put(
            self::CONFIRMED_KEY.$user->id,
            now()->addMinutes((int) config('auth.passkeys.confirmation_window'))->getTimestamp(),
        );
    }

    public function hasRecentConfirmation(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $until = $request->session()->get(self::CONFIRMED_KEY.$user->id);

        return \is_int($until) && $until > now()->getTimestamp();
    }

    public function relyingPartyId(): string
    {
        return (string) parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
    }

    /** @return array<string, mixed> */
    private function remember(Request $request, PasskeyCeremony $ceremony, PublicKeyCredentialOptions $options, ?User $user): array
    {
        $json = $this->serialize($options);

        $request->session()->put(self::PENDING_KEY.$ceremony->value, [
            'user_id' => $user?->id,
            'options' => $json,
            'expires_at' => now()->addSeconds(self::PENDING_TTL)->getTimestamp(),
        ]);

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Takes the pending options of a ceremony out of the session. Expired
     * options and options issued to another user (a sign-out and sign-in in
     * between) do not count.
     *
     * @throws ValidationException
     */
    private function pull(Request $request, PasskeyCeremony $ceremony, ?User $user): PublicKeyCredentialOptions
    {
        $pending = $request->session()->pull(self::PENDING_KEY.$ceremony->value);

        if (
            ! \is_array($pending)
            || ($pending['user_id'] ?? null) !== $user?->id
            || ($pending['expires_at'] ?? 0) < now()->getTimestamp()
        ) {
            throw ValidationException::withMessages(['credential' => __('auth.passkey_expired')]);
        }

        return $this->serializer()->deserialize(
            $pending['options'],
            $ceremony === PasskeyCeremony::Register
                ? PublicKeyCredentialCreationOptions::class
                : PublicKeyCredentialRequestOptions::class,
            'json',
        );
    }

    /** @param array<string, mixed> $credential */
    private function credential(array $credential): PublicKeyCredential
    {
        try {
            return $this->serializer()->deserialize(
                json_encode($credential, JSON_THROW_ON_ERROR),
                PublicKeyCredential::class,
                'json',
            );
        } catch (Throwable) {
            throw $this->invalid();
        }
    }

    private function record(UserPasskey $passkey): CredentialRecord
    {
        return $this->serializer()->deserialize($passkey->record, CredentialRecord::class, 'json');
    }

    /** @return list<PublicKeyCredentialDescriptor> */
    private function descriptors(User $user): array
    {
        return $user->passkeys()
            ->get()
            ->map(fn (UserPasskey $passkey) => $this->record($passkey)->getPublicKeyCredentialDescriptor())
            ->values()
            ->all();
    }

    private function ceremonies(): CeremonyStepManagerFactory
    {
        $url = parse_url((string) config('app.frontend_url'));
        $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins([$origin]);

        return $factory;
    }

    private function serialize(object $value): string
    {
        return $this->serializer()->serialize($value, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);
    }

    private function serializer(): SerializerInterface
    {
        return $this->serializer ??= (new WebauthnSerializerFactory(
            AttestationStatementSupportManager::create([new NoneAttestationStatementSupport]),
        ))->create();
    }

    private function hash(string $credentialId): string
    {
        return hash('sha256', $credentialId);
    }

    /** A failed check is the client's doing, so it is logged for support, not reported. */
    private function invalid(?Throwable $reason = null): ValidationException
    {
        if ($reason) {
            Log::notice('Passkey verification failed', ['reason' => $reason->getMessage()]);
        }

        return ValidationException::withMessages(['credential' => __('auth.passkey_invalid')]);
    }
}
