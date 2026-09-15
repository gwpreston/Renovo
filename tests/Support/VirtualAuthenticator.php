<?php

declare(strict_types=1);

namespace App\Tests\Support;

use CBOR\ByteStringObject;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use RuntimeException;

/**
 * A WebAuthn authenticator, in PHP, for the tests.
 *
 * It holds an ES256 key pair and produces exactly what a real authenticator
 * produces: a CBOR attestation object with "none" attestation for registration,
 * and a signed assertion for authentication. Nothing about the server's
 * verification is stubbed or bypassed — the library runs its full ceremony
 * against these bytes, which is the only way a test can say anything useful
 * about whether registration and sign-in actually work.
 *
 * The sign counter is settable so a test can make it go backwards, which is how
 * a cloned authenticator would look and which the ceremony must refuse.
 */
final class VirtualAuthenticator
{
    private const AAGUID = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    /** @var \OpenSSLAsymmetricKey */
    private $privateKey;

    private string $x;
    private string $y;

    private string $credentialId;

    private int $signCount = 0;

    public function __construct(private readonly string $rpId, private readonly string $origin)
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate an EC key: ' . (openssl_error_string() ?: 'unknown'));
        }

        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);
        if ($details === false || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('The generated key has no EC coordinates.');
        }

        // Both coordinates are fixed-width on the wire, and openssl trims
        // leading zero bytes; left-padding them is not cosmetic.
        $this->x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $this->y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);

        $this->credentialId = random_bytes(32);
    }

    public function credentialIdBase64Url(): string
    {
        return self::base64Url($this->credentialId);
    }

    public function setSignCount(int $count): void
    {
        $this->signCount = $count;
    }

    /**
     * The JSON a browser posts back after `navigator.credentials.create()`.
     *
     * @param string $challengeBase64Url The challenge exactly as the options
     *                                   handed it to the browser.
     */
    public function register(string $challengeBase64Url, bool $userVerified = true): string
    {
        $clientData = $this->clientData('webauthn.create', $challengeBase64Url);

        $flags = self::FLAG_USER_PRESENT | self::FLAG_ATTESTED_CREDENTIAL_DATA
            | ($userVerified ? self::FLAG_USER_VERIFIED : 0);

        $authData = $this->authenticatorData($flags, $this->attestedCredentialData());

        $attestationObject = (string) MapObject::create()
            ->add(TextStringObject::create('fmt'), TextStringObject::create('none'))
            ->add(TextStringObject::create('attStmt'), MapObject::create())
            ->add(TextStringObject::create('authData'), ByteStringObject::create($authData));

        return json_encode([
            'id' => self::base64Url($this->credentialId),
            'rawId' => self::base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'attestationObject' => self::base64Url($attestationObject),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * The JSON a browser posts back after `navigator.credentials.get()`.
     *
     * @param string|null $userHandle The handle the credential was bound to, as
     *                                the server stored it. Returned base64url
     *                                encoded, exactly as a browser hands it
     *                                back, which is what a usernameless sign-in
     *                                identifies the account from.
     */
    public function authenticate(
        string $challengeBase64Url,
        ?string $userHandle = null,
        bool $userVerified = true,
    ): string {
        $this->signCount++;

        $clientData = $this->clientData('webauthn.get', $challengeBase64Url);
        $flags = self::FLAG_USER_PRESENT | ($userVerified ? self::FLAG_USER_VERIFIED : 0);
        $authData = $this->authenticatorData($flags);

        // The signature covers the authenticator data and a hash of the client
        // data, in that order. Getting this wrong is the single most common way
        // a hand-rolled ceremony fails to verify.
        $signature = '';
        if (!openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->privateKey, 'sha256')) {
            throw new RuntimeException('Could not sign the assertion.');
        }

        return json_encode([
            'id' => self::base64Url($this->credentialId),
            'rawId' => self::base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'authenticatorData' => self::base64Url($authData),
                'signature' => self::base64Url($signature),
                'userHandle' => $userHandle === null ? null : self::base64Url($userHandle),
            ],
            'clientExtensionResults' => [],
        ], JSON_THROW_ON_ERROR);
    }

    private function clientData(string $type, string $challengeBase64Url): string
    {
        return json_encode([
            'type' => $type,
            'challenge' => $challengeBase64Url,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_THROW_ON_ERROR);
    }

    private function authenticatorData(int $flags, string $attestedCredentialData = ''): string
    {
        return hash('sha256', $this->rpId, true)
            . chr($flags)
            . pack('N', $this->signCount)
            . $attestedCredentialData;
    }

    private function attestedCredentialData(): string
    {
        return self::AAGUID
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . $this->coseKey();
    }

    /**
     * The public key in COSE_Key form: EC2 over P-256, ES256.
     */
    private function coseKey(): string
    {
        return (string) MapObject::create()
            ->add(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2))
            ->add(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7))
            ->add(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1))
            ->add(NegativeIntegerObject::create(-2), ByteStringObject::create($this->x))
            ->add(NegativeIntegerObject::create(-3), ByteStringObject::create($this->y));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
