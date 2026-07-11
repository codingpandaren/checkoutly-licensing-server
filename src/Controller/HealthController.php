<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness/readiness probe for external uptime monitoring. Public (no auth) and
 * cheap: confirms the app boots and the database answers. Returns 200 when
 * healthy, 503 otherwise, so a monitor flags both "app down" (no response) and
 * "DB down" (503).
 */
class HealthController extends AbstractController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(Connection $connection): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            return new JsonResponse(['status' => 'degraded', 'db' => false], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['status' => 'ok', 'db' => true]);
    }
}
