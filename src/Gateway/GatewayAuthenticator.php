<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Entity\License;
use App\Repository\LicenseRepository;
use App\Service\DomainNormalizer;
use App\Service\LicenseKeyVerifier;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Turns a raw gateway request into an authenticated License, or throws a
 * GatewayException carrying the normalized error the client should see.
 *
 * Order matches the design doc (§4): per-IP rate limit, then stateless key
 * verification (no DB hit for a forged/expired key), then the license record and
 * entitlement, then domain binding. The domain header is spoofable; the real
 * backstop against a leaked key is quota + anomaly detection, not this check.
 */
final class GatewayAuthenticator
{
    public function __construct(
        private readonly LicenseKeyVerifier $verifier,
        private readonly LicenseRepository $licenses,
        private readonly DomainNormalizer $normalizer,
        #[Autowire(service: 'limiter.gateway_api')]
        private readonly RateLimiterFactory $rateLimiter,
    ) {
    }

    public function authenticate(Request $request): License
    {
        if (!$this->rateLimiter->create($request->getClientIp())->consume()->isAccepted()) {
            throw GatewayException::rateLimited();
        }

        $key = $this->bearerToken($request);
        $result = $this->verifier->verify($key);
        if (!$result['valid']) {
            throw GatewayException::unauthorized($result['reason']);
        }

        $license = $this->licenses->findOneByLicenseId($result['id']);
        if ($license === null) {
            throw GatewayException::unauthorized('unknown license');
        }

        if ($license->isRevoked()) {
            throw GatewayException::notEntitled('revoked');
        }
        if (!in_array($license->getStatus(), License::ENTITLED_STATUSES, true)) {
            throw GatewayException::notEntitled('status ' . $license->getStatus());
        }

        $domain = $this->normalizer->normalize((string) $request->headers->get('X-Checkoutly-Domain', ''));
        if (!License::isLocalDomain($domain)) {
            $registered = (string) $license->getRegisteredDomain();
            if ($registered === '' || $registered !== $domain) {
                throw GatewayException::domainMismatch($registered, $domain);
            }
        }

        return $license;
    }

    private function bearerToken(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        throw GatewayException::unauthorized('missing bearer');
    }
}
