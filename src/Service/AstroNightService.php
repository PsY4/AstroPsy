<?php

namespace App\Service;

class AstroNightService
{
    /**
     * Local Apparent Sidereal Time in degrees.
     */
    private function localSiderealTime(\DateTimeImmutable $t, float $lon): float
    {
        $jd  = $t->getTimestamp() / 86400.0 + 2440587.5;
        $T   = ($jd - 2451545.0) / 36525.0;
        $gst = 280.46061837 + 360.98564736629 * ($jd - 2451545.0) + 0.000387933 * $T * $T;

        return fmod($gst + $lon + 720.0, 360.0);
    }

    /**
     * Altitude of a celestial object (degrees) given its equatorial coords (degrees).
     */
    public function altitude(
        float $lat, float $lon,
        float $raDeg, float $decDeg,
        \DateTimeImmutable $t
    ): float {
        $lst  = $this->localSiderealTime($t, $lon);
        $ha   = deg2rad($lst - $raDeg);
        $sinA = sin(deg2rad($decDeg)) * sin(deg2rad($lat))
              + cos(deg2rad($decDeg)) * cos(deg2rad($lat)) * cos($ha);

        return rad2deg(asin(max(-1.0, min(1.0, $sinA))));
    }

    /**
     * Altitude of the Sun (degrees) — Jean Meeus simplified formula.
     */
    public function sunAltitude(float $lat, float $lon, \DateTimeImmutable $t): float
    {
        $jd   = $t->getTimestamp() / 86400.0 + 2440587.5;
        $n    = $jd - 2451545.0;
        $L    = fmod(280.460 + 0.9856474 * $n + 720.0, 360.0);
        $g    = deg2rad(fmod(357.528 + 0.9856003 * $n + 720.0, 360.0));

        $lambda = $L + 1.915 * sin($g) + 0.020 * sin(2.0 * $g);
        $eps    = 23.439 - 0.0000004 * $n;

        $lRad = deg2rad($lambda);
        $eRad = deg2rad($eps);

        $raDeg  = rad2deg(atan2(cos($eRad) * sin($lRad), cos($lRad)));
        $decDeg = rad2deg(asin(max(-1.0, min(1.0, sin($eRad) * sin($lRad)))));

        return $this->altitude($lat, $lon, $raDeg, $decDeg, $t);
    }

    /**
     * Moon position (RA/Dec degrees) — Jean Meeus simplified formula.
     */
    public function getMoonPosition(\DateTimeImmutable $t): array
    {
        $jd = $t->getTimestamp() / 86400.0 + 2440587.5;
        $n  = $jd - 2451545.0;

        $L = fmod(218.316 + 13.176396 * $n, 360.0);
        $M = fmod(134.963 + 13.064993 * $n, 360.0);
        $F = fmod(93.272  + 13.229350 * $n, 360.0);

        $lon = $L + 6.289 * sin(deg2rad($M));
        $lat = 5.128 * sin(deg2rad($F));

        $eps = 23.439 - 0.0000004 * $n;

        $lRad = deg2rad($lon);
        $bRad = deg2rad($lat);
        $eRad = deg2rad($eps);

        $raDeg  = rad2deg(atan2(
            cos($bRad) * sin($lRad) * cos($eRad) - sin($bRad) * sin($eRad),
            cos($bRad) * cos($lRad)
        ));
        $decDeg = rad2deg(asin(max(-1.0, min(1.0,
            sin($bRad) * cos($eRad) + cos($bRad) * sin($eRad) * sin($lRad)
        ))));

        return ['raDeg' => fmod($raDeg + 360.0, 360.0), 'decDeg' => $decDeg];
    }

    /**
     * Moon illumination fraction [0-1].
     * Uses known new moon reference: JD 2451549.5 (Jan 6, 2000).
     */
    public function getMoonPhase(\DateTimeImmutable $t): float
    {
        $jd    = $t->getTimestamp() / 86400.0 + 2440587.5;
        $phase = fmod($jd - 2451549.5, 29.53058867) / 29.53058867;
        if ($phase < 0) {
            $phase += 1.0;
        }

        return (1.0 - cos(2.0 * M_PI * $phase)) / 2.0;
    }

    /**
     * Angular separation between two sky positions (degrees) — haversine formula.
     */
    public function moonAngularSeparation(
        float $ra1Deg, float $dec1Deg,
        float $ra2Deg, float $dec2Deg
    ): float {
        $dDec = deg2rad($dec2Deg - $dec1Deg);
        $dRa  = deg2rad($ra2Deg  - $ra1Deg);
        $a    = sin($dDec / 2.0) ** 2
              + cos(deg2rad($dec1Deg)) * cos(deg2rad($dec2Deg)) * sin($dRa / 2.0) ** 2;

        return rad2deg(2.0 * asin(min(1.0, sqrt(max(0.0, $a)))));
    }

    /**
     * Night bounds (astronomical dusk / dawn) at given location.
     * Loops from 14:00 UTC in 5-min steps over 20h (port of JS getNightBounds).
     *
     * @return array{dusk: \DateTimeImmutable|null, dawn: \DateTimeImmutable|null}
     */
    public function getNightBounds(float $lat, float $lon, \DateTimeImmutable $baseDate): array
    {
        $baseTs = $baseDate->getTimestamp(); // midnight UTC of baseDate
        $dusk   = null;
        $dawn   = null;

        // From 14:00 UTC, 5-min steps, 20 hours = 240 steps
        for ($i = 0; $i < 240; $i++) {
            $ts = (int) ($baseTs + 14 * 3600 + $i * 300);
            $t  = new \DateTimeImmutable('@' . $ts);

            if ($this->sunAltitude($lat, $lon, $t) < -18.0) {
                if ($dusk === null) {
                    $dusk = $t;
                }
                $dawn = new \DateTimeImmutable('@' . ($ts + 300));
            }
        }

        return ['dusk' => $dusk, 'dawn' => $dawn];
    }

    /**
     * Priority score for a target (port of JS priorityScore()).
     *
     * @param float      $usefulH   Observable hours during the night
     * @param float      $moonPhase Illumination fraction [0-1]
     * @param float|null $moonSep   Min angular separation to moon (degrees), null if unknown
     * @param float      $deficitH  Remaining hours to reach goal
     * @param bool       $isNarrow  True if narrowband target (NEB/HII/SNR/PN etc.)
     */
    public function priorityScore(
        float $usefulH,
        float $moonPhase,
        ?float $moonSep,
        float $deficitH,
        bool $isNarrow
    ): float {
        $moonW = $isNarrow ? 0.15 : 1.0;
        $moonF = max(0.0, 1.0 - $moonPhase * $moonW);

        if ($moonSep === null) {
            $sepF = 0.0;
        } elseif ($moonSep < 20) {
            $sepF = 0.1;
        } elseif ($moonSep < 40) {
            $sepF = 0.5;
        } elseif ($moonSep < 60) {
            $sepF = 0.8;
        } else {
            $sepF = 1.0;
        }

        $visScore = $usefulH * $moonF * $sepF;

        return $deficitH > 0 ? $deficitH * $visScore : $visScore;
    }

    /**
     * Compute observable night window for a target.
     *
     * Loops from 18:00 to 06:00 (next day) UTC at 15-min steps.
     * Only counts slots where sun < -18° and target altitude > $horizon.
     * Window times are expressed in approximate local solar time (lon/15h offset).
     * Also computes min moon-target separation during the useful window and moon phase.
     *
     * @return array{usefulH: float, windowStart: string|null, windowEnd: string|null, minSep: float|null, moonPhase: float}
     */
    public function computeNight(
        float $lat, float $lon, float $horizon,
        float $raDeg, float $decDeg,
        \DateTimeImmutable $baseDate
    ): array {
        $usefulH        = 0.0;
        $windowStart    = null;
        $windowEnd      = null;
        $windowStartTs  = null;
        $windowEndTs    = null;
        $minSep         = null;
        $baseTs         = $baseDate->getTimestamp();

        // Approximate local solar time offset (seconds) from UTC
        $localOffsetSec = (int) round($lon / 15.0) * 3600;

        for ($h = 0.0; $h <= 12.0; $h += 0.25) {
            $ts = (int) ($baseTs + (18.0 + $h) * 3600.0);
            $t  = new \DateTimeImmutable('@' . $ts);

            if ($this->sunAltitude($lat, $lon, $t) > -18.0) {
                continue;
            }

            if ($this->altitude($lat, $lon, $raDeg, $decDeg, $t) > $horizon) {
                $usefulH += 0.25;
                $localTs  = $ts + $localOffsetSec;

                if ($windowStart === null) {
                    $windowStart   = gmdate('H:i', $localTs);
                    $windowStartTs = $ts;
                }
                $windowEnd   = gmdate('H:i', $localTs);
                $windowEndTs = $ts;

                // Track minimum moon-target angular separation
                $moon   = $this->getMoonPosition($t);
                $sep    = $this->moonAngularSeparation($moon['raDeg'], $moon['decDeg'], $raDeg, $decDeg);
                $minSep = $minSep === null ? $sep : min($minSep, $sep);
            }
        }

        // Moon phase at midnight UTC (midpoint of typical night)
        $midTs     = (int) ($baseTs + 24 * 3600);
        $moonPhase = $this->getMoonPhase(new \DateTimeImmutable('@' . $midTs));

        return [
            'usefulH'       => $usefulH,
            'windowStart'   => $windowStart,
            'windowEnd'     => $windowEnd,
            'windowStartTs' => $windowStartTs,
            'windowEndTs'   => $windowEndTs,
            'minSep'        => $minSep !== null ? round($minSep, 1) : null,
            'moonPhase'     => round($moonPhase, 3),
        ];
    }

    /**
     * Compute weather alert from forecast data.
     *
     * @param array $forecast  Raw forecast (must contain 'series' key)
     * @param array $thresholds Optional: cloud_max, wind_max, precip_max
     * @return array{favorable: bool, cloud_avg: int|null, wind_max: float|null, precip: float|null, score_avg: int|null}
     */
    public function computeWeatherAlert(array $forecast, array $thresholds = []): array
    {
        $cloudMax  = $thresholds['weather_cloud_max']  ?? $thresholds['cloud_max']  ?? 40;
        $windMax   = $thresholds['weather_wind_max']   ?? $thresholds['wind_max']   ?? 8;
        $precipMax = $thresholds['weather_precip_max'] ?? $thresholds['precip_max'] ?? 0.5;

        $series = $forecast['series'] ?? [];
        $now    = new \DateTime();
        $limit  = (clone $now)->modify('+12 hours');

        $clouds = $winds = $scores = [];
        $precipTotal = 0.0;

        foreach ($series as $p) {
            try { $t = new \DateTime($p['t']); } catch (\Exception) { continue; }
            if ($t < $now || $t > $limit) continue;
            if (isset($p['cloud_total']))     $clouds[] = (float) $p['cloud_total'];
            if (isset($p['wind_speed']))      $winds[]  = (float) $p['wind_speed'];
            if (isset($p['precip_mm']))       $precipTotal += (float) $p['precip_mm'];
            if (isset($p['condition_score'])) $scores[] = (float) $p['condition_score'];
        }

        if (empty($clouds)) {
            return ['favorable' => false, 'cloud_avg' => null, 'wind_max' => null, 'precip' => null, 'score_avg' => null];
        }

        $cloudAvg   = array_sum($clouds) / count($clouds);
        $windMaxVal = !empty($winds) ? max($winds) : 0.0;
        $scoreAvg   = !empty($scores) ? (int) round(array_sum($scores) / count($scores)) : null;

        return [
            'favorable' => $cloudAvg < $cloudMax && $windMaxVal < $windMax && $precipTotal < $precipMax,
            'cloud_avg' => (int) round($cloudAvg),
            'wind_max'  => round($windMaxVal, 1),
            'precip'    => round($precipTotal, 1),
            'score_avg' => $scoreAvg,
        ];
    }
}
