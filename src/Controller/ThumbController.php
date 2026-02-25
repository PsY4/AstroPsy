<?php

namespace App\Controller;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Service\AstropyClient;
use App\Service\StoragePathResolver;
use App\Service\ThumbnailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

final class ThumbController extends AbstractController
{
    public function __construct(
        private readonly ThumbnailService $thumbnailService,
        private readonly AstropyClient $astropy,
        private readonly StoragePathResolver $resolver,
    ) {}

    #[Route('/thumbnail/{light}/{w}', name: 'thumbnail', methods: ['GET'])]
    public function thumbnail(Exposure $light, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($light->getPath());
        $content = $this->astropy->fitsThumbnail($absPath, $w);

        return new Response($content, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'max-age=3600, public',
        ]);
    }

    #[Route('/export-thumbnail/{export}/{w}', name: 'export-thumbnail', methods: ['GET'])]
    public function exportThumbnail(Request $request, Export $export, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($export->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'fits',
            fn () => $this->astropy->fitsThumbnail($absPath, $w),
        );
    }

    #[Route('/fits-thumbnail/{light}/{w}', name: 'fits-thumbnail', methods: ['GET'])]
    public function fitsThumbnail(Request $request, Exposure $light, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($light->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'fits',
            fn () => $this->astropy->fitsThumbnail($absPath, $w),
        );
    }

    #[Route('/xisf-thumbnail/{master}/{w}', name: 'xisf-thumbnail', methods: ['GET'])]
    public function xisfThumbnail(Request $request, Master $master, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($master->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'xisf',
            fn () => $this->astropy->xisfThumbnail($absPath, $w),
        );
    }

    #[Route('/image-thumbnail/{export}/{w}', name: 'image-thumbnail', methods: ['GET'])]
    public function imageThumbnail(Request $request, Export $export, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($export->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'images',
            fn () => $this->astropy->imageThumbnail($absPath, $w),
        );
    }

    #[Route('/tif-thumbnail/{export}/{w}', name: 'tif-thumbnail', methods: ['GET'])]
    public function tifThumbnail(Request $request, Export $export, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($export->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'tif',
            fn () => $this->astropy->tifThumbnail($absPath, $w),
        );
    }

    #[Route('/raw-histogram/{exposure}', name: 'raw_histogram', methods: ['GET'])]
    public function rawHistogram(Exposure $exposure): JsonResponse
    {
        $absPath = $this->resolver->toAbsolutePath($exposure->getPath());
        return new JsonResponse($this->astropy->rawHistogram($absPath));
    }

    #[Route('/raw-render/{exposure}/{w}', name: 'raw_render', methods: ['GET'])]
    public function rawRender(
        Request $request,
        KernelInterface $kernel,
        Exposure $exposure,
        int $w = 1920
    ): BinaryFileResponse {
        $absPath  = $this->resolver->toAbsolutePath($exposure->getPath());
        $stretch  = $request->query->get('stretch', 'asinh');
        $bp       = (float) $request->query->get('bp', 0.1);
        $wp       = (float) $request->query->get('wp', 99.9);
        $cacheKey = hash('xxh3', $absPath."|w={$w}|{$stretch}|{$bp}|{$wp}");
        $cacheDir = $kernel->getCacheDir().'/thumbs/raw-render/';
        $cached   = $cacheDir.$cacheKey.'.png';
        if (!file_exists($cached)) {
            @mkdir($cacheDir, 0775, true);
            file_put_contents($cached, $this->astropy->rawRender($absPath, $w, $stretch, $bp, $wp));
        }
        return new BinaryFileResponse($cached, 200, [
            'Content-Type'  => 'image/png',
            'Cache-Control' => 'max-age=3600, private',
        ]);
    }

    #[Route('/raw-thumbnail/{exposure}/{w}', name: 'raw-thumbnail', methods: ['GET'])]
    public function rawThumbnail(Request $request, Exposure $exposure, int $w = 512): Response
    {
        $absPath = $this->resolver->toAbsolutePath($exposure->getPath());
        return $this->thumbnailService->getCachedThumbnail(
            $request, $absPath, $w, 'raw',
            fn () => $this->astropy->rawThumbnail($absPath, $w),
        );
    }
}
