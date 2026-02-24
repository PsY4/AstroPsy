<?php
// src/Controller/FitsController.php
namespace App\Controller;

use App\Entity\Exposure;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class FitsController extends AbstractController
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
    ) {}

    #[Route('/fitsfile/{id}', name: 'fits_file')]
    public function fitsFile(int $id, EntityManagerInterface $em)
    {
        $exp = $em->getRepository(Exposure::class)->find($id);
        $absPath = $exp ? $this->resolver->toAbsolutePath($exp->getPath()) : null;
        if (!$exp || !is_readable($absPath)) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($absPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            basename($exp->getPath())
        );
        $response->headers->set('Content-Type', 'application/octet-stream');
        return $response;
    }
    #[Route('/fitsfile/js9safe/{id<\d+>}', name: 'fits_stream_js9safe')]
    public function streamJs9Safe(int $id, EntityManagerInterface $em, HttpClientInterface $http,
                                  #[Autowire('%env(ASTROPY_BASE_URL)%')] string $astropyBaseUrl): StreamedResponse
    {
        $exp = $em->getRepository(Exposure::class)->find($id);
        if (!$exp) { throw $this->createNotFoundException(); }
        $path = $this->resolver->toAbsolutePath($exp->getPath());

        $url = rtrim($astropyBaseUrl, '/').'/js9safe';
        $resp = $http->request('GET', $url, ['query' => ['path' => $path]]);

        if (200 !== $resp->getStatusCode()) {
            throw $this->createNotFoundException('Astropy normalize failed: '.$resp->getContent(false));
        }

        $content = $resp->getContent(); // bytes
        return new StreamedResponse(function() use ($content) {
            echo $content;
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="js9safe.fits"',
            'Cache-Control' => 'no-store',
        ]);
    }

}
