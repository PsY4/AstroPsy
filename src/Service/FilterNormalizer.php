<?php

namespace App\Service;

/**
 * Normalizes astrophotography filter names to canonical forms.
 * Labels and aliases are configured via AppConfig (settings page).
 */
class FilterNormalizer
{
    private array $canonical  = [];
    private array $aliasMap   = [];
    private array $colorMap   = [];
    private array $bandMap    = [];
    /** Full config rows keyed by label: ['L' => ['label'=>'L','color'=>'…','band'=>'BB','aliases'=>[…]], …] */
    private array $configRows = [];

    public function __construct(AppConfig $config)
    {
        foreach ($config->getFilters() as $f) {
            $label = $f['label'];
            $this->canonical[] = $label;
            $this->colorMap[$label] = $f['color'] ?? '#6c757d';
            $this->bandMap[$label]  = $f['band']  ?? 'BB';
            $this->configRows[$label] = $f;
            $this->aliasMap[strtolower($label)] = $label;
            foreach ($f['aliases'] as $alias) {
                $this->aliasMap[strtolower($alias)] = $label;
            }
        }
    }

    public function normalize(string $input): string
    {
        $key = strtolower(trim($input));
        return $this->aliasMap[$key] ?? $input;
    }

    /**
     * Sort a list of filter names: canonical order first, then others alphabetically.
     *
     * @param string[] $filters
     * @return string[]
     */
    public function sort(array $filters): array
    {
        usort($filters, function (string $a, string $b) {
            $posA = array_search($a, $this->canonical, true);
            $posB = array_search($b, $this->canonical, true);
            if ($posA === false && $posB === false) { return strcmp($a, $b); }
            if ($posA === false) { return 1; }
            if ($posB === false) { return -1; }
            return $posA - $posB;
        });
        return $filters;
    }

    public function getCanonical(): array
    {
        return $this->canonical;
    }

    public function getColorMap(): array
    {
        return $this->colorMap;
    }

    /**
     * Color map expanded with aliases: every alias and canonical label maps to its color.
     * Useful in templates where filter names may not be normalized.
     */
    public function getExpandedColorMap(): array
    {
        $map = $this->colorMap;
        foreach ($this->aliasMap as $alias => $label) {
            $map[$alias] = $this->colorMap[$label] ?? '#6c757d';
        }
        return $map;
    }

    public function getColor(string $filter): string
    {
        return $this->colorMap[$filter] ?? '#6c757d';
    }

    /** @return array<string,string> ['L' => 'BB', 'Ha' => 'NB', …] */
    public function getBandMap(): array
    {
        return $this->bandMap;
    }

    /**
     * Returns the full config as a JSON-serialisable array for JS injection.
     * Each item: { label, color, band }
     * @return list<array{label:string,color:string,band:string}>
     */
    public function getJsConfig(): array
    {
        return array_values(array_map(
            fn(array $f) => ['label' => $f['label'], 'color' => $f['color'] ?? '#6c757d', 'band' => $f['band'] ?? 'BB'],
            $this->configRows
        ));
    }
}
