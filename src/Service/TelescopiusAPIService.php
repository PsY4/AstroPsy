<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class TelescopiusAPIService
{
    private string $baseURL = "https://api.telescopius.com/v2.0/";

    public function __construct(private HttpClientInterface $client, private string $apiKey) {}

    public function getTargetAbout($targetName, $mainId = "") {

        $resp = $this->client->request('GET', rtrim($this->baseURL,'/').'/targets/search', [
            'headers' => [
                'Authorization'=> 'Key ' . $this->apiKey
            ],
            'query' => [
                'types' => "DEEP_SKY_OBJECT",
                'timezone' => 'UTC',
                'time_format' => '24hr',
                'compute_current' => '0',
                'name' => (($mainId!="")?$mainId:$targetName),
                'name_exact' => 'true',
                'moon_dist_min' => '0',
                'mag_unknown' => 'true',
                'order' => 'mag',
                'order_asc' => 'true',
            ],
        ]);
        $infos =  $resp->toArray();
        if (isset($infos['page_results'][0]['object'])) {
            return $infos['page_results'][0]['object'];
        } else {
            return false;
        }
    }

    public function getTargets(int $minAlt = 65, int $minDuration = 180, int $moonDistMin = 53, int $magMax = 15): array
    {
        $resp = $this->client->request('GET', rtrim($this->baseURL,'/').'/targets/search', [
            'headers' => [
                'Authorization'=> 'Key ' . $this->apiKey
            ],
            'query' => [
                'types'            => 'DEEP_SKY_OBJECT',
                'time_format'      => '24hr',
                'hour_min'         => 'astronomical_sunset',
                'hour_max'         => 'astronomical_sunrise',
                'min_alt'          => $minAlt,
                'min_alt_minutes'  => $minDuration,
                'moon_dist_min'    => $moonDistMin,
                'mag_max'          => $magMax,
                'order'            => 'popularity',
                'order_asc'        => 1,
                'results_per_page' => 120,
            ],
        ]);
        $infos = $resp->toArray();
        return $infos['page_results'] ?? [];
    }
}
