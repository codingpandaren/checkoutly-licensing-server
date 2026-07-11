<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * What a feature handler returns to the base controller: the normalized payload,
 * whether it was served from cache, and whether it counts as a billable unit for
 * quota. Autocomplete-style calls set billable=false; the completed lookup that
 * actually costs us money sets it true.
 *
 * @phpstan-type NormalizedData array<string, mixed>
 */
final class GatewayResult
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly bool $cached = false,
        public readonly bool $billable = true,
    ) {
    }
}
