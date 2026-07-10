<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\License;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<License>
 */
class LicenseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, License::class);
    }

    public function findOneByLicenseId(string $licenseId): ?License
    {
        return $this->findOneBy(['licenseId' => $licenseId]);
    }

    public function findOneByStripeSubscriptionId(string $stripeSubscriptionId): ?License
    {
        return $this->findOneBy(['stripeSubscriptionId' => $stripeSubscriptionId]);
    }

    public function save(License $license, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($license);
        if ($flush) {
            $em->flush();
        }
    }
}
