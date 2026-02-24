<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AstrobinAPIService
{
    private $baseURL = "https://app.astrobin.com/api/v2/";

    public function __construct(private HttpClientInterface $client) {}
    public function getProfileInfos($userId) {
        $resp = $this->client->request('GET', rtrim($this->baseURL,'/').
            '/common/userprofiles/'.$userId
        );
        return $resp->toArray();
    }
    public function getProfileStats($userId) {
        $resp = $this->client->request('GET', rtrim($this->baseURL,'/').
            '/common/userprofiles/'.$userId.'/stats/'
        );
        return $resp->toArray();
    }

    public function getImage($imageId) {
        $resp = $this->client->request('GET', rtrim($this->baseURL,'/').
            '/images/image/',[
                'query' => [
                    'hash' => $imageId,
                    'skip-thumbnails' => "false",
                ],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    ]
            ]
        );
        $data = $resp->toArray();
        if (empty($data['results'])) {
            return null;
        }
        return $data['results'][0];
    }

}