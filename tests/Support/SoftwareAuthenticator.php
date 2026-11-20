<?php

namespace Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use ParagonIE\ConstantTime\Base64UrlSafe;

/**
 * A platform authenticator in PHP: one ES256 passkey that answers WebAuthn
 * creation and request options the way a browser's `PublicKeyCredential.toJSON()`
 * would, so the server-side checks run against real signatures.
 */
class SoftwareAuthenticator
{
    private const int FLAG_USER_PRESENT = 0x01;

    private const int FLAG_USER_VERIFIED = 0x04;

    private const int FLAG_ATTESTED_DATA = 0x40;

    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private ?string $userHandle = null;

    private int $counter = 0;

    public function __construct(private readonly string $origin)
    {
        $this->credentialId = random_bytes(32);
        $this->key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    }

    /**
     * @param  array<string, mixed>  $options  Creation options as the server sent them
     * @return array<string, mixed>
     */
    public function create(array $options): array
    {
        $this->userHandle = Base64UrlSafe::decodeNoPadding($options['user']['id']);
        $clientData = $this->clientData('webauthn.create', $options['challenge']);

        $authData = $this->authData($options['rp']['id'], self::FLAG_ATTESTED_DATA)
            .str_repeat("\0", 16)
            .pack('n', \strlen($this->credentialId))
            .$this->credentialId
            .$this->coseKey();

        $attestationObject = MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return $this->credential([
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
            'attestationObject' => Base64UrlSafe::encodeUnpadded((string) $attestationObject),
            'transports' => ['internal'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $options  Request options as the server sent them
     * @return array<string, mixed>
     */
    public function get(array $options): array
    {
        $clientData = $this->clientData('webauthn.get', $options['challenge']);
        $authData = $this->authData($options['rpId'], 0);

        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $this->credential([
            'clientDataJSON' => Base64UrlSafe::encodeUnpadded($clientData),
            'authenticatorData' => Base64UrlSafe::encodeUnpadded($authData),
            'signature' => Base64UrlSafe::encodeUnpadded($signature),
            'userHandle' => Base64UrlSafe::encodeUnpadded((string) $this->userHandle),
        ]);
    }

    private function clientData(string $type, string $challenge): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    private function authData(string $rpId, int $extraFlags): string
    {
        $this->counter++;

        return hash('sha256', $rpId, true)
            .\chr(self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | $extraFlags)
            .pack('N', $this->counter);
    }

    private function coseKey(): string
    {
        $ec = openssl_pkey_get_details($this->key)['ec'];

        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)));
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function credential(array $response): array
    {
        $id = Base64UrlSafe::encodeUnpadded($this->credentialId);

        return [
            'id' => $id,
            'rawId' => $id,
            'type' => 'public-key',
            'response' => $response,
            'clientExtensionResults' => [],
            'authenticatorAttachment' => 'platform',
        ];
    }
}
