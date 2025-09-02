<?php

declare(strict_types=1);

namespace Thenativeweb\Eventsourcingdb;

use RuntimeException;

if (PHP_VERSION_ID < 80400) {
    define('OPENSSL_KEYTYPE_ED25519', 5);
}

final readonly class Ed25519
{
    public function __construct(
        public string $privateKey,
        public string $publicKey,
    ) {
    }
}

final class SigningKey
{
    public string $privateKeyPem;
    public string $publicKeyPem;
    public Ed25519 $ed25519;

    public function __construct()
    {
        if (PHP_VERSION_ID < 80400) {
            $keypair = sodium_crypto_sign_keypair();
            $secretKey = sodium_crypto_sign_secretkey($keypair);

            $privateKey = substr($secretKey, 0, 32);
            $publicKey = substr($secretKey, 32, 32);

            $this->privateKeyPem = $this->generatePem($privateKey, 'PRIVATE KEY');
            $this->publicKeyPem = $this->generatePem($publicKey, 'PUBLIC KEY');
            $this->ed25519 = new Ed25519(
                privateKey: $privateKey,
                publicKey: $publicKey,
            );

            return;
        }

        $privateKeyPem = '';
        $privateKeyRes = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_ED25519,
        ]);

        if ($privateKeyRes === false) {
            throw new RuntimeException('Failed to generate Ed25519 key pair');
        }

        openssl_pkey_export($privateKeyRes, $privateKeyPem, null, [
            'private_key_type' => OPENSSL_KEYTYPE_ED25519,
        ]);

        $details = openssl_pkey_get_details($privateKeyRes);

        $this->privateKeyPem = $privateKeyPem;
        $this->publicKeyPem = $details['key'] ?? '';
        $this->ed25519 = new Ed25519(
            privateKey: $details['ed25519']['priv_key'] ?? '',
            publicKey: $details['ed25519']['pub_key'] ?? '',
        );
    }

    private function generatePem(string $key, string $type): string
    {
        $encodePkcs8 = $this->encodePkcs8($key);

        $pem = "-----BEGIN {$type}-----\n";
        $pem .= chunk_split(base64_encode($encodePkcs8), 64, "\n");
        $pem .= "-----END {$type}-----\n";

        return $pem;
    }

    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $lenBytes = ltrim(pack('N', $length), "\x00");
        return chr(0x80 | strlen($lenBytes)) . $lenBytes;
    }

    private function asn1(int $tag, string $value): string
    {
        return chr($tag) . $this->encodeLength(strlen($value)) . $value;
    }

    private function encodePkcs8(string $key): string
    {
        $version = $this->asn1(0x02, "\x00");
        $algOid = $this->asn1(0x30, $this->asn1(0x06, "\x2B\x65\x70"));
        $privateKey = $this->asn1(0x04, $this->asn1(0x04, $key));
        $pkcs8 = $this->asn1(0x30, $version . $algOid . $privateKey);

        return $pkcs8;
    }
}
