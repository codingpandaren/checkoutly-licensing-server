<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Gateway\ApiQuota;
use App\Gateway\GatewayAuthenticator;
use App\Gateway\GatewayException;
use App\Gateway\GatewayResult;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shared plumbing for every /api/v1 feature endpoint: authenticate the bearer,
 * pre-check quota, run the handler, record the billable unit, and wrap the
 * result (or any GatewayException) in the normalized envelope. Handlers never
 * see raw upstream errors leave the building - a failure becomes a normalized
 * error code and the module degrades to the plain field.
 */
abstract class AbstractGatewayController extends AbstractController
{
    public function __construct(
        private readonly GatewayAuthenticator $authenticator,
        private readonly ApiQuota $quota,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param callable(\App\Entity\License): GatewayResult $handler
     */
    protected function handle(Request $request, string $feature, callable $handler): JsonResponse
    {
        try {
            $license = $this->authenticator->authenticate($request);
            $this->quota->assertWithinLimit($license, $feature);

            $result = $handler($license);

            if ($result->billable) {
                $this->quota->record($license, $feature);
            }

            return new JsonResponse([
                'ok' => true,
                'data' => $result->data,
                'cached' => $result->cached,
            ]);
        } catch (GatewayException $e) {
            if ($e->getStatusCode() >= 500) {
                $this->logger->warning('gateway feature failed', ['feature' => $feature, 'error' => $e->getErrorCode(), 'detail' => $e->getMessage()]);
            }

            $response = new JsonResponse(['ok' => false, 'error' => $e->getErrorCode()], $e->getStatusCode());
            if ($e->getStatusCode() === 429) {
                $response->headers->set('Retry-After', '3600');
            }

            return $response;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonBody(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            throw GatewayException::invalidInput('body is not a JSON object');
        }

        return $payload;
    }
}
