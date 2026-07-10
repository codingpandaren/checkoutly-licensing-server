<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Repository\LicenseRepository;
use App\Service\DomainNormalizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Module-facing heartbeat. The module POSTs {id, domain} (throttled, from its
 * back office) and honours {revoked: bool}. We answer revoked=false only when
 * the license is entitled AND the calling domain matches the one registered in
 * the portal — that is the whole anti-sharing / seat enforcement. An unknown id
 * (no such license on record) is treated as revoked.
 */
class LicenseStatusController extends AbstractController
{
    #[Route('/api/license/status', name: 'api_license_status', methods: ['POST'])]
    public function status(Request $request, LicenseRepository $licenses, DomainNormalizer $normalizer): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_request'], 400);
        }

        $id = trim((string) ($payload['id'] ?? ''));
        if ($id === '') {
            return new JsonResponse(['error' => 'invalid_request'], 400);
        }

        $domain = $normalizer->normalize((string) ($payload['domain'] ?? ''));

        $license = $licenses->findOneByLicenseId($id);
        if ($license === null) {
            return new JsonResponse(['revoked' => true]);
        }

        $license->setLastSeenDomain($domain !== '' ? $domain : null);
        $license->setLastSeenAt(new \DateTimeImmutable());
        $licenses->save($license);

        return new JsonResponse(['revoked' => !$license->grantsAccessTo($domain)]);
    }
}
