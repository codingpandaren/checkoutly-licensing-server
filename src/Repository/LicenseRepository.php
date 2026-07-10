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

    public function findOneByRegisteredDomain(string $domain): ?License
    {
        return $this->findOneBy(['registeredDomain' => $domain]);
    }

    /**
     * Newest-activity-first listing for the admin console, user eager-loaded to
     * avoid an N+1 when rendering the owner column.
     *
     * @return list<License>
     */
    public function findAllForAdmin(): array
    {
        return $this->createQueryBuilder('l')
            ->addSelect('u')
            ->join('l.user', 'u')
            ->orderBy('l.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(License $license, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($license);
        if ($flush) {
            $em->flush();
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
