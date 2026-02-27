<?php

namespace App\Service\AutoFocus;

use App\Entity\AutofocusLog;
use App\Entity\Session;
use Doctrine\ORM\EntityManagerInterface;

class AutofocusUpsertHelper
{
    public function findOrCreate(
        Session $session,
        EntityManagerInterface $em,
        string $sourcePath,
    ): AutofocusLog {
        $repo = $em->getRepository(AutofocusLog::class);

        $log = $repo->findOneBy(['session' => $session, 'sourcePath' => $sourcePath]);

        if (!$log) {
            $log = new AutofocusLog();
            $log->setSession($session)->setSourcePath($sourcePath);
            $em->persist($log);
        }

        $log->setUpdatedAt(new \DateTimeImmutable());

        return $log;
    }
}
