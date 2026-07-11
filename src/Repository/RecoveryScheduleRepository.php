<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\License;
use App\Entity\RecoverySchedule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecoverySchedule>
 */
class RecoveryScheduleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecoverySchedule::class);
    }

    public function findOneByLicense(License $license): ?RecoverySchedule
    {
        return $this->findOneBy(['license' => $license]);
    }

    public function save(RecoverySchedule $schedule, bool $flush = true): void
    {
        $this->getEntityManager()->persist($schedule);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Enabled schedules whose next ping is due (or never scheduled), oldest first.
     *
     * @return RecoverySchedule[]
     */
    public function findDue(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.enabled = true')
            ->andWhere('s.nextDueAt IS NULL OR s.nextDueAt <= :now')
            ->setParameter('now', $now)
            ->orderBy('s.nextDueAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
