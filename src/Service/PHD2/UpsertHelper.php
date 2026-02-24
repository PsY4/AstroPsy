<?php

namespace App\Service\PHD2;

use App\Entity\Phd2Calibration;
use App\Entity\Phd2Guiding;
use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds or creates Phd2Calibration / Phd2Guiding entities (upsert pattern).
 */
class UpsertHelper
{
    public function findOrCreateCalibration(
        Session $session,
        EntityManagerInterface $em,
        string $filePath,
        int $sectionIndex,
        ?\DateTimeImmutable $startedAt
    ): Phd2Calibration {
        $repo = $em->getRepository(Phd2Calibration::class);

        $cal = null;
        if ($startedAt) {
            $cal = $repo->findOneBy(['session' => $session, 'startedAt' => $startedAt]);
        }
        if (!$cal) {
            $cal = $repo->findOneBy(['session' => $session, 'sourcePath' => $filePath, 'sectionIndex' => $sectionIndex]);
        }
        if (!$cal) {
            $cal = new Phd2Calibration();
            $cal->setSession($session)->setSourcePath($filePath)->setSectionIndex($sectionIndex);
            if ($startedAt) {
                $cal->setStartedAt($startedAt);
            }
            $em->persist($cal);
        } else {
            if ($startedAt && $cal->getStartedAt() === null) {
                $cal->setStartedAt($startedAt);
            }
            $cal->setSectionIndex($sectionIndex);
        }

        return $cal;
    }

    public function findOrCreateGuiding(
        Session $session,
        EntityManagerInterface $em,
        string $filePath,
        int $sectionIndex,
        ?\DateTimeImmutable $startedAt
    ): Phd2Guiding {
        $repo = $em->getRepository(Phd2Guiding::class);

        $g = null;
        if ($startedAt) {
            $g = $repo->findOneBy(['session' => $session, 'startedAt' => $startedAt]);
        }
        if (!$g) {
            $g = $repo->findOneBy(['session' => $session, 'sourcePath' => $filePath, 'sectionIndex' => $sectionIndex]);
        }
        if (!$g) {
            $g = new Phd2Guiding();
            $g->setSession($session)->setSourcePath($filePath)->setSectionIndex($sectionIndex);
            if ($startedAt) {
                $g->setStartedAt($startedAt);
            }
            $em->persist($g);
        } else {
            if ($startedAt && $g->getStartedAt() === null) {
                $g->setStartedAt($startedAt);
            }
            $g->setSectionIndex($sectionIndex);
        }

        return $g;
    }
}
