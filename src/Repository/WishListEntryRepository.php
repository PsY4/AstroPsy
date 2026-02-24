<?php

namespace App\Repository;

use App\Entity\WishListEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WishListEntry>
 */
class WishListEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishListEntry::class);
    }

    /**
     * @return array<int, array> targetId => framing data
     */
    public function getFramingMap(?int $setupId = null): array
    {
        $entries = $this->findAll();
        $map = [];
        foreach ($entries as $wle) {
            if ($setupId !== null && $wle->getSetup()?->getId() !== $setupId) {
                continue;
            }
            $map[$wle->getTarget()->getId()] = [
                'ra'              => $wle->getRaFraming(),
                'dec'             => $wle->getDecFraming(),
                'rotation'        => $wle->getRotationAngle() ?? 0.0,
                'setupId'         => $wle->getSetup()?->getId(),
                'filtersSelected' => $wle->getFiltersSelected(),
            ];
        }

        return $map;
    }

    /**
     * @return int[]
     */
    public function getSetupIdsWithFraming(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn(WishListEntry $wle) => $wle->getSetup()?->getId(),
            array_filter($this->findAll(), fn(WishListEntry $wle) => $wle->getSetup() !== null)
        ))));
    }
}
