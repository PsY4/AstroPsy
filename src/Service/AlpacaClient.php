<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AlpacaClient
{
    public function __construct(private HttpClientInterface $client, private string $baseUrl) {}

    public function getSafetyMonitorConfig(int $device = 0): array
    {
        try {
            $resp = $this->client->request('GET', rtrim($this->baseUrl, '/') . "/internal/safetymonitor/{$device}/config");
            return $resp->toArray(false);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function saveSafetyMonitorConfig(int $device, array $data): array
    {
        try {
            $resp = $this->client->request('PUT', rtrim($this->baseUrl, '/') . "/internal/safetymonitor/{$device}/config", [
                'json' => $data,
            ]);
            return $resp->toArray(false);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getSwitchConfig(int $device = 0): array
    {
        try {
            $resp = $this->client->request('GET', rtrim($this->baseUrl, '/') . "/internal/switch/{$device}/config");
            return $resp->toArray(false);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function saveSwitchConfig(int $device, array $data): array
    {
        try {
            $resp = $this->client->request('PUT', rtrim($this->baseUrl, '/') . "/internal/switch/{$device}/config", [
                'json' => $data,
            ]);
            return $resp->toArray(false);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function getDevicesStatus(): array
    {
        $smRaw = $this->getSafetyMonitorConfig(0);
        $swRaw = $this->getSwitchConfig(0);

        $safetyMonitor = isset($smRaw['error']) ? ['error' => $smRaw['error']] : [
            'is_safe'      => $smRaw['weather']['is_safe'] ?? false,
            'condition'    => $smRaw['weather']['condition'] ?? 'Unknown',
            'last_updated' => $smRaw['weather']['last_updated'] ?? null,
        ];

        $switch = isset($swRaw['error']) ? ['error' => $swRaw['error']] : [
            'items' => array_map(fn($item) => [
                'id'         => $item['id'],
                'name'       => $item['name'],
                'is_boolean' => $item['is_boolean'],
                'value'      => $item['value'],
            ], $swRaw['items'] ?? []),
        ];

        return [
            'safety_monitor' => $safetyMonitor,
            'switch'         => $switch,
        ];
    }
}
