<?php

namespace App\Service\AutoFocus;

class AutofocusReportParser
{
    /**
     * Parse the JSON content of an autofocus_report_Region0.json file.
     *
     * @return array|null Parsed data or null if invalid
     */
    public function parse(string $jsonContent): ?array
    {
        $data = @json_decode($jsonContent, true);
        if (!is_array($data)) {
            return null;
        }

        $result = [];

        // Timestamp
        $result['timestamp'] = null;
        if (!empty($data['Timestamp'])) {
            try {
                $result['timestamp'] = new \DateTimeImmutable($data['Timestamp']);
            } catch (\Exception) {
            }
        }

        // Basic fields
        $result['filter'] = $this->extractString($data, 'Filter');
        $result['temperature'] = $this->extractFloat($data, 'Temperature');
        $result['method'] = $this->extractString($data, 'Method');
        $result['fitting'] = $this->extractString($data, 'Fitting');

        // Duration — "HH:MM:SS.xxx" string → seconds
        $result['durationSeconds'] = $this->parseDuration($data['Duration'] ?? null);

        // Focus points
        $initial = $data['InitialFocusPoint'] ?? null;
        $result['initialPosition'] = is_array($initial) ? $this->extractFloat($initial, 'Position') : null;
        $result['initialHfr'] = is_array($initial) ? $this->extractFloat($initial, 'Value') : null;

        $calculated = $data['CalculatedFocusPoint'] ?? null;
        $result['calculatedPosition'] = is_array($calculated) ? $this->extractFloat($calculated, 'Position') : null;
        $result['calculatedHfr'] = is_array($calculated) ? $this->extractFloat($calculated, 'Value') : null;

        // Final HFR (from last focus point if present)
        $final = $data['LastFocusPoint'] ?? $data['FinalFocusPoint'] ?? null;
        $result['finalHfr'] = is_array($final) ? $this->extractFloat($final, 'Value') : null;

        // R²
        $result['rSquared'] = $this->extractFloat($data, 'RSquared');

        // MeasurePoints — array of {Position, Value, Error}
        $result['measurePoints'] = null;
        if (!empty($data['MeasurePoints']) && is_array($data['MeasurePoints'])) {
            $points = [];
            foreach ($data['MeasurePoints'] as $pt) {
                if (!is_array($pt)) {
                    continue;
                }
                $points[] = [
                    'position' => $this->extractFloat($pt, 'Position'),
                    'value'    => $this->extractFloat($pt, 'Value'),
                    'error'    => $this->extractFloat($pt, 'Error'),
                ];
            }
            $result['measurePoints'] = $points ?: null;
        }

        // Fittings (curve equations)
        $result['fittings'] = null;
        if (!empty($data['Fittings']) && is_array($data['Fittings'])) {
            $result['fittings'] = $data['Fittings'];
        }

        // Focuser info
        $result['focuserName'] = $this->extractString($data, 'FocuserName')
            ?? $this->extractString($data, 'Focuser');
        $result['starDetectorName'] = $this->extractString($data, 'StarDetectorName')
            ?? $this->extractString($data, 'StarDetector');

        // Backlash
        $backlash = $data['BacklashCompensation'] ?? $data['Backlash'] ?? null;
        if (is_array($backlash)) {
            $result['backlashModel'] = $this->extractString($backlash, 'Model')
                ?? $this->extractString($backlash, 'BacklashCompensationModel');
            $result['backlashIn'] = $this->extractInt($backlash, 'BacklashIN')
                ?? $this->extractInt($backlash, 'BacklashIn');
            $result['backlashOut'] = $this->extractInt($backlash, 'BacklashOUT')
                ?? $this->extractInt($backlash, 'BacklashOut');
        } else {
            $result['backlashModel'] = null;
            $result['backlashIn'] = null;
            $result['backlashOut'] = null;
        }

        return $result;
    }

    private function extractString(array $data, string $key): ?string
    {
        $val = $data[$key] ?? null;
        return is_string($val) && $val !== '' ? $val : null;
    }

    private function extractFloat(array $data, string $key): ?float
    {
        $val = $data[$key] ?? null;
        return is_numeric($val) ? (float) $val : null;
    }

    private function extractInt(array $data, string $key): ?int
    {
        $val = $data[$key] ?? null;
        return is_numeric($val) ? (int) $val : null;
    }

    private function parseDuration(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        if (is_string($raw) && preg_match('/^(\d+):(\d+):(\d+)/', $raw, $m)) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }

        return null;
    }
}
