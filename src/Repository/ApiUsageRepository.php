<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiUsage;
use App\Entity\License;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiUsage>
 */
class ApiUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiUsage::class);
    }

    /**
     * Usage recorded so far in the given period/feature for a license. Zero when
     * the license has not yet been billed this period.
     */
    public function currentCount(License $license, string $period, string $feature): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT count FROM api_usage WHERE license_id = :id AND period = :period AND feature = :feature',
            ['id' => $license->getId(), 'period' => $period, 'feature' => $feature],
        );

        return $count === false ? 0 : (int) $count;
    }

    /**
     * Atomically record one billable unit and return the resulting count for the
     * period. Uses an upsert so concurrent requests can't lose an increment.
     */
    public function increment(License $license, string $period, string $feature): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement(
            'INSERT INTO api_usage (license_id, period, feature, count, updated_at)
             VALUES (:id, :period, :feature, 1, :now)
             ON DUPLICATE KEY UPDATE count = count + 1, updated_at = :now',
            [
                'id' => $license->getId(),
                'period' => $period,
                'feature' => $feature,
                'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );

        return $this->currentCount($license, $period, $feature);
    }
}
