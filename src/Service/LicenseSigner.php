<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Produces Checkoutly license keys the module can verify offline. The format is
 * fixed by the module's LicenseValidator:
 *
 *   CKLY.<base64url(payload)>.<base64url(RSA-SHA256 signature of payloadPart)>
 *
 * where payload is JSON {id, email, tier, iat, exp, domain} encoded with
 * JSON_UNESCAPED_SLASHES, and the signature is over the base64url payload
 * STRING (not the raw JSON). Must stay byte-compatible with the reference
 * signer or the module will reject the key.
 */
class LicenseSigner
{
    public function __construct(
        #[Autowire('%env(LICENSE_PRIVATE_KEY)%')]
        private readonly string $privateKeyPem = '',
        #[Autowire('%env(LICENSE_PRIVATE_KEY_PATH)%')]
        private readonly string $privateKeyPath = '',
    ) {
    }

    /**
     * Mint a fresh license: generates the id, builds the payload, signs it.
     *
     * @return array{id: string, key: string, payload: array{id: string, email: string, tier: string, iat: int, exp: int, domain: string}}
     */
    public function issue(string $email, string $tier = 'pro', int $days = 0, string $domain = ''): array
    {
        $now = time();
        $payload = [
            'id' => bin2hex(random_bytes(6)),
            'email' => $email,
            'tier' => $tier,
            'iat' => $now,
            'exp' => $days > 0 ? $now + ($days * 86400) : 0,
            'domain' => $domain,
        ];

        return [
            'id' => $payload['id'],
            'key' => $this->sign($payload),
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function sign(array $payload): string
    {
        $payloadPart = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $signature = '';
        if (!openssl_sign($payloadPart, $signature, $this->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Failed to sign license: ' . (openssl_error_string() ?: 'unknown error'));
        }

        return 'CKLY.' . $payloadPart . '.' . $this->base64UrlEncode($signature);
    }

    private function privateKey(): \OpenSSLAsymmetricKey
    {
        $pem = $this->privateKeyPem;
        if ($pem === '' && $this->privateKeyPath !== '' && is_file($this->privateKeyPath)) {
            $pem = (string) file_get_contents($this->privateKeyPath);
        }

        if (trim($pem) === '') {
            throw new \RuntimeException('License signing key is not configured. Set LICENSE_PRIVATE_KEY (PEM) or LICENSE_PRIVATE_KEY_PATH.');
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new \RuntimeException('License signing key could not be loaded: ' . (openssl_error_string() ?: 'invalid PEM'));
        }

        return $key;
    }

    private function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
