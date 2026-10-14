<?php

namespace Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Authentificateur WebAuthn logiciel, pour les tests uniquement.
 *
 * Il produit de vraies réponses (clé ECDSA P-256, attestation « none »,
 * signatures ES256) : le serveur les vérifie avec la même bibliothèque
 * qu'en production, sans simulacre. Cela permet de tester aussi les refus
 * (signature d'une autre clé, défi rejoué, origine différente...).
 */
final class FakeAuthenticator
{
    public readonly string $credentialId;

    private OpenSSLAsymmetricKey $key;

    private int $counter = 0;

    public function __construct(
        private readonly string $rpId = 'localhost',
        public string $origin = 'http://localhost',
        public bool $userVerified = true,
    ) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        if ($key === false) {
            throw new RuntimeException('Impossible de générer la clé de test.');
        }

        $this->key = $key;
        $this->credentialId = random_bytes(16);
    }

    /**
     * Réponse à des options d'enregistrement, au format envoyé par le navigateur.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function create(array $options): array
    {
        $clientData = $this->clientData('webauthn.create', (string) $options['challenge']);

        $attestationObject = "\xA3"
            .$this->text('fmt').$this->text('none')
            .$this->text('attStmt')."\xA0"
            .$this->text('authData').$this->bytes($this->authenticatorData(attested: true));

        return [
            'id' => self::base64Url($this->credentialId),
            'rawId' => self::base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'attestationObject' => self::base64Url($attestationObject),
                'transports' => ['internal'],
            ],
            'clientExtensionResults' => [],
        ];
    }

    /**
     * Réponse à des options de vérification (assertion signée).
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function get(array $options): array
    {
        $clientData = $this->clientData('webauthn.get', (string) $options['challenge']);
        $authenticatorData = $this->authenticatorData(attested: false);

        if (! openssl_sign($authenticatorData.hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signature de test impossible.');
        }

        return [
            'id' => self::base64Url($this->credentialId),
            'rawId' => self::base64Url($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => self::base64Url($clientData),
                'authenticatorData' => self::base64Url($authenticatorData),
                'signature' => self::base64Url($signature),
                'userHandle' => null,
            ],
            'clientExtensionResults' => [],
        ];
    }

    public static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function clientData(string $type, string $challenge): string
    {
        return (string) json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Données de l'authentificateur : empreinte du domaine, indicateurs
     * (présence, vérification de l'utilisateur, données attestées), compteur.
     */
    private function authenticatorData(bool $attested): string
    {
        $flags = 0x01 | ($this->userVerified ? 0x04 : 0) | ($attested ? 0x40 : 0);
        $data = hash('sha256', $this->rpId, true).chr($flags).pack('N', $attested ? 0 : ++$this->counter);

        if ($attested) {
            $data .= str_repeat("\0", 16)
                .pack('n', strlen($this->credentialId))
                .$this->credentialId
                .$this->coseKey();
        }

        return $data;
    }

    /**
     * Clé publique au format COSE (EC2, ES256), encodée en CBOR.
     */
    private function coseKey(): string
    {
        $details = openssl_pkey_get_details($this->key);
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);

        // {1: 2 (EC2), 3: -7 (ES256), -1: 1 (P-256), -2: x, -3: y}
        return "\xA5"."\x01\x02"."\x03\x26"."\x20\x01"."\x21".$this->bytes($x)."\x22".$this->bytes($y);
    }

    private function text(string $value): string
    {
        return $this->header(3, strlen($value)).$value;
    }

    private function bytes(string $value): string
    {
        return $this->header(2, strlen($value)).$value;
    }

    private function header(int $major, int $length): string
    {
        $type = $major << 5;

        return match (true) {
            $length < 24 => chr($type | $length),
            $length < 256 => chr($type | 24).chr($length),
            default => chr($type | 25).pack('n', $length),
        };
    }
}
