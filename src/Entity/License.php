<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LicenseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LicenseRepository::class)]
#[ORM\Table(name: 'license')]
#[ORM\UniqueConstraint(name: 'uniq_license_license_id', columns: ['license_id'])]
#[ORM\HasLifecycleCallbacks]
class License
{
    /**
     * Entitled: the shop should be treated as premium.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRIALING = 'trialing';
    public const STATUS_PAST_DUE = 'past_due';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_UNPAID = 'unpaid';
    public const STATUS_INCOMPLETE = 'incomplete';

    /**
     * Sub statuses that grant access. past_due keeps access during Stripe's
     * dunning window; the subscription flips to canceled/unpaid once it gives up.
     */
    public const ENTITLED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TRIALING,
        self::STATUS_PAST_DUE,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The payload "id" (12 hex) embedded in the signed key; what the module sends
     * back on every heartbeat. Stable for the life of the license.
     */
    #[ORM\Column(length: 32)]
    private string $licenseId;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'licenses')]
    #[ORM\JoinColumn(nullable: false)]
    private User $user;

    #[ORM\Column(length: 20, options: ['default' => 'pro'])]
    private string $tier = 'pro';

    /**
     * The full signed license key ("CKLY.<payload>.<sig>") the merchant pastes.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $licenseKey;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 191, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 20, options: ['default' => 'incomplete'])]
    private string $status = self::STATUS_INCOMPLETE;

    /**
     * Hard kill switch, independent of Stripe status (refund, chargeback, abuse).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $revoked = false;

    /**
     * The single shop domain this license is registered to (normalized). The
     * heartbeat only grants access when the calling domain matches this.
     */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $registeredDomain = null;

    /**
     * Expiry embedded in the signed key. 0 = perpetual (our default; access is
     * governed by subscription status + revocation, not by key expiry).
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $expiresAt = 0;

    /**
     * Last domain seen on a heartbeat and when - for the portal and abuse review.
     */
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $lastSeenDomain = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Whether the module should treat the shop as premium for the given domain.
     * This is the single source of truth behind the heartbeat response.
     */
    public function grantsAccessTo(string $normalizedDomain): bool
    {
        if ($this->revoked) {
            return false;
        }
        if (!in_array($this->status, self::ENTITLED_STATUSES, true)) {
            return false;
        }
        if ($this->registeredDomain === null || $this->registeredDomain === '') {
            return false;
        }

        return $this->registeredDomain === $normalizedDomain;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLicenseId(): string
    {
        return $this->licenseId;
    }

    public function setLicenseId(string $licenseId): static
    {
        $this->licenseId = $licenseId;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getTier(): string
    {
        return $this->tier;
    }

    public function setTier(string $tier): static
    {
        $this->tier = $tier;

        return $this;
    }

    public function getLicenseKey(): string
    {
        return $this->licenseKey;
    }

    public function setLicenseKey(string $licenseKey): static
    {
        $this->licenseKey = $licenseKey;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    public function setRevoked(bool $revoked): static
    {
        $this->revoked = $revoked;

        return $this;
    }

    public function getRegisteredDomain(): ?string
    {
        return $this->registeredDomain;
    }

    public function setRegisteredDomain(?string $registeredDomain): static
    {
        $this->registeredDomain = $registeredDomain;

        return $this;
    }

    public function getExpiresAt(): int
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(int $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getLastSeenDomain(): ?string
    {
        return $this->lastSeenDomain;
    }

    public function setLastSeenDomain(?string $lastSeenDomain): static
    {
        $this->lastSeenDomain = $lastSeenDomain;

        return $this;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

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
