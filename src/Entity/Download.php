<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DownloadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per module download, recorded by the first-party /api/download
 * endpoint before it redirects to the actual zip. Anonymous: a download happens
 * before install/licensing, so there is no license link. The IP is stored only
 * as a salted hash, for a rough unique-download estimate.
 */
#[ORM\Entity(repositoryClass: DownloadRepository::class)]
#[ORM\Table(name: 'download')]
#[ORM\Index(name: 'idx_download_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_download_version', columns: ['version'])]
class Download
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $version;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $ipHash;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $country;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $referer;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $version, ?string $ipHash = null, ?string $country = null, ?string $referer = null, ?string $userAgent = null)
    {
        $this->version = $version;
        $this->ipHash = $ipHash;
        $this->country = $country;
        $this->referer = $referer;
        $this->userAgent = $userAgent;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getIpHash(): ?string
    {
        return $this->ipHash;
    }

    public function getCountry(): ?string
    {
        return $this->country;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
