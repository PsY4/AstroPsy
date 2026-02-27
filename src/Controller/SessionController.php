<?php

namespace App\Controller;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Phd2Calibration;
use App\Entity\Phd2Guiding;
use App\Entity\Session;
use App\Entity\Target;
use App\Entity\AutofocusLog;
use App\Entity\WbppLog;
use App\Enum\SessionFolder;
use App\Form\SessionType;
use App\Service\AstrobinAPIService;
use App\Service\AstropyClient;
use App\Service\AutofocusLogsReader;
use App\Service\FilterNormalizer;
use App\Service\PHD2LogsReader;
use App\Service\SessionRefreshService;
use App\Service\StoragePathResolver;
use App\Service\WBPPLogsReader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SessionController extends AbstractController
{
    public function __construct(
        private FilterNormalizer $normalizer,
        private SessionRefreshService $refreshService,
        private StoragePathResolver $resolver,
    ) {}

    #[Route('/session/{id<\d+>}', name: 'browse_session')]
    public function browseSession(int $id, Request $req, EntityManagerInterface $em, AstrobinAPIService $astrobin): Response
    {
        $session = $em->getRepository(Session::class)->find($id);
        if (!$session) {
            throw $this->createNotFoundException();
        }

        $exposures = $session->getExposures();
        $logs =$session->getLogs();
        $masters = $session->getMasters();
        $exports = $session->getExports();

        // Edit form
        $editForm = $this->createForm(SessionType::class, $session);
        $editForm->handleRequest($req);
        if ($editForm->isSubmitted() && $editForm->isValid()) {
            $em->persist($session);
            $em->flush();
            return $this->redirectToRoute('browse_session', ['id'=>$session->getId()]);
        }

        $consistency = $this->resolver->analyzeConsistency($session);

        return $this->render('browse/session.html.twig', [
            'session'        => $session,
            'target'         => $session->getTarget(),
            'exposures'      => $exposures,
            'logs'           => $logs,
            'masters'        => $masters,
            'exports'        => $exports,
            'editForm'       => $editForm->createView(),
            'filterColorMap' => $this->normalizer->getExpandedColorMap(),
            'consistency'    => $consistency,
        ]);
    }

    #[Route('/session/{id}/toggle-progress-exclude', name: 'session_toggle_progress_exclude', methods: ['POST'])]
    public function toggleProgressExclude(Session $session, EntityManagerInterface $em): JsonResponse
    {
        $session->setExcludeFromProgress(!$session->isExcludeFromProgress());
        $em->flush();
        return new JsonResponse(['excluded' => $session->isExcludeFromProgress()]);
    }

    #[Route('/session/{id}/notes', name: 'session_notes', methods: ['POST'])]
    public function saveNotes(Session $session, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $notes = $request->request->get('notes', '');
        $session->setNotes($notes !== '' ? $notes : null);
        $em->flush();
        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/session/{id<\d+>}/refresh/count', name: 'api_session_refresh_count', methods: ['GET'])]
    public function refreshCount(Session $session, AutofocusLogsReader $afReader): JsonResponse
    {
        $counts = [];
        $folders = [
            'lights' => SessionFolder::LIGHT,
            'darks'  => SessionFolder::DARK,
            'flats'  => SessionFolder::FLAT,
            'bias'   => SessionFolder::BIAS,
            'masters'=> SessionFolder::MASTER,
            'exports'=> SessionFolder::EXPORT,
            'phd2'   => SessionFolder::LOG_PHD2,
            'wbpp'   => SessionFolder::LOG_WBPP,
        ];

        foreach ($folders as $key => $folder) {
            $pattern = $folder->filePattern();
            $path = $this->resolver->resolve($session, $folder);
            if (!$pattern || !is_dir($path)) {
                $counts[$key] = 0;
                continue;
            }
            $finder = new Finder();
            $finder->files()->in($path)->name($pattern);
            $counts[$key] = iterator_count($finder);
        }

        // Autofocus: count run directories (not files)
        $counts['autofocus'] = $afReader->countRunDirs($session);

        return new JsonResponse($counts);
    }

    #[Route('/api/session/{id<\d+>}/refresh/purge', name: 'api_session_refresh_purge', methods: ['POST'])]
    public function refreshPurge(Session $session, EntityManagerInterface $em): JsonResponse
    {
        try {
            $em->createQuery('DELETE App\Entity\Exposure e WHERE e.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\Master m WHERE m.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\Export e WHERE e.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\Phd2Calibration c WHERE c.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\Phd2Guiding g WHERE g.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\WbppLog w WHERE w.session = :s')->execute(['s' => $session]);
            $em->createQuery('DELETE App\Entity\AutofocusLog a WHERE a.session = :s')->execute(['s' => $session]);

            return new JsonResponse(['ok' => true]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/raws', name: 'api_session_refresh_raws', methods: ['POST'])]
    public function refreshRaws(Session $session): JsonResponse
    {
        try {
            $processed = $this->refreshService->refreshRaws($session);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/phd2', name: 'api_session_refresh_phd2', methods: ['POST'])]
    public function refreshPhd2(Session $session, EntityManagerInterface $em, PHD2LogsReader $phd2Reader): JsonResponse
    {
        try {
            $processed = $phd2Reader->refreshPHD2Logs($session, $em);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/wbpp', name: 'api_session_refresh_wbpp', methods: ['POST'])]
    public function refreshWbpp(Session $session, EntityManagerInterface $em, WBPPLogsReader $wbppReader): JsonResponse
    {
        try {
            $processed = $wbppReader->refreshWBPPLogs($session, $em);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/autofocus', name: 'api_session_refresh_autofocus', methods: ['POST'])]
    public function refreshAutofocus(Session $session, EntityManagerInterface $em, AutofocusLogsReader $afReader): JsonResponse
    {
        try {
            $processed = $afReader->refreshAutofocusLogs($session, $em);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/masters', name: 'api_session_refresh_masters', methods: ['POST'])]
    public function refreshMasters(Session $session): JsonResponse
    {
        try {
            $processed = $this->refreshService->refreshMasters($session);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/exports', name: 'api_session_refresh_exports', methods: ['POST'])]
    public function refreshExports(Session $session): JsonResponse
    {
        try {
            $processed = $this->refreshService->refreshExports($session);
            return new JsonResponse(['processed' => $processed]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/{step}/files', name: 'api_session_refresh_step_files', methods: ['GET'], requirements: ['step' => 'raws|masters|exports'])]
    public function listStepFiles(Session $session, string $step): JsonResponse
    {
        try {
            return new JsonResponse($this->refreshService->listFilesForStep($session, $step));
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/api/session/{id<\d+>}/refresh/{step}/file', name: 'api_session_refresh_step_file', methods: ['POST'], requirements: ['step' => 'raws|masters|exports'])]
    public function processStepFile(Session $session, string $step, Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);
            $path = $data['path'] ?? '';
            $folder = $data['folder'] ?? 'light';

            $result = match ($step) {
                'raws'    => $this->refreshService->processSingleRaw($session, $path, $folder),
                'masters' => $this->refreshService->processSingleMaster($session, $path),
                'exports' => $this->refreshService->processSingleExport($session, $path),
            };

            return new JsonResponse($result);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/session/new/target/{target}', name: 'new_session')]
    public function newSession(Target $target, Request $request, EntityManagerInterface $em): Response
    {
        $sessionDate = new \DateTime($request->request->get('sessionDate'));
        $baseRelPath = $target->getPath() . '/' . $sessionDate->format("Y-m-d");
        $relPath = $baseRelPath;
        $suffix = 2;

        // Ensure path uniqueness (append _2, _3, etc.)
        while (is_dir($this->resolver->toAbsolutePath($relPath))) {
            $relPath = $baseRelPath . '_' . $suffix;
            $suffix++;
        }

        $absSessionPath = $this->resolver->toAbsolutePath($relPath);

        $newSession = new Session();
        $newSession
            ->setTarget($target)
            ->setStartedAt($sessionDate)
            ->setPath($relPath);

        $em->persist($newSession);
        $em->flush();

        $filesystem = new Filesystem();
        try {
            foreach ($this->resolver->getAllRelativePaths() as $dir) {
                $filesystem->mkdir(Path::normalize($absSessionPath . '/' . $dir));
            }
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException(
                'An error occurred while creating your directory at ' . $exception->getPath() . ': ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        return $this->redirectToRoute('browse_session', ['id' => $newSession->getId()]);
    }

    #[Route('/details/light/{exposure}', name: 'exposure_detail')]
    public function exposureDetail(
    Exposure $exposure,
    AstropyClient $astropy,
    EntityManagerInterface $entityManager
): Response
{

    if ($exposure->getRawHeader() == null)
    {
        $absPath = $this->resolver->toAbsolutePath($exposure->getPath());
        $headers = $exposure->getFormat() === 'FITS'
            ? $astropy->fitsHeader($absPath)
            : $astropy->rawHeader($absPath);
        $exposure->setRawHeader($headers);
        $entityManager->flush();
    }

    return $this->render('details/exposure.html.twig', [
        'exposure' => $exposure
    ]);
}

    #[Route('/details/master/{master}', name: 'master_detail')]
    public function masterDetail(
    Master               $master,
    AstropyClient          $astropy,
    EntityManagerInterface $entityManager
): Response
{

    if ($master->getHeader() == [])
    {
        $absPath = $this->resolver->toAbsolutePath($master->getPath());
        $headers = $master->getType() === 'XISF'
            ? $astropy->xisfHeader($absPath)
            : $astropy->fitsHeader($absPath);
        $master->setHeader($headers);
        $entityManager->persist($master);
        $entityManager->flush();
    }
    return $this->render('details/master.html.twig', [
        'master' => $master
    ]);
}

    #[Route('/details/export/{export}', name: 'export_detail')]
    public function exportDetail(
    Export                 $export,
    AstropyClient          $astropy,
    EntityManagerInterface $entityManager
): Response
{

    $meta = $export->getMetadata();
    $needsRefresh = $meta === [] || $meta === null;

    // Re-fetch for TIFF exports missing the enriched tiff_summary
    if (!$needsRefresh && in_array(strtolower($export->getType()), ['tif', 'tiff'], true)
        && !isset($meta['tiff_summary'])) {
        $needsRefresh = true;
    }

    if ($needsRefresh) {
        $export->setMetadata($astropy->imageHeader($this->resolver->toAbsolutePath($export->getPath())));
        $entityManager->persist($export);
        $entityManager->flush();
    }

    return $this->render('details/image.html.twig', [
        'image' => $export
    ]);
}

    #[Route('/hide/phd2calibration/{id}/{hidden}', name: 'phd2calibration_hide')]
    public function phd2calibrationHide(
        Phd2Calibration $id, EntityManagerInterface $em, $hidden = true
    ): Response
    {
        $id->setHidden($hidden);
        $em->persist($id);
        $em->flush();
        return $this->redirectToRoute('browse_session', ['id'=> $id->getSession()->getId()]);
    }

    #[Route('/details/phd2calibration/{id}', name: 'phd2calibration_detail')]
    public function phd2calibrationDetail(
        Phd2Calibration        $id
    ): Response
    {

        return $this->render('details/phd2calibration.html.twig', [
            'calibration' => $id
        ]);
    }

    #[Route('/hide/phd2guiding/{id}/{hidden}', name: 'phd2guiding_hide')]
    public function phd2guidingHide(
        Phd2Guiding $id, EntityManagerInterface $em, $hidden = false
    ): Response
    {
        $id->setHidden($hidden);
        $em->persist($id);
        $em->flush();
        return $this->redirectToRoute('browse_session', ['id'=> $id->getSession()->getId()]);
    }

    #[Route('/details/phd2guiding/{id}', name: 'phd2guiding_detail')]
    public function phd2guidingDetail(
        Phd2Guiding        $id
    ): Response
    {

        return $this->render('details/phd2guiding.html.twig', [
            'guiding' => $id
        ]);
    }

    #[Route('/details/phd2calibration/{id}/graph', name: 'phd2calibrationgraph_detail')]
    public function phd2calibrationgraphDetail(
        Phd2Calibration        $id
    ): Response
    {

        return $this->render('details/phd2calibrationgraph.html.twig', [
            'calibration' => $id
        ]);
    }

    #[Route('/details/phd2guiding/{id}/graph', name: 'phd2guidinggraph_detail')]
    public function phd2guidinggraphDetail(
        Phd2Guiding        $id
    ): Response
    {

        return $this->render('details/phd2guidinggraph.html.twig', [
            'guiding' => $id
        ]);
    }

    #[Route('/hide/autofocuslog/{id}/{hidden}', name: 'autofocuslog_hide')]
    public function autofocuslogHide(
        AutofocusLog $id, EntityManagerInterface $em, $hidden = true
    ): Response
    {
        $id->setHidden($hidden);
        $em->persist($id);
        $em->flush();
        return $this->redirectToRoute('browse_session', ['id' => $id->getSession()->getId()]);
    }

    #[Route('/details/autofocuslog/{id}', name: 'autofocuslog_detail')]
    public function autofocuslogDetail(AutofocusLog $id): Response
    {
        return $this->render('details/autofocuslog.html.twig', [
            'af' => $id
        ]);
    }

    #[Route('/details/autofocuslog/{id}/sidebar', name: 'autofocuslog_sidebar')]
    public function autofocuslogSidebar(AutofocusLog $id): Response
    {
        return $this->render('details/autofocuslog_sidebar.html.twig', [
            'af' => $id
        ]);
    }

    #[Route('/details/autofocuslog/{id}/graph', name: 'autofocuslog_graph')]
    public function autofocuslogGraph(AutofocusLog $id): Response
    {
        return $this->render('details/autofocuslog_graph.html.twig', [
            'af' => $id
        ]);
    }

    #[Route('/api/autofocus/{id}/images', name: 'api_autofocus_images')]
    public function autofocusImages(AutofocusLog $id): JsonResponse
    {
        $absPath = $this->resolver->toAbsolutePath($id->getSourcePath());
        $images = [];

        if (is_dir($absPath)) {
            $items = @scandir($absPath);
            if ($items !== false) {
                foreach ($items as $item) {
                    if (preg_match('/\.(png|jpg|jpeg)$/i', $item) && is_file($absPath . '/' . $item)) {
                        $images[] = [
                            'name' => $item,
                            'path' => $id->getSourcePath() . '/' . $item,
                        ];
                    }
                }
                sort($images);
            }
        }

        return new JsonResponse($images);
    }

    #[Route('/api/autofocus/image', name: 'api_autofocus_image')]
    public function autofocusImage(Request $request): Response
    {
        $relPath = $request->query->get('path', '');
        if ($relPath === '' || str_contains($relPath, '..')) {
            return new Response('Forbidden', 403);
        }

        $absPath = $this->resolver->toAbsolutePath($relPath);
        if (!is_file($absPath)) {
            throw $this->createNotFoundException();
        }

        $mime = match (strtolower(pathinfo($absPath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };

        return new Response(file_get_contents($absPath), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    #[Route('/hide/wbpplog/{id}/{hidden}', name: 'wbpplog_hide')]
    public function wbpplogHide(
        WbppLog $id, EntityManagerInterface $em, $hidden = false
    ): Response
    {
        $id->setHidden($hidden);
        $em->persist($id);
        $em->flush();
        return $this->redirectToRoute('browse_session', ['id' => $id->getSession()->getId()]);
    }

    #[Route('/details/wbpplog/{id}', name: 'wbpplog_detail')]
    public function wbpplogDetail(WbppLog $id): Response
    {
        return $this->render('details/wbpplog.html.twig', [
            'wbpp' => $id
        ]);
    }

    #[Route('/details/wbpplog/{id}/sidebar', name: 'wbpplog_sidebar')]
    public function wbpplogSidebar(WbppLog $id): Response
    {
        return $this->render('details/wbpplog_sidebar.html.twig', [
            'wbpp' => $id
        ]);
    }

    #[Route('/session/delete/{id}', name: 'delete_session', methods: ['POST'])]
    public function deleteTarget(int $id, EntityManagerInterface $em, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $session = $em->getRepository(Session::class)->find($id);

        if (!$session) {
            throw $this->createNotFoundException('Session not found.');
        }

        if (!$this->isCsrfTokenValid('delete_session_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $target = $session->getTarget();

        $em->remove($session);
        $em->flush();

        return $this->redirectToRoute('browse_target', ['id'=> $target->getId()]);
    }
}
