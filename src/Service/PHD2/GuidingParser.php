<?php

namespace App\Service\PHD2;

/**
 * Parses a single PHD2 "Guiding Begins at" section.
 *
 * Input : array of lines for the section.
 * Output: associative array with all guiding time-series data and statistics.
 */
class GuidingParser
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
     * @return array<string, mixed>|null
     */
    public function parse(array $section): ?array
    {
        if (!$section) {
            return null;
        }

        // Timestamps
        $startedAt = null;
        if (preg_match('/^Guiding Begins at\s+(.+)$/', $section[0] ?? '', $m)) {
            $startedAt = $this->dateParser->parse($m[1]);
        }
        $endedAt = null;
        for ($i = 1; $i < count($section); $i++) {
            if (preg_match('/^Guiding Ends at\s+(.+)$/', trim($section[$i]), $mm)) {
                $endedAt = $this->dateParser->parse($mm[1]);
                if ($endedAt) break;
            }
        }

        // Equipment header fields (common)
        $eq = $this->equipmentParser->parseCommonFields($section);

        // Guiding-specific header fields
        $mount       = null;
        $orthoErrDeg = null;

        // Find CSV header line
        $csvHeaderIdx = null;
        for ($i = 1; $i < count($section); $i++) {
            $line = trim($section[$i]);
            if ($line === '' || str_starts_with($line, 'INFO:')) {
                continue;
            }

            // Mount line (guiding pattern)
            if ($mount === null && preg_match('/^Mount\s*=\s*(.*?),\s*connected, guiding enabled.*$/i', $line, $m)) {
                $mount = trim($m[1]);
                continue;
            }
            // Orthogonality error (guiding header)
            if ($orthoErrDeg === null && preg_match('/ortho\.err\.\s*=\s*([0-9.,+-]+)\s*deg/i', $line, $m)) {
                $orthoErrDeg = self::toFloat($m[1]);
                continue;
            }

            if (preg_match('/^Frame,Time,mount,dx,dy,RARawDistance,DECRawDistance,RAGuideDistance,DECGuideDistance,RADuration,RADirection,DECDuration,DECDirection,XStep,YStep,StarMass,SNR,ErrorCode$/', $line)) {
                $csvHeaderIdx = $i;
                break;
            }
        }

        if ($csvHeaderIdx === null) {
            return null; // no data
        }

        // --- CSV rows + dither state machine ---
        $points          = [];
        $drops           = [];
        $dithers         = [];
        $currentDitherId = null;
        $inDither        = false;
        $sumRa2          = 0.0;
        $sumDec2         = 0.0;
        $nRms            = 0;
        $pixelScale      = $eq['pixelScale'];

        for ($i = $csvHeaderIdx + 1; $i < count($section); $i++) {
            $raw  = $section[$i];
            $line = trim($raw);
            if ($line === '') {
                break;
            }
            if (preg_match('/^(Guiding (Begins|Ends) at|Calibration Begins at)\s+/', $line)) {
                break;
            }

            // INFO lines: dither state machine
            if (str_starts_with($line, 'INFO:')) {
                if (preg_match(
                    '/^INFO:\s*SET LOCK POSITION.*?new lock pos\s*=\s*([-\d.,]+)\s*,\s*([-\d.,]+)/i',
                    $line, $m
                )) {
                    $inDither = true;
                    $currentDitherId = ($currentDitherId === null) ? 0 : $currentDitherId + 1;
                    $dithers[$currentDitherId] = [
                        'id'         => $currentDitherId,
                        'startIndex' => count($points),
                        'endIndex'   => null,
                        'lockPos'    => [
                            'x' => (float) str_replace(',', '.', $m[1]),
                            'y' => (float) str_replace(',', '.', $m[2]),
                        ],
                    ];
                } elseif ($inDither && $currentDitherId !== null && preg_match(
                    '/^INFO:\s*DITHER by\s*([-\d.]+)\s*,\s*([-\d.]+)/i',
                    $line, $m
                )) {
                    $dithers[$currentDitherId]['offset'] = ['dx' => (float) $m[1], 'dy' => (float) $m[2]];
                } elseif (stripos($line, 'SETTLING STATE CHANGE, Settling complete') !== false) {
                    if ($inDither && $currentDitherId !== null) {
                        $dithers[$currentDitherId]['endIndex'] = max(0, count($points) - 1);
                    }
                    $inDither = false;
                }
                continue;
            }

            // CSV rows
            $cols = str_getcsv($line, ',', '"');
            if (count($cols) < 3) {
                continue;
            }

            // DROP rows
            if (strcasecmp($cols[2] ?? '', 'DROP') === 0) {
                $drops[] = [
                    'frame'     => isset($cols[0]) ? (int) $cols[0] : null,
                    't'         => isset($cols[1]) ? (float) str_replace(',', '.', $cols[1]) : null,
                    'starMass'  => isset($cols[3]) && is_numeric(str_replace(',', '.', $cols[3])) ? (float) str_replace(',', '.', $cols[3]) : null,
                    'snr'       => isset($cols[4]) && is_numeric(str_replace(',', '.', $cols[4])) ? (float) str_replace(',', '.', $cols[4]) : null,
                    'errorCode' => isset($cols[5]) && is_numeric($cols[5]) ? (int) $cols[5] : null,
                    'reason'    => $cols[6] ?? null,
                ];
                continue;
            }

            if (strcasecmp($cols[2] ?? '', 'Mount') !== 0) {
                continue;
            }

            $dx       = isset($cols[3]) ? (float) str_replace(',', '.', $cols[3]) : null;
            $dy       = isset($cols[4]) ? (float) str_replace(',', '.', $cols[4]) : null;
            $raDurMs  = isset($cols[9])  && $cols[9]  !== '' ? (int) $cols[9]  : 0;
            $decDurMs = isset($cols[11]) && $cols[11] !== '' ? (int) $cols[11] : 0;

            $points[] = [
                'frame'     => (int) $cols[0],
                't'         => (float) str_replace(',', '.', $cols[1]),
                'dx'        => $dx,
                'dy'        => $dy,
                'raRaw'     => isset($cols[5]) ? (float) str_replace(',', '.', $cols[5]) : null,
                'decRaw'    => isset($cols[6]) ? (float) str_replace(',', '.', $cols[6]) : null,
                'raGuide'   => isset($cols[7]) ? (float) str_replace(',', '.', $cols[7]) : null,
                'decGuide'  => isset($cols[8]) ? (float) str_replace(',', '.', $cols[8]) : null,
                'raDurMs'   => $raDurMs,
                'raDir'     => $cols[10] ?? '',
                'decDurMs'  => $decDurMs,
                'decDir'    => $cols[12] ?? '',
                'xStep'     => isset($cols[13]) ? (float) str_replace(',', '.', $cols[13]) : null,
                'yStep'     => isset($cols[14]) ? (float) str_replace(',', '.', $cols[14]) : null,
                'starMass'  => isset($cols[15]) ? (float) str_replace(',', '.', $cols[15]) : null,
                'snr'       => isset($cols[16]) ? (float) str_replace(',', '.', $cols[16]) : null,
                'errorCode' => isset($cols[17]) && $cols[17] !== '' ? (int) $cols[17] : 0,
                'isDither'  => $inDither,
                'ditherId'  => $inDither ? $currentDitherId : null,
            ];

            if ($pixelScale !== null && $dx !== null && $dy !== null) {
                $sumRa2  += ($dx * $pixelScale) ** 2;
                $sumDec2 += ($dy * $pixelScale) ** 2;
                $nRms++;
            }
        }

        // Close any unclosed dither window
        if ($inDither && $currentDitherId !== null && isset($dithers[$currentDitherId]) && $dithers[$currentDitherId]['endIndex'] === null) {
            $dithers[$currentDitherId]['endIndex'] = max(0, count($points) - 1);
        }

        $rmsRa  = $nRms > 0 ? sqrt($sumRa2  / $nRms) : null;
        $rmsDec = $nRms > 0 ? sqrt($sumDec2 / $nRms) : null;
        $rmsTot = ($rmsRa !== null && $rmsDec !== null) ? sqrt($rmsRa ** 2 + $rmsDec ** 2) : null;

        return [
            'startedAt'  => $startedAt,
            'endedAt'    => $endedAt,
            'mount'      => $mount,
            'pixelScale' => $pixelScale,
            'lockPos'    => $eq['lockPos'],
            'hfdPx'      => $eq['hfdPx'],
            'rmsRa'      => $rmsRa,
            'rmsDec'     => $rmsDec,
            'rmsTot'     => $rmsTot,
            'frameCount' => count($points),
            'dropCount'  => count($drops),
            'samples'    => [
                'points'  => $points,
                'drops'   => $drops,
                'dithers' => array_values($dithers),
            ],
            'headers'    => [
                'equipmentProfile'    => $eq['equipmentProfile'],
                'camera'              => $eq['camera'],
                'pixelScaleArcsecPx'  => $pixelScale,
                'binning'             => $eq['binning'],
                'focalLengthMm'       => $eq['focalLength'],
                'exposureMs'          => $eq['exposureMs'],
                'mount'               => $mount,
                'raGuideSpeedAsPerS'  => $eq['raGuideSpeed'],
                'decGuideSpeedAsPerS' => $eq['decGuideSpeed'],
                'raHr'                => $eq['raHr'],
                'decDeg'              => $eq['decDeg'],
                'hourAngleHr'         => $eq['hourAngleHr'],
                'pierSide'            => $eq['pierSide'],
                'altDeg'              => $eq['altDeg'],
                'azDeg'               => $eq['azDeg'],
                'hfdPx'               => $eq['hfdPx'],
                'orthoErrDeg'         => $orthoErrDeg,
            ],
        ];
    }
}
