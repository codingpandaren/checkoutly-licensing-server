<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Per-license, per-feature, per-month usage counter for the API gateway. The
 * period ("YYYYMM") is part of the unique key, so a new row starts every month
 * and quota "resets" implicitly - there is no reset job.
 *
 * The counter is incremented once per billable unit (a validated VAT lookup, a
 * completed Places session), not once per HTTP request.
 */
#[ORM\Entity(repositoryClass: ApiUsageRepository::class)]
#[ORM\Table(name: 'api_usage')]
#[ORM\UniqueConstraint(name: 'uniq_api_usage_license_period_feature', columns: ['license_id', 'period', 'feature'])]
class ApiUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: License::class)]
    #[ORM\JoinColumn(nullable: false)]
    private License $license;

    /**
     * Billing period, "YYYYMM".
     */
    #[ORM\Column(length: 6)]
    private string $period;

    #[ORM\Column(length: 32)]
    private string $feature;

    #[ORM\Column(options: ['default' => 0])]
    private int $count = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(License $license, string $period, string $feature)
    {
        $this->license = $license;
        $this->period = $period;
        $this->feature = $feature;
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

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function getFeature(): string
    {
        return $this->feature;
    }

    public function getCount(): int
    {
        return $this->count;
    }
}
