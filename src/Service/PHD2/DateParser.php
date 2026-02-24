<?php

namespace App\Service\PHD2;

/**
 * Parses PHD2 timestamp strings into DateTimeImmutable.
 * Handles: "2025-07-29 22:10:47", "29/07/2025 22:10:47", "2025/07/29 22:10:47"
 */
class DateParser
{
    private const FORMATS = ['Y-m-d H:i:s', 'd/m/Y H:i:s', 'Y/m/d H:i:s'];

    public function parse(string $tsRaw): ?\DateTimeImmutable
    {
        $tsRaw = trim($tsRaw);
        foreach (self::FORMATS as $f) {
            $dt = \DateTimeImmutable::createFromFormat($f, $tsRaw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }
        return null;
    }
}
