<?php

declare(strict_types=1);

namespace App\Gateway;

use App\Entity\License;
use App\Repository\ApiUsageRepository;

/**
 * Per-license monthly quota for gateway features. Limits are config-driven per
 * feature (see services.yaml / GATEWAY_QUOTA_*). A feature with no configured
 * limit is treated as unlimited.
 *
 * The flow is check-then-increment: assertWithinLimit() rejects before we spend
 * money on an upstream call, and record() is called only after a billable unit
 * actually completed. Under concurrency this can overshoot the limit by at most
 * the number of in-flight requests, which is an acceptable trade for never
 * billing a request we rejected.
 */
final class ApiQuota
{
    /**
     * @param array<string, int> $limits feature => monthly limit (0 or missing = unlimited)
     */
    public function __construct(
        private readonly ApiUsageRepository $usage,
        private readonly array $limits = [],
    ) {
    }

    public function assertWithinLimit(License $license, string $feature): void
    {
        $limit = $this->limits[$feature] ?? 0;
        if ($limit <= 0) {
            return;
        }

        if ($this->usage->currentCount($license, $this->period(), $feature) >= $limit) {
            throw GatewayException::quotaExceeded($feature);
        }
    }

    public function record(License $license, string $feature): void
    {
        $this->usage->increment($license, $this->period(), $feature);
    }

    private function period(): string
    {
        return (new \DateTimeImmutable())->format('Ym');
    }
}
