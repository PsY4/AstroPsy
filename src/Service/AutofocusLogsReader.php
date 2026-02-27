<?php

namespace App\Service;

use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Service\AutoFocus\AutofocusReportParser;
use App\Service\AutoFocus\AutofocusUpsertHelper;
use Doctrine\ORM\EntityManagerInterface;

class AutofocusLogsReader
{
    private const REPORT_FILENAME = 'autofocus_report_Region0.json';

    public function __construct(
        private readonly AutofocusReportParser  $parser,
        private readonly AutofocusUpsertHelper  $upsertHelper,
        private readonly StoragePathResolver    $resolver,
    ) {}

    public function refreshAutofocusLogs(Session $session, EntityManagerInterface $em): int
    {
        $afPath = $this->resolver->resolve($session, SessionFolder::LOG_AF);
        if (!is_dir($afPath)) {
            return 0;
        }

        $count = 0;

        // Scan AutoFocus_* run folders
        $runDirs = @scandir($afPath);
        if ($runDirs === false) {
            return 0;
        }

        foreach ($runDirs as $runDir) {
            if (!str_starts_with($runDir, 'AutoFocus_') || !is_dir($afPath . '/' . $runDir)) {
                continue;
            }

            $runPath = $afPath . '/' . $runDir;
            $hasFinal = is_dir($runPath . '/final');

            // Scan attempt folders
            $items = @scandir($runPath);
            if ($items === false) {
                continue;
            }

            foreach ($items as $item) {
                if (!preg_match('/^attempt(\d+)$/i', $item, $m)) {
                    continue;
                }

                $attemptNumber = (int) $m[1];
                $attemptPath = $runPath . '/' . $item;
                $reportPath = $attemptPath . '/' . self::REPORT_FILENAME;

                if (!is_file($reportPath)) {
                    continue;
                }

                $content = @file_get_contents($reportPath);
                if ($content === false || $content === '') {
                    continue;
                }

                $data = $this->parser->parse($content);
                if (!$data) {
                    continue;
                }

                $relPath = $this->resolver->toRelativePath($attemptPath);
                $log = $this->upsertHelper->findOrCreate($session, $em, $relPath);

                $log->setRunFolder($runDir)
                    ->setAttemptNumber($attemptNumber)
                    ->setTimestamp($data['timestamp'])
                    ->setFilter($data['filter'])
                    ->setTemperature($data['temperature'])
                    ->setMethod($data['method'])
                    ->setFitting($data['fitting'])
                    ->setDurationSeconds($data['durationSeconds'])
                    ->setInitialPosition($data['initialPosition'])
                    ->setInitialHfr($data['initialHfr'])
                    ->setCalculatedPosition($data['calculatedPosition'])
                    ->setCalculatedHfr($data['calculatedHfr'])
                    ->setFinalHfr($data['finalHfr'])
                    ->setRSquared($data['rSquared'])
                    ->setMeasurePoints($data['measurePoints'])
                    ->setFittings($data['fittings'])
                    ->setFocuserName($data['focuserName'])
                    ->setStarDetectorName($data['starDetectorName'])
                    ->setBacklashModel($data['backlashModel'])
                    ->setBacklashIn($data['backlashIn'])
                    ->setBacklashOut($data['backlashOut'])
                    ->setSuccess($hasFinal);

                $count++;
            }
        }

        $em->flush();
        return $count;
    }

    /**
     * Count the number of AutoFocus_* run directories in a session.
     */
    public function countRunDirs(Session $session): int
    {
        $afPath = $this->resolver->resolve($session, SessionFolder::LOG_AF);
        if (!is_dir($afPath)) {
            return 0;
        }

        $count = 0;
        $items = @scandir($afPath);
        if ($items === false) {
            return 0;
        }

        foreach ($items as $item) {
            if (str_starts_with($item, 'AutoFocus_') && is_dir($afPath . '/' . $item)) {
                $count++;
            }
        }

        return $count;
    }
}
