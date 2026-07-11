<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Entity\License;
use App\Gateway\ApiQuota;
use App\Gateway\GatewayAuthenticator;
use App\Gateway\ViesClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase A endpoint: EU VAT validation via VIES. Free upstream, so this proves the
 * whole gateway pipe (bearer auth -> entitlement -> domain -> quota -> upstream
 * -> normalized envelope) at zero marginal cost.
 */
final class VatController extends AbstractGatewayController
{
    public function __construct(
        GatewayAuthenticator $authenticator,
        ApiQuota $quota,
        LoggerInterface $logger,
        private readonly ViesClient $vies,
    ) {
        parent::__construct($authenticator, $quota, $logger);
    }

    #[Route('/api/v1/vat/validate', name: 'api_v1_vat_validate', methods: ['POST'])]
    public function validate(Request $request): JsonResponse
    {
        return $this->handle($request, 'vat', function (License $license) use ($request) {
            $body = $this->jsonBody($request);

            return $this->vies->validate(
                (string) ($body['country'] ?? ''),
                (string) ($body['vat'] ?? ''),
            );
        });
    }
}
