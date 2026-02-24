<?php

namespace App\Repository;

use App\Entity\Observatory;
use App\Entity\Target;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Target>
 */
class TargetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Target::class);
    }

    /**
     * @return Target[]
     */
    public function findWishlistWithCoordinates(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.wishlist = true')
            ->andWhere('t.ra IS NOT NULL')
            ->andWhere('t.dec IS NOT NULL')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Returns stats for all targets in a single SQL query.
     * @return array<int, array{sessions: int, last_session_ts: int, lights: int, dofs: int, masters: int, exports: int}>
     */
    public function findAllStats(): array
    {
        // Sous-requêtes indépendantes pour éviter le produit cartésien
        // exposure × master × export sur la même session multiplie les lignes → SUM faux.
        $sql = '
            SELECT
                t.id                                    AS target_id,
                COALESCE(s_agg.sessions_count,  0)      AS sessions_count,
                COALESCE(s_agg.last_session_ts, 0)      AS last_session_ts,
                COALESCE(e_agg.lights_count,    0)      AS lights_count,
                COALESCE(e_agg.dofs_count,      0)      AS dofs_count,
                COALESCE(m_agg.masters_count,   0)      AS masters_count,
                COALESCE(ex_agg.exports_count,  0)      AS exports_count
            FROM target t

            LEFT JOIN (
                SELECT target_id,
                       COUNT(*)                                AS sessions_count,
                       MAX(EXTRACT(EPOCH FROM started_at))    AS last_session_ts
                FROM session
                GROUP BY target_id
            ) s_agg ON s_agg.target_id = t.id

            LEFT JOIN (
                SELECT s.target_id,
                       SUM(CASE WHEN e.type = \'LIGHT\' THEN 1 ELSE 0 END)  AS lights_count,
                       SUM(CASE WHEN e.type != \'LIGHT\' THEN 1 ELSE 0 END) AS dofs_count
                FROM session s
                JOIN exposure e ON e.session_id = s.id
                GROUP BY s.target_id
            ) e_agg ON e_agg.target_id = t.id

            LEFT JOIN (
                SELECT s.target_id, COUNT(*) AS masters_count
                FROM session s
                JOIN master m ON m.session_id = s.id
                GROUP BY s.target_id
            ) m_agg ON m_agg.target_id = t.id

            LEFT JOIN (
                SELECT s.target_id, COUNT(*) AS exports_count
                FROM session s
                JOIN export ex ON ex.session_id = s.id
                GROUP BY s.target_id
            ) ex_agg ON ex_agg.target_id = t.id
        ';

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql)->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['target_id']] = [
                'sessions'        => (int) $row['sessions_count'],
                'last_session_ts' => (int) $row['last_session_ts'],
                'lights'          => (int) $row['lights_count'],
                'dofs'            => (int) $row['dofs_count'],
                'masters'         => (int) $row['masters_count'],
                'exports'         => (int) $row['exports_count'],
            ];
        }

        return $result;
    }

    /**
     * Returns distinct authors per target in a single SQL query.
     * @return array<int, list<array{id: int, name: string, logo: string}>>
     */
    public function findAuthorsPerTarget(): array
    {
        $sql = '
            SELECT DISTINCT t.id AS target_id, a.id AS author_id, a.name, a.logo
            FROM target t
            JOIN session       s  ON s.target_id   = t.id
            JOIN session_author sa ON sa.session_id = s.id
            JOIN author        a  ON a.id           = sa.author_id
        ';

        $rows = $this->getEntityManager()->getConnection()->executeQuery($sql)->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $tid = (int) $row['target_id'];
            $aid = (int) $row['author_id'];
            if (!isset($result[$tid])) {
                $result[$tid] = [];
            }
            // Avoid duplicates (DISTINCT on SQL side, but defensive here too)
            $result[$tid][$aid] = ['id' => $aid, 'name' => $row['name'], 'logo' => $row['logo']];
        }

        // Re-index inner arrays
        foreach ($result as $tid => $authors) {
            $result[$tid] = array_values($authors);
        }

        return $result;
    }

}
