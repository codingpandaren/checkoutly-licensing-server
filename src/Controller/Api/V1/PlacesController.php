<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Entity\License;
use App\Gateway\ApiQuota;
use App\Gateway\GatewayAuthenticator;
use App\Gateway\GooglePlacesClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Phase B: Google Places address autocomplete via the gateway. autocomplete is
 * free-flowing (not billable); details completes the session Google bills for and
 * is the billable unit for the "places" quota. Both share one session token so
 * Google bills one session per address entry (§7).
 */
final class PlacesController extends AbstractGatewayController
{
    public function __construct(
        GatewayAuthenticator $authenticator,
        ApiQuota $quota,
        LoggerInterface $logger,
        private readonly GooglePlacesClient $places,
    ) {
        parent::__construct($authenticator, $quota, $logger);
    }

    #[Route('/api/v1/places/autocomplete', name: 'api_v1_places_autocomplete', methods: ['POST'])]
    public function autocomplete(Request $request): JsonResponse
    {
        return $this->handle($request, 'places', function (License $license) use ($request) {
            $body = $this->jsonBody($request);

            return $this->places->autocomplete(
                (string) ($body['q'] ?? ''),
                (string) ($body['session'] ?? ''),
                (string) ($body['lang'] ?? ''),
            );
        });
    }

    #[Route('/api/v1/places/details', name: 'api_v1_places_details', methods: ['POST'])]
    public function details(Request $request): JsonResponse
    {
        return $this->handle($request, 'places', function (License $license) use ($request) {
            $body = $this->jsonBody($request);

            return $this->places->details(
                (string) ($body['place_id'] ?? ''),
                (string) ($body['session'] ?? ''),
            );
        });
    }
}
