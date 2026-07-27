<?php

namespace App\Services\Auth;

use App\Actions\User\CreateUser;
use App\Enums\MembershipSource;
use App\Models\Management\TeamSamlProvider;
use App\Models\Management\UserSamlIdentity;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Settings;

class SamlLoginService
{
    public const string NAME_ID_MAPPING = 'NameID';

    public function __construct(
        private readonly CreateUser $createUser,
        private readonly MembershipService $membershipService,
    ) {}

    public function authFor(TeamSamlProvider $provider): Auth
    {
        return new Auth($this->settingsFor($provider));
    }

    public function metadataFor(TeamSamlProvider $provider): string
    {
        $settings = new Settings($this->settingsFor($provider), true);
        $metadata = $settings->getSPMetadata();
        $errors = $settings->validateMetadata($metadata);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'metadata' => $errors,
            ]);
        }

        return $metadata;
    }

    public function settingsFor(TeamSamlProvider $provider): array
    {
        $team = $provider->team()->firstOrFail();
        $metadataUrl = route('auth.saml.metadata', ['team' => $team]);
        $acsUrl = route('auth.saml.acs', ['team' => $team]);
        $slsUrl = route('auth.saml.sls', ['team' => $team]);

        return [
            'strict' => $provider->strict,
            'debug' => config('app.debug'),
            'sp' => [
                'entityId' => $metadataUrl,
                'assertionConsumerService' => [
                    'url' => $acsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'singleLogoutService' => [
                    'url' => $slsUrl,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'NameIDFormat' => $provider->name_id_format,
                'x509cert' => $provider->sp_x509_cert ?: '',
                'privateKey' => $provider->sp_private_key ?: '',
            ],
            'idp' => [
                'entityId' => $provider->idp_entity_id,
                'singleSignOnService' => [
                    'url' => $provider->sso_url,
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => $provider->slo_url ?: '',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $provider->idp_x509_cert,
            ],
            'security' => [
                'authnRequestsSigned' => $provider->sign_authn_requests,
                'logoutRequestSigned' => $provider->sign_logout_requests,
                'wantMessagesSigned' => $provider->want_messages_signed,
                'wantAssertionsSigned' => $provider->want_assertions_signed,
                'wantAssertionsEncrypted' => $provider->want_assertions_encrypted,
                'wantNameId' => true,
                'wantNameIdEncrypted' => false,
                'requestedAuthnContext' => false,
                'signatureAlgorithm' => $provider->signature_algorithm,
                'digestAlgorithm' => $provider->digest_algorithm,
                'lowercaseUrlencoding' => true,
            ],
        ];
    }

    public function completeLogin(TeamSamlProvider $provider, Auth $auth, ?string $requestId): User
    {
        $auth->processResponse($requestId);

        if ($auth->getErrors() !== [] || ! $auth->isAuthenticated()) {
            throw ValidationException::withMessages([
                'saml' => [$auth->getLastErrorReason() ?: 'The SAML response could not be validated.'],
            ]);
        }

        return DB::transaction(function () use ($provider, $auth): User {
            $attributes = $auth->getAttributes();
            $mapping = $provider->attribute_mapping ?: [];
            $nameId = (string) $auth->getNameId();
            $externalId = $this->mappedValue($attributes, $mapping['external_id'] ?? self::NAME_ID_MAPPING, $nameId);

            if ($externalId === '') {
                $externalId = $nameId;
            }

            if ($externalId === '') {
                throw ValidationException::withMessages([
                    'external_id' => ['The SAML assertion did not include a stable NameID or external id.'],
                ]);
            }

            $email = Str::lower($this->mappedValue($attributes, $mapping['email'] ?? 'email', $nameId));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'email' => ['The SAML assertion did not include a valid email address.'],
                ]);
            }

            $identity = UserSamlIdentity::query()
                ->where('team_saml_provider_id', $provider->id)
                ->where('external_id', $externalId)
                ->first();

            $user = $identity?->user;
            if (! $user) {
                // Matching on the asserted email alone would let any team that
                // configures its own IdP sign in as any account on the
                // platform. A team's IdP may only vouch for its own members;
                // everyone else has to go through JIT provisioning below.
                //
                // Membership alone is not enough either: any owner may both
                // configure a SAML provider and attach an arbitrary user id to
                // their team, which would hand the attacker the membership
                // this check looks for. Only a membership the user themselves
                // agreed to — an accepted invite, their own team, or an
                // account this very provider created — counts as a claim.
                $user = User::query()
                    ->where('email', $email)
                    ->whereHas('teams', fn ($query) => $query
                        ->whereKey($provider->team_id)
                        ->whereIn('team_user.source', MembershipSource::samlTrusted()))
                    ->first();
            }

            if (! $user) {
                if (! $provider->allow_jit) {
                    throw ValidationException::withMessages([
                        'email' => ['This account is not allowed to sign in with this team SAML provider.'],
                    ]);
                }

                $user = $this->createSamlUser($provider, $attributes, $mapping, $email);
            }

            $role = $this->resolveRole($provider, $attributes);
            $this->membershipService->assignTeamRole(
                $provider->team()->firstOrFail(),
                $user,
                $role,
                MembershipSource::Saml,
            );

            UserSamlIdentity::query()->updateOrCreate(
                $identity
                    ? [
                        'team_saml_provider_id' => $provider->id,
                        'external_id' => $externalId,
                    ]
                    : [
                        'team_saml_provider_id' => $provider->id,
                        'user_id' => $user->id,
                    ],
                [
                    'user_id' => $user->id,
                    'external_id' => $externalId,
                    'name_id' => $nameId ?: null,
                    'session_index' => $auth->getSessionIndex() ?: null,
                    'last_login_at' => now(),
                ],
            );

            $provider->forceFill(['last_login_at' => now()])->save();

            return $user->refresh();
        });
    }

    private function createSamlUser(
        TeamSamlProvider $provider,
        array $attributes,
        array $mapping,
        string $email,
    ): User {
        $firstname = $this->mappedValue($attributes, $mapping['first_name'] ?? 'firstName');
        $lastname = $this->mappedValue($attributes, $mapping['last_name'] ?? 'lastName');

        if ($firstname === '' && $lastname === '') {
            [$firstname, $lastname] = $this->namesFromEmail($email);
        }

        return $this->createUser->execute([
            'email' => $email,
            'firstname' => $firstname ?: 'b10cks',
            'lastname' => $lastname ?: 'User',
            'password' => Hash::make(Str::random(48)),
            'language_iso' => app()->getLocale(),
            'source' => "saml:{$provider->team_id}",
            'email_verified_at' => now(),
        ]);
    }

    private function resolveRole(TeamSamlProvider $provider, array $attributes): string
    {
        if (! $provider->role_attribute) {
            return $provider->default_role;
        }

        $values = Arr::wrap($attributes[$provider->role_attribute] ?? []);
        $roleMapping = $provider->role_mapping ?: [];

        foreach ($values as $value) {
            $role = $roleMapping[(string) $value] ?? null;
            if ($role) {
                return $role;
            }
        }

        return $provider->default_role;
    }

    private function mappedValue(array $attributes, ?string $attribute, string $nameId = ''): string
    {
        if (! $attribute) {
            return '';
        }

        if ($attribute === self::NAME_ID_MAPPING) {
            return trim($nameId);
        }

        $values = Arr::wrap($attributes[$attribute] ?? []);

        return trim((string) ($values[0] ?? ''));
    }

    private function namesFromEmail(string $email): array
    {
        $localPart = Str::before($email, '@');
        $parts = preg_split('/[._-]+/', $localPart, 2) ?: [];

        return [
            Str::headline($parts[0] ?? 'b10cks'),
            Str::headline($parts[1] ?? 'User'),
        ];
    }
}
