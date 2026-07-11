<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RecoveryScheduleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The managed-cron booster registration for one licensed shop. The module
 * registers it by piggybacking the license heartbeat (enabled flag + callback
 * URL + trigger token); app:recovery:tick pings the callback on schedule so a
 * low-traffic store still fires its abandoned-cart reminders on time. The shop's
 * own web-cron is the primary trigger, so a blocked/failed ping is harmless.
 */
#[ORM\Entity(repositoryClass: RecoveryScheduleRepository::class)]
#[ORM\Table(name: 'recovery_schedule')]
#[ORM\UniqueConstraint(name: 'uniq_recovery_schedule_license', columns: ['license_id'])]
#[ORM\HasLifecycleCallbacks]
class RecoverySchedule
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: License::class)]
    #[ORM\JoinColumn(nullable: false)]
    private License $license;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    /**
     * The shop's recoverycron front-controller URL (no token in the query - the
     * token travels in the X-Checkoutly-Cron-Token header when we ping).
     */
    #[ORM\Column(length: 400, options: ['default' => ''])]
    private string $callbackUrl = '';

    #[ORM\Column(length: 128, options: ['default' => ''])]
    private string $callbackToken = '';

    #[ORM\Column(options: ['default' => 15])]
    private int $intervalMinutes = 15;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextDueAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRunAt = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastHttpCode = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $consecutiveFailures = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(License $license)
    {
        $this->license = $license;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLicense(): License
    {
        return $this->license;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getCallbackUrl(): string
    {
        return $this->callbackUrl;
    }

    public function setCallbackUrl(string $callbackUrl): static
    {
        $this->callbackUrl = $callbackUrl;

        return $this;
    }

    public function getCallbackToken(): string
    {
        return $this->callbackToken;
    }

    public function setCallbackToken(string $callbackToken): static
    {
        $this->callbackToken = $callbackToken;

        return $this;
    }

    public function getIntervalMinutes(): int
    {
        return $this->intervalMinutes;
    }

    public function setIntervalMinutes(int $intervalMinutes): static
    {
        $this->intervalMinutes = max(1, $intervalMinutes);

        return $this;
    }

    public function getNextDueAt(): ?\DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function setNextDueAt(?\DateTimeImmutable $nextDueAt): static
    {
        $this->nextDueAt = $nextDueAt;

        return $this;
    }

    public function getLastRunAt(): ?\DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    public function setLastRunAt(?\DateTimeImmutable $lastRunAt): static
    {
        $this->lastRunAt = $lastRunAt;

        return $this;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function setLastStatus(?string $lastStatus): static
    {
        $this->lastStatus = $lastStatus;

        return $this;
    }

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    public function setLastHttpCode(?int $lastHttpCode): static
    {
        $this->lastHttpCode = $lastHttpCode;

        return $this;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function setConsecutiveFailures(int $consecutiveFailures): static
    {
        $this->consecutiveFailures = max(0, $consecutiveFailures);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
