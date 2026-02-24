<?php

namespace App\Tests\Service;

use App\Service\AstroNightService;
use PHPUnit\Framework\TestCase;

class AstroNightServiceTest extends TestCase
{
    private AstroNightService $svc;

    protected function setUp(): void
    {
        $this->svc = new AstroNightService();
    }

    // ── altitude() ──────────────────────────────────────────────────────────

    public function testAltitudeZenithWhenDecEqualsLat(): void
    {
        // An object at dec=lat transits at zenith (alt ~90) at its meridian
        // Use Polaris-like: dec=45, lat=45, pick a time when HA~0
        // We test that at SOME point during a day the altitude reaches near 90
        $lat = 45.0;
        $dec = 45.0;
        $ra  = 0.0;

        $maxAlt = -90.0;
        for ($h = 0; $h < 24; $h++) {
            $t = new \DateTimeImmutable("2026-03-20 {$h}:00:00 UTC");
            $alt = $this->svc->altitude($lat, 0.0, $ra, $dec, $t);
            $maxAlt = max($maxAlt, $alt);
        }

        $this->assertEqualsWithDelta(90.0, $maxAlt, 1.5, 'Object at dec=lat should reach ~90 deg');
    }

    public function testAltitudeNorthPoleStarAlwaysAboveHorizon(): void
    {
        // Polaris (dec~89.3) from lat=45 should always be above horizon
        $lat = 45.0;
        $t   = new \DateTimeImmutable('2026-06-21 00:00:00 UTC');
        $alt = $this->svc->altitude($lat, 0.0, 0.0, 89.3, $t);
        $this->assertGreaterThan(30.0, $alt);
    }

    public function testAltitudeNegativeForObjectBelowHorizon(): void
    {
        // Southern object (dec=-70) from northern lat=50 should be negative
        $t   = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $alt = $this->svc->altitude(50.0, 0.0, 180.0, -70.0, $t);
        $this->assertLessThan(0.0, $alt);
    }

    public function testAltitudeSymmetryNorthSouth(): void
    {
        // Object at dec=0 should have same altitude from lat=45N and lat=45S at equinox noon
        $t    = new \DateTimeImmutable('2026-03-20 12:00:00 UTC');
        $altN = $this->svc->altitude(45.0, 0.0, 0.0, 0.0, $t);
        $altS = $this->svc->altitude(-45.0, 0.0, 0.0, 0.0, $t);
        $this->assertEqualsWithDelta($altN, $altS, 1.0);
    }

    // ── sunAltitude() ───────────────────────────────────────────────────────

    public function testSunAltitudeMidnightIsNegative(): void
    {
        // Sun at midnight UTC from Greenwich should be well below horizon
        $t   = new \DateTimeImmutable('2026-06-21 00:00:00 UTC');
        $alt = $this->svc->sunAltitude(51.48, 0.0, $t);
        $this->assertLessThan(0.0, $alt);
    }

    public function testSunAltitudeNoonPositive(): void
    {
        $t   = new \DateTimeImmutable('2026-06-21 12:00:00 UTC');
        $alt = $this->svc->sunAltitude(51.48, 0.0, $t);
        $this->assertGreaterThan(50.0, $alt, 'Sun should be high at summer solstice noon in London');
    }

    public function testSunAltitudeSummerSolsticeNoonTropics(): void
    {
        // At lat=23.44 (tropic), summer solstice noon: sun near zenith
        $t   = new \DateTimeImmutable('2026-06-21 12:00:00 UTC');
        $alt = $this->svc->sunAltitude(23.44, 0.0, $t);
        $this->assertGreaterThan(80.0, $alt);
    }

    public function testSunAltitudeWinterSolsticeNoonLow(): void
    {
        // Paris (48.86N) at winter solstice noon — sun should be low (~18 deg)
        $t   = new \DateTimeImmutable('2025-12-21 12:00:00 UTC');
        // lon=2.35 for Paris, but noon UTC is close
        $alt = $this->svc->sunAltitude(48.86, 2.35, $t);
        $this->assertLessThan(25.0, $alt);
        $this->assertGreaterThan(10.0, $alt);
    }

    // ── getMoonPosition() ───────────────────────────────────────────────────

    public function testMoonPositionReturnsValidRange(): void
    {
        $t    = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $moon = $this->svc->getMoonPosition($t);

        $this->assertArrayHasKey('raDeg', $moon);
        $this->assertArrayHasKey('decDeg', $moon);
        $this->assertGreaterThanOrEqual(0.0, $moon['raDeg']);
        $this->assertLessThan(360.0, $moon['raDeg']);
        $this->assertGreaterThanOrEqual(-30.0, $moon['decDeg']);
        $this->assertLessThanOrEqual(30.0, $moon['decDeg']);
    }

    public function testMoonPositionDifferentDates(): void
    {
        $t1 = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        $t2 = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');

        $m1 = $this->svc->getMoonPosition($t1);
        $m2 = $this->svc->getMoonPosition($t2);

        // Moon moves ~13 deg/day → 14 days = ~180 deg shift expected
        $this->assertNotEquals(round($m1['raDeg']), round($m2['raDeg']),
            'Moon RA should differ over 2 weeks');
    }

    public function testMoonPositionDecWithinBounds(): void
    {
        // Moon's declination is always between roughly -29 and +29 degrees
        for ($month = 1; $month <= 12; $month++) {
            $t    = new \DateTimeImmutable(sprintf('2026-%02d-15 00:00:00 UTC', $month));
            $moon = $this->svc->getMoonPosition($t);
            $this->assertGreaterThanOrEqual(-30.0, $moon['decDeg']);
            $this->assertLessThanOrEqual(30.0, $moon['decDeg']);
        }
    }

    // ── getMoonPhase() ──────────────────────────────────────────────────────

    public function testMoonPhaseNewMoonNearZero(): void
    {
        // Known new moon: Jan 29, 2025 12:36 UTC
        $t     = new \DateTimeImmutable('2025-01-29 12:36:00 UTC');
        $phase = $this->svc->getMoonPhase($t);
        $this->assertEqualsWithDelta(0.0, $phase, 0.05, 'New moon should have illumination ~0');
    }

    public function testMoonPhaseFullMoonNearOne(): void
    {
        // Known full moon: Feb 12, 2025 13:53 UTC
        $t     = new \DateTimeImmutable('2025-02-12 13:53:00 UTC');
        $phase = $this->svc->getMoonPhase($t);
        $this->assertEqualsWithDelta(1.0, $phase, 0.05, 'Full moon should have illumination ~1');
    }

    public function testMoonPhaseFirstQuarterNearHalf(): void
    {
        // Known first quarter: Feb 5, 2025 08:02 UTC
        $t     = new \DateTimeImmutable('2025-02-05 08:02:00 UTC');
        $phase = $this->svc->getMoonPhase($t);
        $this->assertEqualsWithDelta(0.5, $phase, 0.1, 'First quarter should be ~0.5');
    }

    public function testMoonPhaseAlwaysBetweenZeroAndOne(): void
    {
        for ($d = 0; $d < 30; $d++) {
            $t     = new \DateTimeImmutable("2026-03-01 +{$d} days UTC");
            $phase = $this->svc->getMoonPhase($t);
            $this->assertGreaterThanOrEqual(0.0, $phase);
            $this->assertLessThanOrEqual(1.0, $phase);
        }
    }

    public function testMoonPhasePeriodicity(): void
    {
        // Phase should repeat every ~29.53 days
        $t1 = new \DateTimeImmutable('2026-03-01 00:00:00 UTC');
        $t2 = new \DateTimeImmutable('2026-03-31 00:00:00 UTC'); // ~30 days

        $p1 = $this->svc->getMoonPhase($t1);
        $p2 = $this->svc->getMoonPhase($t2);
        $this->assertEqualsWithDelta($p1, $p2, 0.15, 'Phase should roughly repeat after ~30 days');
    }

    // ── moonAngularSeparation() ─────────────────────────────────────────────

    public function testSeparationSamePointIsZero(): void
    {
        $sep = $this->svc->moonAngularSeparation(100.0, 30.0, 100.0, 30.0);
        $this->assertEqualsWithDelta(0.0, $sep, 0.001);
    }

    public function testSeparationOppositePointsIs180(): void
    {
        // (0, 0) and (180, 0) = 180 degrees apart
        $sep = $this->svc->moonAngularSeparation(0.0, 0.0, 180.0, 0.0);
        $this->assertEqualsWithDelta(180.0, $sep, 0.1);
    }

    public function testSeparation90Degrees(): void
    {
        // (0, 0) and (90, 0) = 90 degrees
        $sep = $this->svc->moonAngularSeparation(0.0, 0.0, 90.0, 0.0);
        $this->assertEqualsWithDelta(90.0, $sep, 0.1);
    }

    public function testSeparationCommutative(): void
    {
        $sep1 = $this->svc->moonAngularSeparation(45.0, 30.0, 200.0, -15.0);
        $sep2 = $this->svc->moonAngularSeparation(200.0, -15.0, 45.0, 30.0);
        $this->assertEqualsWithDelta($sep1, $sep2, 0.001);
    }

    // ── getNightBounds() ────────────────────────────────────────────────────

    public function testNightBoundsMidLatitudeWinter(): void
    {
        // Paris in winter: astronomical darkness exists
        $t      = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $bounds = $this->svc->getNightBounds(48.86, 2.35, $t);

        $this->assertNotNull($bounds['dusk'], 'Dusk should exist in winter Paris');
        $this->assertNotNull($bounds['dawn'], 'Dawn should exist in winter Paris');
        $this->assertLessThan(
            $bounds['dawn']->getTimestamp(),
            $bounds['dusk']->getTimestamp(),
            'Dusk should be before dawn'
        );
    }

    public function testNightBoundsDuskBeforeDawn(): void
    {
        $t      = new \DateTimeImmutable('2026-06-15 00:00:00 UTC');
        $bounds = $this->svc->getNightBounds(45.0, 0.0, $t);

        if ($bounds['dusk'] !== null && $bounds['dawn'] !== null) {
            $this->assertLessThan(
                $bounds['dawn']->getTimestamp(),
                $bounds['dusk']->getTimestamp(),
                'Dusk should be before dawn'
            );
        }
    }

    public function testNightBoundsHighLatitudeSummerNoNight(): void
    {
        // At lat=65 (Northern Norway) in summer, no astronomical darkness
        $t      = new \DateTimeImmutable('2026-06-21 00:00:00 UTC');
        $bounds = $this->svc->getNightBounds(65.0, 15.0, $t);

        $this->assertNull($bounds['dusk'], 'No astronomical darkness at lat=65 in summer');
        $this->assertNull($bounds['dawn'], 'No astronomical darkness at lat=65 in summer');
    }

    public function testNightBoundsEquatorAlwaysHasNight(): void
    {
        $t      = new \DateTimeImmutable('2026-06-21 00:00:00 UTC');
        $bounds = $this->svc->getNightBounds(0.0, 0.0, $t);

        $this->assertNotNull($bounds['dusk']);
        $this->assertNotNull($bounds['dawn']);
    }

    public function testNightBoundsWinterLongerThanSummer(): void
    {
        $winter = $this->svc->getNightBounds(48.86, 2.35, new \DateTimeImmutable('2026-01-15 00:00:00 UTC'));
        $summer = $this->svc->getNightBounds(48.86, 2.35, new \DateTimeImmutable('2026-06-15 00:00:00 UTC'));

        $this->assertNotNull($winter['dusk'], 'Winter dusk must exist at lat 48');
        $this->assertNotNull($winter['dawn'], 'Winter dawn must exist at lat 48');

        $winterLen = $winter['dawn']->getTimestamp() - $winter['dusk']->getTimestamp();

        if ($summer['dusk'] && $summer['dawn']) {
            $summerLen = $summer['dawn']->getTimestamp() - $summer['dusk']->getTimestamp();
            $this->assertGreaterThan($summerLen, $winterLen, 'Winter nights should be longer');
        } else {
            // No astronomical darkness in summer at this latitude → winter is trivially longer
            $this->assertGreaterThan(0, $winterLen);
        }
    }

    // ── priorityScore() ─────────────────────────────────────────────────────

    public function testPriorityScoreZeroUsefulHoursGivesZero(): void
    {
        $score = $this->svc->priorityScore(0.0, 0.5, 50.0, 5.0, false);
        $this->assertEqualsWithDelta(0.0, $score, 0.001);
    }

    public function testPriorityScoreNarrowbandLessSensitiveToMoon(): void
    {
        $bb = $this->svc->priorityScore(5.0, 0.9, 50.0, 10.0, false);
        $nb = $this->svc->priorityScore(5.0, 0.9, 50.0, 10.0, true);
        $this->assertGreaterThan($bb, $nb,
            'Narrowband should score higher than broadband at high moon illumination');
    }

    public function testPriorityScoreHigherDeficitHigherScore(): void
    {
        $low  = $this->svc->priorityScore(5.0, 0.3, 80.0, 2.0, false);
        $high = $this->svc->priorityScore(5.0, 0.3, 80.0, 20.0, false);
        $this->assertGreaterThan($low, $high, 'Higher deficit should give higher score');
    }

    public function testPriorityScoreNullSepGivesSepFactorZero(): void
    {
        $score = $this->svc->priorityScore(5.0, 0.3, null, 10.0, false);
        $this->assertEqualsWithDelta(0.0, $score, 0.001,
            'Null moon separation should give sepF=0, thus score=0');
    }

    public function testPriorityScoreCloseMoonLowScore(): void
    {
        $close = $this->svc->priorityScore(5.0, 0.5, 10.0, 10.0, false);
        $far   = $this->svc->priorityScore(5.0, 0.5, 80.0, 10.0, false);
        $this->assertGreaterThan($close, $far, 'Far from moon should score higher');
    }

    public function testPriorityScoreAlwaysNonNegative(): void
    {
        $score = $this->svc->priorityScore(3.0, 1.0, 5.0, 0.0, false);
        $this->assertGreaterThanOrEqual(0.0, $score);
    }

    // ── computeNight() ──────────────────────────────────────────────────────

    public function testComputeNightReturnsExpectedKeys(): void
    {
        $t      = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $result = $this->svc->computeNight(48.86, 2.35, 20.0, 83.63, 22.01, $t); // Betelgeuse

        $this->assertArrayHasKey('usefulH', $result);
        $this->assertArrayHasKey('windowStart', $result);
        $this->assertArrayHasKey('windowEnd', $result);
        $this->assertArrayHasKey('minSep', $result);
        $this->assertArrayHasKey('moonPhase', $result);
    }

    public function testComputeNightWinterTargetHasUsefulHours(): void
    {
        // Orion Nebula (RA=5.59h=83.85deg, Dec=-5.39) visible in winter from Paris
        $t      = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $result = $this->svc->computeNight(48.86, 2.35, 20.0, 83.85, -5.39, $t);

        $this->assertGreaterThan(0.0, $result['usefulH'], 'Orion should be visible in January from Paris');
        $this->assertNotNull($result['windowStart']);
        $this->assertNotNull($result['windowEnd']);
    }

    public function testComputeNightSummerTargetNotVisibleInWinter(): void
    {
        // Veil Nebula (RA=20.76h=311.4deg, Dec=30.7) — summer object
        $t      = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $result = $this->svc->computeNight(48.86, 2.35, 20.0, 311.4, 30.7, $t);

        // With horizon=20, this should have very limited or no visibility in January
        $this->assertLessThanOrEqual(2.0, $result['usefulH'],
            'Veil Nebula should have limited winter visibility from Paris');
    }

    public function testComputeNightMoonPhaseInRange(): void
    {
        $t      = new \DateTimeImmutable('2026-03-01 00:00:00 UTC');
        $result = $this->svc->computeNight(45.0, 0.0, 15.0, 100.0, 20.0, $t);

        $this->assertGreaterThanOrEqual(0.0, $result['moonPhase']);
        $this->assertLessThanOrEqual(1.0, $result['moonPhase']);
    }

    public function testComputeNightMinSepNonNegative(): void
    {
        $t      = new \DateTimeImmutable('2026-01-15 00:00:00 UTC');
        $result = $this->svc->computeNight(48.86, 2.35, 20.0, 83.85, -5.39, $t);

        if ($result['minSep'] !== null) {
            $this->assertGreaterThanOrEqual(0.0, $result['minSep']);
        }
    }

    // ── computeWeatherAlert() ────────────────────────────────────────────────

    public function testWeatherAlertEmptyForecast(): void
    {
        $result = $this->svc->computeWeatherAlert([]);

        $this->assertFalse($result['favorable']);
        $this->assertNull($result['cloud_avg']);
        $this->assertNull($result['wind_max']);
        $this->assertNull($result['precip']);
        $this->assertNull($result['score_avg']);
    }

    public function testWeatherAlertEmptySeries(): void
    {
        $result = $this->svc->computeWeatherAlert(['series' => []]);

        $this->assertFalse($result['favorable']);
        $this->assertNull($result['cloud_avg']);
    }

    public function testWeatherAlertFavorable(): void
    {
        $now = new \DateTime();
        $series = [];
        for ($i = 1; $i <= 6; $i++) {
            $t = (clone $now)->modify("+{$i} hour");
            $series[] = [
                't'               => $t->format('c'),
                'cloud_total'     => 20,
                'wind_speed'      => 3.0,
                'precip_mm'       => 0.0,
                'condition_score' => 80,
            ];
        }

        $result = $this->svc->computeWeatherAlert(['series' => $series]);

        $this->assertTrue($result['favorable']);
        $this->assertEquals(20, $result['cloud_avg']);
        $this->assertEquals(3.0, $result['wind_max']);
        $this->assertEquals(0.0, $result['precip']);
        $this->assertEquals(80, $result['score_avg']);
    }

    public function testWeatherAlertUnfavorable(): void
    {
        $now = new \DateTime();
        $series = [];
        for ($i = 1; $i <= 6; $i++) {
            $t = (clone $now)->modify("+{$i} hour");
            $series[] = [
                't'               => $t->format('c'),
                'cloud_total'     => 80,
                'wind_speed'      => 15.0,
                'precip_mm'       => 2.0,
                'condition_score' => 20,
            ];
        }

        $result = $this->svc->computeWeatherAlert(['series' => $series]);

        $this->assertFalse($result['favorable']);
        $this->assertEquals(80, $result['cloud_avg']);
        $this->assertEquals(15.0, $result['wind_max']);
    }

    public function testWeatherAlertCustomThresholds(): void
    {
        $now = new \DateTime();
        $series = [];
        for ($i = 1; $i <= 4; $i++) {
            $t = (clone $now)->modify("+{$i} hour");
            $series[] = [
                't'           => $t->format('c'),
                'cloud_total' => 50,
                'wind_speed'  => 6.0,
                'precip_mm'   => 0.0,
            ];
        }

        // Default thresholds (cloud_max=40) → unfavorable
        $default = $this->svc->computeWeatherAlert(['series' => $series]);
        $this->assertFalse($default['favorable']);

        // Relaxed thresholds → favorable
        $relaxed = $this->svc->computeWeatherAlert(['series' => $series], ['cloud_max' => 60]);
        $this->assertTrue($relaxed['favorable']);
    }
}
