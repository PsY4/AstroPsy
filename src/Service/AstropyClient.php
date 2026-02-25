<?php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AstropyClient
{
    private const DEFAULT_TIMEOUT = 10;

    public function __construct(private HttpClientInterface $client, private string $baseUrl) {}

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    public function forecast(float $lat, float $lon): string
    {
        $resp = $this->client->request('GET', $this->url('/astro/forecast'), [
            'query'   => ['lat' => $lat, 'lon' => $lon],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function fitsThumbnail(string $path, int $w = 512): string
    {
        $resp = $this->client->request('GET', $this->url('/fits/thumbnail'), [
            'query'   => ['path' => $path, 'w' => $w],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function fitsHeader(string $path): array
    {
        $resp = $this->client->request('GET', $this->url('/fits/header'), [
            'query'   => ['path' => $path],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->toArray(false);
    }

    public function rawHeader(string $path): array
    {
        $resp = $this->client->request('GET', $this->url('/raw/header'), [
            'query'   => ['path' => $path],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->toArray(false);
    }

    public function xisfThumbnail(string $path, int $w = 512): string
    {
        $resp = $this->client->request('GET', $this->url('/xisf/thumbnail'), [
            'query'   => ['path' => $path, 'w' => $w],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function xisfHeader(string $path): array
    {
        $resp = $this->client->request('GET', $this->url('/xisf/header'), [
            'query'   => ['path' => $path],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);

        // delete astrometry resolution (too large)
        $respA = $resp->toArray(false);
        if (isset($respA['metadata']['XISFProperties'])) $respA['metadata']['XISFProperties'] = "";

        return $respA;
    }

    public function imageThumbnail(string $path, int $w = 512): string
    {
        $resp = $this->client->request('GET', $this->url('/image/thumbnail'), [
            'query'   => ['path' => $path, 'w' => $w],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function imageHeader(string $path): array
    {
        $resp = $this->client->request('GET', $this->url('/image/header'), [
            'query'   => ['path' => $path],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->toArray(false);
    }

    public function tifThumbnail(string $path, int $w = 512): string
    {
        $resp = $this->client->request('GET', $this->url('/tif/thumbnail'), [
            'query'   => ['path' => $path, 'w' => $w],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function rawThumbnail(string $path, int $w = 512): string
    {
        $resp = $this->client->request('GET', $this->url('/raw/thumbnail'), [
            'query'   => ['path' => $path, 'w' => $w],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->getContent();
    }

    public function rawHistogram(string $path): array
    {
        $resp = $this->client->request('GET', $this->url('/raw/histogram'), [
            'query'   => ['path' => $path],
            'timeout' => self::DEFAULT_TIMEOUT,
        ]);
        return $resp->toArray(false);
    }

    public function rawRender(string $path, int $w, string $stretch, float $bp, float $wp): string
    {
        $resp = $this->client->request('GET', $this->url('/raw/render'), [
            'query'   => ['path' => $path, 'w' => $w, 'stretch' => $stretch, 'bp' => $bp, 'wp' => $wp],
            'timeout' => 30,
        ]);
        return $resp->getContent();
    }
}
