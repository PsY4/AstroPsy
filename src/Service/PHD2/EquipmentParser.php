<?php

namespace App\Service\PHD2;

/**
 * Parses PHD2 equipment header fields common to both calibration and guiding sections.
 *
 * Returns array keys: equipmentProfile, camera, exposureMs, pixelScale, binning,
 *   focalLength, raGuideSpeed, decGuideSpeed, raHr, decDeg, hourAngleHr,
 *   pierSide, altDeg, azDeg, lockPos ([x,y]|null), starPos ([x,y]|null), hfdPx.
 */
class EquipmentParser
{
    private static function toFloat(?string $s): ?float
    {
        if ($s === null || $s === '') {
            return null;
        }
        $s = str_replace(',', '.', trim($s));
        return is_numeric($s) ? (float) $s : null;
    }

    /**
     * @param  string[] $sectionLines Lines of the PHD2 section (header lines only).
     * @return array<string, mixed>
     */
    public function parseCommonFields(array $sectionLines): array
    {
        $f = [
            'equipmentProfile' => null,
            'camera'           => null,
            'exposureMs'       => null,
            'pixelScale'       => null,
            'binning'          => null,
            'focalLength'      => null,
            'raGuideSpeed'     => null,
            'decGuideSpeed'    => null,
            'raHr'             => null,
            'decDeg'           => null,
            'hourAngleHr'      => null,
            'pierSide'         => null,
            'altDeg'           => null,
            'azDeg'            => null,
            'lockPos'          => null,
            'starPos'          => null,
            'hfdPx'            => null,
        ];

        foreach ($sectionLines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'INFO:')) {
                continue;
            }

            if ($f['equipmentProfile'] === null && preg_match('/^Equipment Profile\s*=\s*(.+)$/i', $line, $m)) {
                $f['equipmentProfile'] = trim($m[1]);
            } elseif ($f['camera'] === null && preg_match('/^\s*Camera\s*=\s*(.+)$/i', $line, $m)) {
                $f['camera'] = trim($m[1]);
            } elseif ($f['exposureMs'] === null && preg_match('/^Exposure\s*=\s*([0-9]+)\s*ms$/i', $line, $m)) {
                $f['exposureMs'] = (int) $m[1];
            } elseif ($f['pixelScale'] === null && preg_match(
                '/^Pixel scale\s*=\s*([0-9.,+-]+)\s*arc-sec\/px,\s*Binning\s*=\s*([0-9]+),\s*Focal length\s*=\s*([0-9.,+-]+)\s*mm$/i',
                $line, $m
            )) {
                $f['pixelScale']  = self::toFloat($m[1]);
                $f['binning']     = (int) $m[2];
                $f['focalLength'] = self::toFloat($m[3]);
            } elseif ($f['raGuideSpeed'] === null && preg_match(
                '/^RA Guide Speed\s*=\s*([0-9.]+)\s*a\-s\/s,\s*Dec Guide Speed\s*=\s*([0-9.]+)\s*a\-s\/s/i',
                $line, $m
            )) {
                $f['raGuideSpeed']  = (float) $m[1];
                $f['decGuideSpeed'] = (float) $m[2];
            } elseif ($f['raHr'] === null && preg_match(
                '/^RA\s*=\s*([0-9.+-]+)\s*hr,\s*Dec\s*=\s*([0-9.+-]+)\s*deg,\s*Hour angle\s*=\s*([0-9.+-]+)\s*hr,\s*Pier side\s*=\s*([A-Za-z]+).*?Alt\s*=\s*([0-9.+-]+)\s*deg,\s*Az\s*=\s*([0-9.+-]+)\s*deg/i',
                $line, $m
            )) {
                $f['raHr']        = (float) $m[1];
                $f['decDeg']      = (float) $m[2];
                $f['hourAngleHr'] = (float) $m[3];
                $f['pierSide']    = trim($m[4]);
                $f['altDeg']      = (float) $m[5];
                $f['azDeg']       = (float) $m[6];
            } elseif ($f['lockPos'] === null && preg_match(
                '/^Lock position\s*=\s*([0-9.,+-]+),\s*([0-9.,+-]+),\s*Star position\s*=\s*([0-9.,+-]+),\s*([0-9.,+-]+),\s*HFD\s*=\s*([0-9.,+-]+)\s*px$/i',
                $line, $m
            )) {
                $f['lockPos'] = [self::toFloat($m[1]), self::toFloat($m[2])];
                $f['starPos'] = [self::toFloat($m[3]), self::toFloat($m[4])];
                $f['hfdPx']   = self::toFloat($m[5]);
            }
        }

        return $f;
    }
}
