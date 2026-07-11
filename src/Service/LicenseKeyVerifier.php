<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stateless verification of a Checkoutly license key, server-side. This is the
 * mirror image of the module's Checkoutly\License\LicenseValidator: a key is
 * "CKLY.<base64url(payload)>.<base64url(signature)>" where the signature is an
 * RSA-SHA256 signature over the base64url payload STRING.
 *
 * The gateway uses this to authenticate the bearer credential without a DB hit,
 * so a forged or expired key is rejected before we touch the license table.
 *
 * The public key is derived from the configured signing private key by default,
 * which makes it impossible for the two to drift. A LICENSE_PUBLIC_KEY override
 * exists for gateway nodes that are deployed with only the public half.
 */
class LicenseKeyVerifier
{
    private const PREFIX = 'CKLY';

    private ?string $publicKeyPem = null;

    public function __construct(
        #[Autowire('%env(LICENSE_PUBLIC_KEY)%')]
        private readonly string $configuredPublicKey = '',
        #[Autowire('%env(LICENSE_PRIVATE_KEY)%')]
        private readonly string $privateKeyPem = '',
        #[Autowire('%env(LICENSE_PRIVATE_KEY_PATH)%')]
        private readonly string $privateKeyPath = '',
    ) {
    }

    /**
     * @return array{valid: bool, reason: string, id: string, email: string, tier: string, exp: int, domain: string}
     */
    public function verify(string $key): array
    {
        $fail = static function (string $reason): array {
            return ['valid' => false, 'reason' => $reason, 'id' => '', 'email' => '', 'tier' => '', 'exp' => 0, 'domain' => ''];
        };

        $key = trim($key);
        if ($key === '') {
            return $fail('empty');
        }

        $parts = explode('.', $key);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return $fail('malformed');
        }

        $payloadPart = $parts[1];
        $signature = $this->base64UrlDecode($parts[2]);
        if ($signature === '') {
            return $fail('malformed');
        }

        $verified = openssl_verify($payloadPart, $signature, $this->publicKey(), OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return $fail('bad_signature');
        }

        $payload = json_decode($this->base64UrlDecode($payloadPart), true);
        if (!is_array($payload)) {
            return $fail('bad_payload');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp > 0 && $exp < time()) {
            return $fail('expired');
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'id' => (string) ($payload['id'] ?? ''),
            'email' => (string) ($payload['email'] ?? ''),
            'tier' => (string) ($payload['tier'] ?? 'pro'),
            'exp' => $exp,
            'domain' => (string) ($payload['domain'] ?? ''),
        ];
    }

    private function publicKey(): string
    {
        if ($this->publicKeyPem !== null) {
            return $this->publicKeyPem;
        }

        if (trim($this->configuredPublicKey) !== '') {
            return $this->publicKeyPem = $this->configuredPublicKey;
        }

        $pem = $this->privateKeyPem;
        if (trim($pem) === '' && $this->privateKeyPath !== '' && is_file($this->privateKeyPath)) {
            $pem = (string) file_get_contents($this->privateKeyPath);
        }
        if (trim($pem) === '') {
            throw new \RuntimeException('No license public key configured: set LICENSE_PUBLIC_KEY or a signing private key to derive it from.');
        }

        $private = openssl_pkey_get_private($pem);
        if ($private === false) {
            throw new \RuntimeException('License signing key could not be loaded: ' . (openssl_error_string() ?: 'invalid PEM'));
        }

        $details = openssl_pkey_get_details($private);
        if ($details === false || !isset($details['key'])) {
            throw new \RuntimeException('Could not derive public key from the signing private key.');
        }

        return $this->publicKeyPem = (string) $details['key'];
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }
}
