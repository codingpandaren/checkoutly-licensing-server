<?php

declare(strict_types=1);

namespace App\Gateway;

/**
 * A gateway request that must terminate with a normalized error envelope. The
 * error code is the machine-readable value the module keys its degradation
 * matrix off (invalid_input, quota_exceeded, upstream_unavailable, not_entitled,
 * domain_mismatch, unauthorized, rate_limited); the raw message is for our logs
 * only and is never sent to the client.
 */
final class GatewayException extends \RuntimeException
{
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $logMessage = '',
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $errorCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public static function unauthorized(string $reason): self
    {
        return new self(401, 'unauthorized', 'license key rejected: ' . $reason);
    }

    public static function notEntitled(string $reason): self
    {
        return new self(403, 'not_entitled', $reason);
    }

    public static function domainMismatch(string $expected, string $got): self
    {
        return new self(403, 'domain_mismatch', sprintf('registered=%s got=%s', $expected, $got));
    }

    public static function quotaExceeded(string $feature): self
    {
        return new self(429, 'quota_exceeded', 'quota exceeded for ' . $feature);
    }

    public static function rateLimited(): self
    {
        return new self(429, 'rate_limited', 'per-ip rate limit');
    }

    public static function invalidInput(string $reason): self
    {
        return new self(400, 'invalid_input', $reason);
    }

    public static function upstreamUnavailable(string $reason): self
    {
        return new self(502, 'upstream_unavailable', $reason);
    }
}
