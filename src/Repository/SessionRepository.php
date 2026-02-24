<?php

namespace App\Repository;

use App\Entity\Observatory;
use App\Entity\Session;
use App\Entity\Target;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Session>
 */
class SessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Session::class);
    }

    /**
     * Returns lightweight session data for the calendar view (1 query, no lazy-loading).
     * @return array<int, array{id: int, startedAt: \DateTimeInterface, target_name: string, target_id: int, target_type: string}>
     */
    public function findForCalendar(): array
    {
        return $this->getEntityManager()->createQuery(
            'SELECT s.id, s.startedAt, t.name AS target_name, t.id AS target_id, t.type AS target_type
             FROM App\Entity\Session s
             JOIN s.target t
             WHERE s.startedAt IS NOT NULL
             ORDER BY s.startedAt ASC'
        )->getResult();
    }

}
