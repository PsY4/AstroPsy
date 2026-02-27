<?php

namespace App\Service\WBPP;

/**
 * Parses a WBPP (WeightedBatchPreProcessing) log file from PixInsight.
 *
 * Every line is prefixed with "[YYYY-MM-DD HH:MM:SS] " — we strip that first.
 *
 * Log structure:
 *  - Header with PixInsight + WBPP versions
 *  - Calibration sections (Bias/Dark integration)
 *  - Frame descriptor blocks: full path on a line, "---" separator, metric lines (FWHM, Eccentricity, etc.), "---"
 *  - Rejection lines: "[Frames rejection] filename - score > threshold | accepted/rejected"
 *  - Integration per filter
 *  - Duration: "* WeightedBatchPreprocessing: MM:SS.ss"
 */
class WbppLogParser
{
    /**
     * @param string[] $lines Raw lines from the log file
     */
    public function parse(array $lines): ?array
    {
        if (count($lines) < 10) {
            return null;
        }

        // Strip timestamp prefix from all lines
        $stripped = [];
        foreach ($lines as $line) {
            $stripped[] = preg_replace('/^\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\]\s?/', '', $line);
        }

        $result = [
            'piVersion' => null,
            'wbppVersion' => null,
            'startedAt' => null,
            'durationSeconds' => null,
            'calibrationSummary' => [],
            'filterGroups' => [],
            'frames' => [],
            'integrationResults' => [],
        ];

        $this->parseStartedAt($lines, $result);
        $this->parseHeader($stripped, $result);
        $this->parseFrameDescriptors($stripped, $result);
        $this->parseFrameRejections($stripped, $result);
        $this->parseCalibrationSections($stripped, $result);
        $this->parseIntegrationResults($stripped, $result);
        $this->parseDuration($stripped, $result);
        $this->buildFilterGroups($result);

        if (empty($result['frames'])) {
            return null;
        }

        return $result;
    }

    /**
     * Extract startedAt from the original timestamp-prefixed lines.
     */
    private function parseStartedAt(array $rawLines, array &$result): void
    {
        foreach ($rawLines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                try {
                    $result['startedAt'] = new \DateTimeImmutable($m[1]);
                } catch (\Exception) {
                }
                return;
            }
        }
    }

    private function parseHeader(array $lines, array &$result): void
    {
        foreach ($lines as $i => $line) {
            if (preg_match('/PixInsight\s+Core\s+([\d.]+)/i', $line, $m)) {
                $result['piVersion'] = $m[1];
            }
            if (preg_match('/Weighted\s+Batch\s+Preprocessing\s+Script\s+([\d.]+)/i', $line, $m)) {
                $result['wbppVersion'] = $m[1];
            }
            if ($i > 20) {
                break;
            }
        }
    }

    private function parseFrameDescriptors(array $lines, array &$result): void
    {
        $count = count($lines);
        $currentFilter = 'Unknown';
        $i = 0;

        while ($i < $count) {
            $line = trim($lines[$i]);

            // Track current filter from "Filter   : H" lines
            if (preg_match('/^Filter\s*:\s*(\S+)/', $line, $fm)) {
                $filter = $fm[1];
                if ($filter !== 'NoFilter') {
                    $currentFilter = $filter;
                }
                $i++;
                continue;
            }

            // Frame descriptor: line is a full path ending in .xisf (or .fits/.fit)
            // Can be Windows paths like Y:/... or Unix paths like /...
            if (preg_match('/^([A-Z]:[\\/]|\/|\.\/).+\.(?:xisf|fits?)$/i', $line)) {
                $file = basename($line);
                $metrics = [];

                // Scan next lines for separator + metrics
                $j = $i + 1;
                $inBlock = false;
                while ($j < $count && $j < $i + 25) {
                    $mLine = trim($lines[$j]);

                    // Separator line (dashes)
                    if (preg_match('/^-{5,}$/', $mLine)) {
                        if ($inBlock) {
                            $j++;
                            break; // End of metric block
                        }
                        $inBlock = true;
                        $j++;
                        continue;
                    }

                    if ($mLine === '') {
                        if ($inBlock) {
                            break;
                        }
                        $j++;
                        continue;
                    }

                    if ($inBlock && preg_match('/^([\w\s()]+?)\s*:\s*(.+)$/', $mLine, $kv)) {
                        $key = strtolower(trim($kv[1]));
                        $val = trim($kv[2]);
                        $metrics[$key] = $val;
                    }

                    $j++;
                }

                if (!empty($metrics)) {
                    // Extract filter from path (FILTER-H_mono) or from filename
                    $filter = $this->extractFilterFromPath($line) ?? $this->guessFilterFromFilename($file) ?? $currentFilter;

                    $frame = [
                        'file' => $file,
                        'filter' => $filter,
                        'fwhm' => $this->extractFloat($metrics, ['fwhm']),
                        'eccentricity' => $this->extractFloat($metrics, ['eccentricity']),
                        'stars' => $this->extractInt($metrics, ['number of stars']),
                        'psfSignalWeight' => $this->extractFloat($metrics, ['psf signal weight']),
                        'psfSnr' => $this->extractFloat($metrics, ['psf snr']),
                        'snr' => $this->extractFloat($metrics, ['snr']),
                        'median' => $this->extractFloat($metrics, ['median (adu)', 'median']),
                        'mad' => $this->extractFloat($metrics, ['mad (adu)', 'mad']),
                        'mstar' => $this->extractFloat($metrics, ['mstar (adu)', 'mstar']),
                        'accepted' => true,
                        'weight' => $this->extractFloat($metrics, ['weight']),
                    ];

                    $result['frames'][] = $frame;
                }

                $i = $j;
                continue;
            }

            $i++;
        }
    }

    private function parseFrameRejections(array $lines, array &$result): void
    {
        if (empty($result['frames'])) {
            return;
        }

        // Build lookup by filename (basename without _c suffix variations)
        $frameIdx = [];
        foreach ($result['frames'] as $k => $f) {
            $frameIdx[strtolower($f['file'])] = $k;
        }

        foreach ($lines as $line) {
            // [Frames rejection] filename.xisf - score > threshold | accepted/rejected
            if (preg_match('/\[Frames rejection\]\s+(\S+\.(?:xisf|fits?))\s+.*\|\s*(accepted|rejected)/i', $line, $m)) {
                $file = strtolower($m[1]);
                $status = strtolower($m[2]);
                if (isset($frameIdx[$file])) {
                    $result['frames'][$frameIdx[$file]]['accepted'] = ($status === 'accepted');
                }
            }
        }
    }

    private function parseCalibrationSections(array $lines, array &$result): void
    {
        $calibrations = [];

        foreach ($lines as $i => $line) {
            // "* Begin integration of Bias frames" or "* Begin integration of Dark frames"
            if (preg_match('/\*\s*Begin\s+integration\s+of\s+(Bias|Dark|Flat)\s+frames?/i', $line, $m)) {
                $type = ucfirst(strtolower($m[1]));
                $count = null;

                // Look at next few lines for "Group of N ... frames (N active)"
                for ($j = $i + 1; $j < min($i + 5, count($lines)); $j++) {
                    if (preg_match('/Group\s+of\s+(\d+)\s+/', $lines[$j], $gm)) {
                        $count = (int) $gm[1];
                        break;
                    }
                }

                $calibrations[] = [
                    'type' => $type,
                    'count' => $count,
                ];
            }
        }

        $result['calibrationSummary'] = $calibrations;
    }

    private function parseIntegrationResults(array $lines, array &$result): void
    {
        $integrations = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // "* Begin integration of Light frames"
            if (preg_match('/\*\s*Begin\s+integration\s+of\s+Light\s+frames?/i', $line)) {
                $entry = [
                    'imageCount' => null,
                    'filter' => null,
                    'snr' => null,
                    'rejectionMethod' => null,
                ];

                for ($j = $i + 1; $j < min($i + 10, $count); $j++) {
                    if (preg_match('/Group\s+of\s+(\d+)\s+Light\s+frames?\s+\((\d+)\s+active\)/i', $lines[$j], $gm)) {
                        $entry['imageCount'] = (int) $gm[2]; // active count
                    }
                    if (preg_match('/Filter\s*:\s*(\S+)/i', $lines[$j], $fm)) {
                        $entry['filter'] = $fm[1];
                    }
                }

                // Scan further for rejection method and SNR
                for ($j = $i; $j < min($i + 500, $count); $j++) {
                    $sLine = $lines[$j];
                    if (preg_match('/Rejection method.*?:\s*(.*)/i', $sLine, $rm)) {
                        $entry['rejectionMethod'] = trim($rm[1]);
                    }
                    if (preg_match('/(Winsorized\s+Sigma|Linear\s+Fit|Sigma\s+Clipping|Percentile|ESD)/i', $sLine, $rm) && $entry['rejectionMethod'] === null) {
                        $entry['rejectionMethod'] = trim($rm[1]);
                    }
                }

                $integrations[] = $entry;
            }
        }

        $result['integrationResults'] = $integrations;
    }

    private function parseDuration(array $lines, array &$result): void
    {
        $lastLines = array_slice($lines, -100);
        foreach (array_reverse($lastLines) as $line) {
            if (preg_match('/\*?\s*WeightedBatchPreprocessing[:\s]+(\d+):(\d+(?:\.\d+)?)/i', $line, $m)) {
                $result['durationSeconds'] = (int) $m[1] * 60 + (int) round((float) $m[2]);
                return;
            }
        }
    }

    private function buildFilterGroups(array &$result): void
    {
        if (empty($result['frames'])) {
            return;
        }

        $groups = [];
        foreach ($result['frames'] as $frame) {
            $filter = $frame['filter'] ?? 'Unknown';
            if (!isset($groups[$filter])) {
                $groups[$filter] = [
                    'filter' => $filter,
                    'framesTotal' => 0,
                    'framesActive' => 0,
                    'framesRejected' => 0,
                    'fwhmSum' => 0.0,
                    'eccSum' => 0.0,
                    'snrSum' => 0.0,
                    'fwhmCount' => 0,
                    'eccCount' => 0,
                    'snrCount' => 0,
                ];
            }

            $groups[$filter]['framesTotal']++;
            if ($frame['accepted']) {
                $groups[$filter]['framesActive']++;
            } else {
                $groups[$filter]['framesRejected']++;
            }

            if ($frame['fwhm'] !== null) {
                $groups[$filter]['fwhmSum'] += $frame['fwhm'];
                $groups[$filter]['fwhmCount']++;
            }
            if ($frame['eccentricity'] !== null) {
                $groups[$filter]['eccSum'] += $frame['eccentricity'];
                $groups[$filter]['eccCount']++;
            }
            if ($frame['snr'] !== null) {
                $groups[$filter]['snrSum'] += $frame['snr'];
                $groups[$filter]['snrCount']++;
            }
        }

        $filterGroups = [];
        foreach ($groups as $g) {
            $filterGroups[] = [
                'filter' => $g['filter'],
                'framesTotal' => $g['framesTotal'],
                'framesActive' => $g['framesActive'],
                'framesRejected' => $g['framesRejected'],
                'avgFwhm' => $g['fwhmCount'] > 0 ? round($g['fwhmSum'] / $g['fwhmCount'], 3) : null,
                'avgEccentricity' => $g['eccCount'] > 0 ? round($g['eccSum'] / $g['eccCount'], 3) : null,
                'avgSnr' => $g['snrCount'] > 0 ? round($g['snrSum'] / $g['snrCount'], 3) : null,
            ];
        }

        $result['filterGroups'] = $filterGroups;
    }

    private function extractFilterFromPath(string $path): ?string
    {
        // Match FILTER-X pattern in path: "FILTER-H_mono", "FILTER-S_"
        if (preg_match('/FILTER[_-]([A-Za-z]+)/i', $path, $m)) {
            return strtoupper($m[1]);
        }
        return null;
    }

    private function guessFilterFromFilename(string $filename): ?string
    {
        // Common patterns: "_H_300.00s_", "_S_120s_", "_OIII_"
        if (preg_match('/_([LRGBHSO]|Ha|Sii|Oiii)_/i', $filename, $m)) {
            $map = [
                'ha' => 'H', 'sii' => 'S', 'oiii' => 'O',
                'l' => 'L', 'r' => 'R', 'g' => 'G', 'b' => 'B',
                'h' => 'H', 's' => 'S', 'o' => 'O',
            ];
            return $map[strtolower($m[1])] ?? strtoupper($m[1]);
        }
        return null;
    }

    private function extractFloat(array $metrics, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($metrics[$key])) {
                // Strip units like "[px]", "(ADU)" etc.
                $val = preg_replace('/\s*[\[\(].*[\]\)]/', '', $metrics[$key]);
                $val = trim($val);
                if (is_numeric($val)) {
                    return (float) $val;
                }
            }
        }
        return null;
    }

    private function extractInt(array $metrics, array $keys): ?int
    {
        foreach ($keys as $key) {
            if (isset($metrics[$key])) {
                $val = trim(preg_replace('/\s*[\[\(].*[\]\)]/', '', $metrics[$key]));
                if (is_numeric($val)) {
                    return (int) $val;
                }
            }
        }
        return null;
    }
}
