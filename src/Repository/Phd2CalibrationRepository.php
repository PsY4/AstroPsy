<?php

namespace App\Repository;

use App\Entity\Phd2Calibration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Phd2Calibration>
 */
class Phd2CalibrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Phd2Calibration::class);
    }

    public function findLatestForSession(int $sessionId): ?Phd2Calibration
    {
        return $this->createQueryBuilder('c')
            ->andWhere('IDENTITY(c.session) = :sid')
            ->setParameter('sid', $sessionId)
            ->orderBy('c.startedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
