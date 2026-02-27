<?php

namespace App\Service;

use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Service\WBPP\WbppLogParser;
use App\Service\WBPP\WbppUpsertHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

class WBPPLogsReader
{
    public function __construct(
        private readonly WbppLogParser       $parser,
        private readonly WbppUpsertHelper    $upsertHelper,
        private readonly StoragePathResolver $resolver,
    ) {}

    public function refreshWBPPLogs(Session $session, EntityManagerInterface $em): int
    {
        $wbppPath = $this->resolver->resolve($session, SessionFolder::LOG_WBPP);
        if (!is_dir($wbppPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($wbppPath)->name('/\.(log)$/i');
        $count = 0;

        foreach ($finder as $file) {
            $absPath = $file->getRealPath();
            if (!$absPath) {
                continue;
            }
            $relPath = $this->resolver->toRelativePath($absPath);

            $content = @file_get_contents($absPath);
            if ($content === false || $content === '') {
                continue;
            }

            $lines = preg_split('/\R/u', $content) ?: [];
            if (!$lines) {
                continue;
            }

            $data = $this->parser->parse($lines);
            if (!$data) {
                continue;
            }

            $log = $this->upsertHelper->findOrCreate($session, $em, $relPath);

            $log->setSourceSha1(sha1($content))
                ->setPiVersion($data['piVersion'])
                ->setWbppVersion($data['wbppVersion'])
                ->setStartedAt($data['startedAt'])
                ->setDurationSeconds($data['durationSeconds'])
                ->setCalibrationSummary($data['calibrationSummary'])
                ->setFilterGroups($data['filterGroups'])
                ->setFrames($data['frames'])
                ->setIntegrationResults($data['integrationResults']);

            $count++;
        }

        $em->flush();
        return $count;
    }
}
