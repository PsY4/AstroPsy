<?php

namespace App\Service;

use App\Entity\Target;
use App\Entity\TargetGoal;
use Doctrine\ORM\EntityManagerInterface;

class ProgressTrackingService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    /**
     * Accumulated LIGHT seconds for all targets, grouped by target and filter.
     *
     * @return array<int, array<string, float>> targetId => [filterName => totalSeconds]
     */
    public function getAccumulatedAll(): array
    {
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(s.target) as targetId, e.filterName, SUM(e.exposure_s) as totalSeconds
             FROM App\Entity\Exposure e
             JOIN e.session s
             WHERE e.type = :type AND e.filterName IS NOT NULL
             GROUP BY s.target, e.filterName'
        )->setParameter('type', 'LIGHT')->getResult();

        $accumulated = [];
        foreach ($rows as $row) {
            $accumulated[(int) $row['targetId']][$row['filterName']] = (float) $row['totalSeconds'];
        }

        return $accumulated;
    }

    /**
     * Accumulated LIGHT seconds for a single target, respecting excludeFromProgress.
     * Returns raw filter names (caller is responsible for normalization).
     *
     * @return array<string, float> filterName => totalSeconds
     */
    public function getAccumulatedForTarget(Target $target): array
    {
        $rows = $this->em->createQuery(
            'SELECT e.filterName, SUM(e.exposure_s) as totalSeconds
             FROM App\Entity\Exposure e
             JOIN e.session s
             WHERE s.target = :target AND e.type = :type
               AND s.excludeFromProgress = false
             GROUP BY e.filterName ORDER BY e.filterName'
        )->setParameters(['target' => $target, 'type' => 'LIGHT'])->getResult();

        $accumulated = [];
        foreach ($rows as $row) {
            $accumulated[$row['filterName'] ?? 'nofilter'] = (float) $row['totalSeconds'];
        }

        return $accumulated;
    }

    /**
     * Goals for all targets, grouped by target and filter.
     *
     * @return array<int, array<string, int>> targetId => [filterName => goalSeconds]
     */
    public function getGoalsAll(): array
    {
        $goals = [];
        foreach ($this->em->getRepository(TargetGoal::class)->findAll() as $g) {
            $goals[$g->getTarget()->getId()][$g->getFilterName()] = $g->getGoalSeconds();
        }

        return $goals;
    }

    /**
     * @param array<string, int>   $goals       filterName => goalSeconds
     * @param array<string, float> $accumulated filterName => totalSeconds
     */
    public function computeDeficitHours(array $goals, array $accumulated): float
    {
        $deficit = 0.0;
        foreach ($goals as $filter => $goalSec) {
            $deficit += max(0, $goalSec - ($accumulated[$filter] ?? 0)) / 3600;
        }

        return round($deficit, 2);
    }
}
