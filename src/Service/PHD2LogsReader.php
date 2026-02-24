<?php

namespace App\Service;

use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Service\PHD2\CalibrationParser;
use App\Service\PHD2\GuidingParser;
use App\Service\PHD2\UpsertHelper;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Entry-point for scanning PHD2 log files and persisting calibration/guiding data.
 * Parsing logic is delegated to sub-services in App\Service\PHD2\.
 */
class PHD2LogsReader
{
    public function __construct(
        private readonly CalibrationParser    $calibrationParser,
        private readonly GuidingParser        $guidingParser,
        private readonly UpsertHelper         $upsertHelper,
        private readonly StoragePathResolver  $resolver,
    ) {}

    public function refreshPHD2Logs(Session $session, EntityManagerInterface $em): int
    {
        $phd2Path = $this->resolver->resolve($session, SessionFolder::LOG_PHD2);
        if (!is_dir($phd2Path)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($phd2Path)->name('/\.(txt)$/i');
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

            $this->processCalibrationSections($session, $em, $lines, $relPath, $content);
            $this->processGuidingSections($session, $em, $lines, $relPath, $content);
            $count++;
        }

        $em->flush();
        return $count;
    }

    private function processCalibrationSections(
        Session $session, EntityManagerInterface $em,
        array $lines, string $filePath, string $content
    ): void {
        $ranges = $this->findSectionRanges($lines, 'Calibration Begins at');
        if (!$ranges) {
            return;
        }

        foreach ($ranges as $sectionIndex => [$s, $e]) {
            $section = array_slice($lines, $s, $e - $s);
            $data    = $this->calibrationParser->parse($section);
            if (!$data) {
                continue;
            }

            $cal = $this->upsertHelper->findOrCreateCalibration(
                $session, $em, $filePath, $sectionIndex, $data['startedAt']
            );

            $cal->setSession($session)
                ->setSourcePath($filePath)
                ->setSourceSha1(sha1($content))
                ->setSectionIndex($sectionIndex)
                ->setHeaders($data['headers'])
                ->setMount($data['mount'])
                ->setPixelScaleArcsecPerPx($data['pixelScale'])
                ->setWestAngleDeg($data['westSummary']['angle'] ?? null)
                ->setWestRatePxPerSec($data['westSummary']['rate'] ?? null)
                ->setWestParity($data['westSummary']['parity'] ?? null)
                ->setNorthAngleDeg($data['northSummary']['angle'] ?? null)
                ->setNorthRatePxPerSec($data['northSummary']['rate'] ?? null)
                ->setNorthParity($data['northSummary']['parity'] ?? null)
                ->setOrthogonalityDeg($data['orthogonalityDeg'])
                ->setPointsWest($data['steps']['West'])
                ->setPointsEast($data['steps']['East'])
                ->setPointsNorth($data['steps']['North'])
                ->setPointsSouth($data['steps']['South']);

            if ($data['startedAt']) {
                $cal->setStartedAt($data['startedAt']);
            }
            if ($data['lockPos']) {
                $cal->setLockPosition(['x' => (float) $data['lockPos'][0], 'y' => (float) $data['lockPos'][1]]);
            }
        }
    }

    private function processGuidingSections(
        Session $session, EntityManagerInterface $em,
        array $lines, string $filePath, string $content
    ): void {
        $ranges = $this->findSectionRanges($lines, 'Guiding Begins at');
        if (!$ranges) {
            return;
        }

        foreach ($ranges as $sectionIndex => [$s, $e]) {
            $section = array_slice($lines, $s, $e - $s);
            $data    = $this->guidingParser->parse($section);
            if (!$data) {
                continue;
            }

            $g = $this->upsertHelper->findOrCreateGuiding(
                $session, $em, $filePath, $sectionIndex, $data['startedAt']
            );

            $g->setHeaders($data['headers'])
              ->setMount($data['mount'])
              ->setPixelScaleArcsecPerPx($data['pixelScale'])
              ->setExposureMs($data['headers']['exposureMs'] ?? null)
              ->setFrameCount($data['frameCount'])
              ->setDropCount($data['dropCount'])
              ->setRmsRaArcsec($data['rmsRa'])
              ->setRmsDecArcsec($data['rmsDec'])
              ->setTotalRmsArcsec($data['rmsTot'])
              ->setSamples($data['samples']);

            if ($data['endedAt']) {
                $g->setEndedAt($data['endedAt']);
            }
            if ($data['lockPos']) {
                $g->setLockPosition(['x' => (float) $data['lockPos'][0], 'y' => (float) $data['lockPos'][1]]);
            }
            if ($data['hfdPx'] !== null) {
                $g->setHfdPx($data['hfdPx']);
            }
        }
    }

    /**
     * Returns [[startIdx, endIdx], …] for every section starting with $marker.
     * End of each section = start of next section (any type) or EOF.
     *
     * @return list<array{int,int}>
     */
    private function findSectionRanges(array $lines, string $marker): array
    {
        $total  = count($lines);
        $starts = [];
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, $marker)) {
                $starts[] = $i;
            }
        }
        if (!$starts) {
            return [];
        }

        $ranges = [];
        foreach ($starts as $startIdx) {
            $endIdx = $total;
            for ($j = $startIdx + 1; $j < $total; $j++) {
                if (preg_match('/^(Calibration Begins at|Guiding Begins at)\s+/', $lines[$j])) {
                    $endIdx = $j;
                    break;
                }
            }
            $ranges[] = [$startIdx, $endIdx];
        }

        return $ranges;
    }
}
