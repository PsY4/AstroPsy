<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\KernelInterface;

class ThumbnailService
{
    private string $cacheRoot;

    public function __construct(KernelInterface $kernel)
    {
        $this->cacheRoot = rtrim($kernel->getProjectDir(), '/').'/var/cache/thumbs';
    }

    /**
     * Serve a cached thumbnail, generating it on-miss via $generator.
     *
     * @param callable $generator fn() => string (raw image bytes)
     */
    public function getCachedThumbnail(
        Request $request,
        string $sourcePath,
        int $width,
        string $cacheSubDir,
        callable $generator,
    ): Response {
        $mtime = @filemtime($sourcePath) ?: 0;
        $hash = sha1($sourcePath.'|w='.$width.'|m='.$mtime);
        $cacheDir = $this->cacheRoot.'/'.$cacheSubDir.'/'.$width;
        $cacheFile = $cacheDir.'/'.$hash.'.png';

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }

        if (!is_file($cacheFile)) {
            $content = $generator();
            $tmp = $cacheFile.'.tmp.'.bin2hex(random_bytes(6));
            file_put_contents($tmp, $content, LOCK_EX);
            @rename($tmp, $cacheFile);
            @chmod($cacheFile, 0664);
        }

        $response = new BinaryFileResponse($cacheFile);
        $response->headers->set('Content-Type', 'image/png');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->setSharedMaxAge(86400);
        $response->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
        $response->setEtag(substr(sha1_file($cacheFile), 0, 16));

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response;
    }
}
