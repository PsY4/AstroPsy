<?php

namespace App\Service;

class AppConfig
{
    private array $data;
    private string $path;

    private const DEFAULTS = [
        'locale' => 'en',
        'theme'  => 'dark',
        'sessions_root' => null,
        'notifications' => [
            'emails'              => ['admin@astropsy.local'],
            'weather_cloud_max'   => 40,
            'weather_wind_max'    => 8.0,
            'weather_precip_max'  => 0.5,
            'min_useful_hours'    => 2.0,
            'min_moon_sep'        => 30,
        ],
        'filters' => [
            ['label' => 'L',    'color' => '#adb5bd', 'band' => 'BB', 'aliases' => ['l', 'lum', 'luminance', 'l-pro', 'lpro', 'l-enhance', 'lenhance', 'l2', 'lext']],
            ['label' => 'R',    'color' => '#dc3545', 'band' => 'BB', 'aliases' => ['r', 'red']],
            ['label' => 'G',    'color' => '#28a745', 'band' => 'BB', 'aliases' => ['g', 'green']],
            ['label' => 'B',    'color' => '#007bff', 'band' => 'BB', 'aliases' => ['b', 'blue']],
            ['label' => 'Ha',   'color' => '#fd7e14', 'band' => 'NB', 'aliases' => ['ha', 'h', 'h-alpha', 'halpha', 'h_alpha', 'hα', 'ha_', 'h-a']],
            ['label' => 'OIII', 'color' => '#17a2b8', 'band' => 'NB', 'aliases' => ['oiii', 'o', 'o-iii', 'o3', 'o_iii', 'oxy', 'oxygen']],
            ['label' => 'SII',  'color' => '#6f42c1', 'band' => 'NB', 'aliases' => ['sii', 's', 's-ii', 's2', 's_ii', 'sul', 'sulphur', 'sulfur']],
        ],
        'session_template' => [
            'version' => 1,
            'tree' => [
                ['name' => '00 - Metadata', 'children' => []],
                ['name' => '01 - Planning', 'children' => []],
                ['name' => '02 - Acquisition', 'children' => [
                    ['name' => 'logs', 'children' => [
                        ['name' => 'autofocus', 'role' => 'LOG_AF', 'allowExtra' => true, 'children' => []],
                        ['name' => 'nina', 'role' => 'LOG_NINA', 'children' => []],
                        ['name' => 'phd2', 'role' => 'LOG_PHD2', 'children' => []],
                        ['name' => 'session', 'allowExtra' => true, 'children' => []],
                    ]],
                    ['name' => 'metadata', 'allowExtra' => true, 'children' => []],
                    ['name' => 'raw', 'children' => [
                        ['name' => 'bias', 'role' => 'BIAS', 'children' => []],
                        ['name' => 'dark', 'role' => 'DARK', 'children' => []],
                        ['name' => 'flat', 'role' => 'FLAT', 'children' => []],
                        ['name' => 'light', 'role' => 'LIGHT', 'allowExtra' => true, 'children' => []],
                    ]],
                ]],
                ['name' => '03 - Processing', 'children' => [
                    ['name' => 'exports', 'role' => 'EXPORT', 'children' => []],
                    ['name' => 'logs', 'role' => 'LOG_WBPP', 'allowExtra' => true, 'children' => []],
                    ['name' => 'master', 'role' => 'MASTER', 'children' => []],
                    ['name' => 'pixinsight', 'allowExtra' => true, 'children' => []],
                ]],
                ['name' => '99 - Docs', 'role' => 'DOC', 'children' => []],
            ],
        ],
    ];

    public function __construct(string $projectDir)
    {
        $this->path = $projectDir . '/var/app_config.json';
        $this->data = file_exists($this->path)
            ? (json_decode(file_get_contents($this->path), true) ?? [])
            : [];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? self::DEFAULTS[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->save();
    }

    public function getSection(string $section): array
    {
        $stored   = $this->data[$section] ?? [];
        $defaults = self::DEFAULTS[$section] ?? [];
        return array_merge($defaults, $stored);
    }

    public function setSection(string $section, array $values): void
    {
        $this->data[$section] = $values;
        $this->save();
    }

    // --- Shortcuts ---

    public function getLocale(): string
    {
        return $this->get('locale', 'en');
    }

    public function setLocale(string $locale): void
    {
        $this->set('locale', $locale);
    }

    public function getTheme(): string
    {
        return $this->get('theme', 'dark');
    }

    public function setTheme(string $theme): void
    {
        $this->set('theme', $theme);
    }

    public function getFilters(): array
    {
        return $this->get('filters', self::DEFAULTS['filters']);
    }

    public function setFilters(array $v): void
    {
        $this->set('filters', $v);
    }

    /** @return array<string,string> ['L' => '#adb5bd', 'R' => '#dc3545', ...] */
    public function getFilterColorMap(): array
    {
        return array_column($this->getFilters(), 'color', 'label');
    }

    public function getSessionsRoot(): ?string
    {
        return $this->get('sessions_root');
    }

    public function setSessionsRoot(?string $path): void
    {
        $this->set('sessions_root', $path);
    }

    public function getSessionTemplate(): array
    {
        return $this->get('session_template', self::DEFAULTS['session_template']);
    }

    public function setSessionTemplate(array $template): void
    {
        $this->set('session_template', $template);
    }

    // --- Private ---

    private function save(): void
    {
        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
