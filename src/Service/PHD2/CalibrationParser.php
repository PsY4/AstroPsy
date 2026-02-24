<?php

namespace App\Service\PHD2;

/**
 * Parses a single PHD2 "Calibration Begins at" section.
 *
 * Input : array of lines for the section.
 * Output: associative array with all calibration data
 *         (startedAt, mount, steps, summaries, headers, …).
 */
class CalibrationParser
{
    public function __construct(
        private readonly DateParser      $dateParser,
        private readonly EquipmentParser $equipmentParser,
    ) {}

    private static function toFloat(?string $s): ?float
    {
        if ($s === null || $s === '') {
            return null;
        }
        $s = str_replace(',', '.', trim($s));
        return is_numeric($s) ? (float) $s : null;
    }

    /**
     * @param  string[] $section
     * @return array<string, mixed>|null  null if section is empty or unparseable
     */
    public function parse(array $section): ?array
    {
        if (!$section) {
            return null;
        }

        // Timestamp
        $startedAt = null;
        if (preg_match('/^Calibration Begins at\s+(.+)$/', $section[0] ?? '', $m)) {
            $startedAt = $this->dateParser->parse($m[1]);
        }

        // Equipment header fields (common)
        $eq = $this->equipmentParser->parseCommonFields($section);

        // Calibration-specific fields
        $mount            = null;
        $calibStepMs      = null;
        $calibDistancePx  = null;
        $assumeOrtho      = null;

        $steps = ['West' => [], 'East' => [], 'North' => [], 'South' => [], 'Backlash' => []];
        $westSummary  = ['angle' => null, 'rate' => null, 'parity' => null];
        $northSummary = ['angle' => null, 'rate' => null, 'parity' => null];
        $csvHeaderSeen = false;

        for ($i = 1; $i < count($section); $i++) {
            $line = trim($section[$i]);
            if ($line === '' || str_starts_with($line, 'INFO:')) {
                continue;
            }

            // Mount line (calibration-specific pattern)
            if ($mount === null && preg_match(
                '/^Mount\s*=\s*(.*?),\s*Calibration Step\s*=\s*([0-9]+)\s*ms,\s*Calibration Distance\s*=\s*([0-9.]+)\s*px,\s*Assume orthogonal axes\s*=\s*(yes|no)$/i',
                $line, $m
            )) {
                $mount           = trim($m[1]);
                $calibStepMs     = (int) $m[2];
                $calibDistancePx = (float) $m[3];
                $assumeOrtho     = strtolower($m[4]) === 'yes';
                continue;
            }

            // CSV header
            if (!$csvHeaderSeen && preg_match('/^Direction,Step,dx,dy,x,y,Dist$/', $line)) {
                $csvHeaderSeen = true;
                continue;
            }

            if ($csvHeaderSeen) {
                // Calibration complete summaries (West / North)
                if (preg_match(
                    '/^(West|North)\s+calibration complete\. Angle\s*=\s*([\-0-9.]+)\s*deg,\s*Rate\s*=\s*([0-9.]+)\s*px\/sec,\s*Parity\s*=\s*(\w+)/i',
                    $line, $m2
                )) {
                    $summary = ['angle' => (float) $m2[2], 'rate' => (float) $m2[3], 'parity' => trim($m2[4])];
                    if (strtolower($m2[1]) === 'west') {
                        $westSummary  = $summary;
                    } else {
                        $northSummary = $summary;
                    }
                    continue;
                }

                // End marker
                if (preg_match('/^Calibration complete, mount\s*=/', $line)) {
                    break;
                }

                // CSV data rows: Direction,Step,dx,dy,x,y,Dist
                if (preg_match(
                    '/^(West|East|North|South|Backlash),\s*([0-9]+),\s*([\-0-9.]+),\s*([\-0-9.]+),\s*([\-0-9.]+),\s*([\-0-9.]+),\s*([\-0-9.]+)/',
                    $line, $mm
                )) {
                    $dir = ucfirst(strtolower($mm[1]));
                    $steps[$dir][] = [
                        'step' => (int) $mm[2],
                        'dx'   => (float) $mm[3],
                        'dy'   => (float) $mm[4],
                        'x'    => (float) $mm[5],
                        'y'    => (float) $mm[6],
                        'dist' => (float) $mm[7],
                    ];
                }
            }
        }

        // Orthogonality from west/north angles
        $orthogonalityDeg = null;
        if ($westSummary['angle'] !== null && $northSummary['angle'] !== null) {
            $diff = fmod((((float) $northSummary['angle'] - (float) $westSummary['angle']) + 360.0), 180.0);
            $orthogonalityDeg = abs($diff - 90.0);
        }

        $normSteps = static function (array $rows): array {
            return array_map(static fn ($r) => [
                'step' => isset($r['step']) ? (int)   $r['step'] : null,
                'dx'   => isset($r['dx'])   ? (float) $r['dx']   : null,
                'dy'   => isset($r['dy'])   ? (float) $r['dy']   : null,
                'x'    => isset($r['x'])    ? (float) $r['x']    : null,
                'y'    => isset($r['y'])    ? (float) $r['y']    : null,
                'dist' => isset($r['dist']) ? (float) $r['dist'] : null,
            ], $rows);
        };

        return [
            'startedAt'        => $startedAt,
            'mount'            => $mount,
            'calibStepMs'      => $calibStepMs,
            'calibDistancePx'  => $calibDistancePx,
            'assumeOrtho'      => $assumeOrtho,
            'pixelScale'       => $eq['pixelScale'],
            'lockPos'          => $eq['lockPos'],
            'starPos'          => $eq['starPos'],
            'hfdPx'            => $eq['hfdPx'],
            'westSummary'      => $westSummary,
            'northSummary'     => $northSummary,
            'steps'            => [
                'West'      => $normSteps($steps['West']),
                'East'      => $normSteps($steps['East']),
                'North'     => $normSteps($steps['North']),
                'South'     => $normSteps($steps['South']),
                'Backlash'  => $normSteps($steps['Backlash']),
            ],
            'orthogonalityDeg' => $orthogonalityDeg,
            'headers'          => [
                'equipmentProfile'    => $eq['equipmentProfile'],
                'camera'              => $eq['camera'],
                'pixelScaleArcsecPx'  => $eq['pixelScale'],
                'binning'             => $eq['binning'],
                'focalLengthMm'       => $eq['focalLength'],
                'exposureMs'          => $eq['exposureMs'],
                'mount'               => $mount,
                'calibrationStepMs'   => $calibStepMs,
                'calibrationDistPx'   => $calibDistancePx,
                'assumeOrtho'         => $assumeOrtho,
                'raGuideSpeedAsPerS'  => $eq['raGuideSpeed'],
                'decGuideSpeedAsPerS' => $eq['decGuideSpeed'],
                'raHr'                => $eq['raHr'],
                'decDeg'              => $eq['decDeg'],
                'hourAngleHr'         => $eq['hourAngleHr'],
                'pierSide'            => $eq['pierSide'],
                'altDeg'              => $eq['altDeg'],
                'azDeg'               => $eq['azDeg'],
                'hfdPx'               => $eq['hfdPx'],
                'west'                => $westSummary,
                'north'               => $northSummary,
            ],
        ];
    }
}
