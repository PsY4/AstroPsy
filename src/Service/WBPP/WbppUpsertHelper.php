<?php

namespace App\Service\WBPP;

use App\Entity\Session;
use App\Entity\WbppLog;
use Doctrine\ORM\EntityManagerInterface;

class WbppUpsertHelper
{
    public function findOrCreate(
        Session $session,
        EntityManagerInterface $em,
        string $sourcePath,
    ): WbppLog {
        $repo = $em->getRepository(WbppLog::class);

        $log = $repo->findOneBy(['session' => $session, 'sourcePath' => $sourcePath]);

        if (!$log) {
            $log = new WbppLog();
            $log->setSession($session)->setSourcePath($sourcePath);
            $em->persist($log);
        }

        $log->setUpdatedAt(new \DateTimeImmutable());

        return $log;
    }
}
