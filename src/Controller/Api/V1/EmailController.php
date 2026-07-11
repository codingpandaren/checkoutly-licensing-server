<?php

declare(strict_types=1);

namespace App\Controller\Api\V1;

use App\Entity\License;
use App\Gateway\ApiQuota;
use App\Gateway\EmailVerifier;
use App\Gateway\GatewayAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Checkout email quality check: syntax, "did you mean gmail.com?" typo
 * suggestion, disposable-domain detection, and MX/deliverability. Advisory on the
 * module side (annotates the field, never blocks the order).
 */
final class EmailController extends AbstractGatewayController
{
    public function __construct(
        GatewayAuthenticator $authenticator,
        ApiQuota $quota,
        LoggerInterface $logger,
        private readonly EmailVerifier $verifier,
    ) {
        parent::__construct($authenticator, $quota, $logger);
    }

    #[Route('/api/v1/email/verify', name: 'api_v1_email_verify', methods: ['POST'])]
    public function verify(Request $request): JsonResponse
    {
        return $this->handle($request, 'email', function (License $license) use ($request) {
            $body = $this->jsonBody($request);

            return $this->verifier->verify((string) ($body['email'] ?? ''));
        });
    }
}
