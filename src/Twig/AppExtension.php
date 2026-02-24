<?php
namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('format_duration', [$this, 'formatDuration']),
            new TwigFilter('filesize_format', [$this, 'formatBytes']),
            new TwigFilter('az_to_cardinal', [$this, 'azToCardinal']),
        ];
    }

    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision).' '.$units[$pow];
    }
    public function formatDuration(int $seconds): string
    {
        if ($seconds < 0) {
            return '-' . $this->formatDuration(abs($seconds));
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $parts = [];
        if ($h > 0) $parts[] = $h . 'h';
        if ($m > 0 || $s > 0) $parts[] = $m . 'm';
        if ($s > 0) $parts[] = $s . 's';

        return implode('', $parts);
    }

    public function azToCardinal(?float $az): ?string
    {
        if ($az === null || !is_numeric($az)) {
            return null;
        }

        // Normalize to [0, 360)
        $az = fmod((float)$az, 360.0);
        if ($az < 0) {
            $az += 360.0;
        }

        // 16-wind rose, 22.5° per segment
        $dirs = ['N','NNE','NE','ENE','E','ESE','SE','SSE',
            'S','SSW','SW','WSW','W','WNW','NW','NNW','N'];

        $i = (int) round($az / 22.5);
        return $dirs[$i];
    }
}
