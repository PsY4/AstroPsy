<?php

namespace App\Controller;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Observatory;
use App\Entity\Session;
use App\Entity\Target;
use App\Entity\TargetGoal;
use App\Form\TargetType;
use App\Repository\DocRepository;
use App\Repository\ObservatoryRepository;
use App\Service\AstrobinAPIService;
use App\Service\FilterNormalizer;
use App\Service\ProgressTrackingService;
use App\Service\StoragePathResolver;
use App\Service\TelescopiusAPIService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TargetController extends AbstractController
{
    public function __construct(private FilterNormalizer $normalizer, private ProgressTrackingService $progress, private StoragePathResolver $resolver) {}

    #[Route('/target/{id<\d+>}', name: 'browse_target')]
    public function browseTarget(int $id, Request $request, EntityManagerInterface $em, AstrobinAPIService $astrobin, ObservatoryRepository $obsRepo, DocRepository $docsRepo): Response
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            throw $this->createNotFoundException();
        }

        $editForm = $this->createForm(TargetType::class, $target);
        $editForm->handleRequest($request);
        if ($editForm->isSubmitted() && $editForm->isValid()) {
            /** @var UploadedFile|null $previewFile */
            $previewFile = $editForm->get('previewImage')->getData();
            if ($previewFile instanceof UploadedFile && $target->getPath()) {
                $this->savePreviewImage(
                    file_get_contents($previewFile->getPathname()),
                    $this->resolver->toAbsolutePath($target->getPath())
                );
            }
            $em->persist($target);
            $em->flush();
            return $this->redirectToRoute('browse_target', ['id' => $target->getId()]);
        }

        $favoriteObs = $obsRepo->findOneBy(['favorite' => true]);

        // Progress Tracker — accumulated seconds per filter (LIGHT only, sessions non exclues)
        // Normalize raw filter names to canonical forms and merge duplicates
        $accumulatedRaw = $this->progress->getAccumulatedForTarget($target);
        $accumulated = [];
        foreach ($accumulatedRaw as $filterName => $totalSeconds) {
            $canonical = $this->normalizer->normalize($filterName);
            $accumulated[$canonical] = ($accumulated[$canonical] ?? 0.0) + $totalSeconds;
        }

        $goals = [];
        foreach ($em->getRepository(TargetGoal::class)->findBy(['target' => $target]) as $g) {
            $canonical = $this->normalizer->normalize($g->getFilterName());
            $goals[$canonical] = $g;
        }

        $allFilters = $this->normalizer->sort(
            array_unique(array_merge(array_keys($accumulated), array_keys($goals)))
        );

        return $this->render('browse/target.html.twig', [
            'target'           => $target,
            'editForm'         => $editForm->createView(),
            'favoriteObs'      => $favoriteObs,
            'accumulated'      => $accumulated,
            'goals'            => $goals,
            'allFilters'       => $allFilters,
            'canonicalFilters' => $this->normalizer->getCanonical(),
            'filterColorMap'   => $this->normalizer->getColorMap(),
            'filtersJsConfig'  => $this->normalizer->getJsConfig(),
            'docsRepo'         => $docsRepo,
        ]);
    }

    #[Route('/target/new', name: 'new_target')]
    public function newTarget(Request $request, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $targetName = trim($request->request->get('targetName'));
        $targetPath = Path::normalize($this->resolver->getSessionsRoot() . '/' . $targetName);

        // Check if folder already exists (use absolute path for FS)
        if (is_dir($targetPath)) {
            $this->addFlash('warning', $translator->trans('flash.target_exists', ['%name%' => $targetName]));
            // Find the existing target if you want to redirect to it
            $existingTarget = $em->getRepository(Target::class)->findOneBy(['name' => $targetName]);
            if ($existingTarget) {
                return $this->redirectToRoute('app_target_refresh_about', ['target' => $existingTarget->getId()]);
            }
            // Otherwise just redirect to targets list
            return $this->redirectToRoute('browse_targets');
        }

        // Create the directory
        $filesystem = new Filesystem();
        try {
            $filesystem->mkdir($targetPath);
        } catch (IOExceptionInterface $exception) {
            throw new \RuntimeException("An error occurred while creating the directory at " . $exception->getPath(), 0, $exception);
        }

        // Create the entity only if folder creation succeeded
        $newTarget = new Target();
        $newTarget
            ->setName($targetName)
            ->setPath($targetName);
        $em->persist($newTarget);
        $em->flush();

        $this->addFlash('success', $translator->trans('flash.target_created', ['%name%' => $targetName]));

        return $this->redirectToRoute('app_target_refresh_about', ['target' => $newTarget->getId()]);
    }


    #[Route('/target/refresh/{id}', name: 'refresh_target')]
    public function refreshTarget(int $id, EntityManagerInterface $em, TranslatorInterface $translator): Response
    {
        $target = $em->getRepository(Target::class)->find($id);

        $root = $this->resolver->toAbsolutePath($target->getPath());

        if (!is_dir($root)) {
            $this->addFlash('danger', $translator->trans('flash.sessions_root_not_found', ['%path%' => $root]));
            return $this->redirectToRoute('browse_targets');
        }

        $sessionsRepo = $em->getRepository(Session::class);

        // Index existing sessions by path for quick lookup
        /** @var Session[] $existingSessions */
        $existingSessions = $sessionsRepo->findBy(['target' => $target->getId()]);
        $byPath = [];
        foreach ($existingSessions as $s) {
            $byPath[$s->getPath()] = $s;
        }
        $foundPaths = [];


        $finder = new Finder();
        $finder
            ->in($root)
            ->depth('== 0')
            ->directories()
            ->ignoreDotFiles(true);

        foreach ($finder as $dir) {
            $name = $dir->getBasename();
            $relPath = $target->getPath() . '/' . $name;

            $key = $relPath;
            $foundPaths[$key] = true;

            if (!isset($byPath[$key])) {
                // Create new session
                $session = new Session();
                $session
                    ->setTarget($target)
                    ->setStartedAt(new \DateTime(explode("_",$name)[0]))
                    ->setPath($relPath)
                ;
                $em->persist($session);
                $byPath[$key] = $session;
            }
        }

        // Clean up sessions that no longer exist on disk
        foreach ($existingSessions as $s) {
            $k = $s->getPath();
            if (!isset($foundPaths[$k])) {
                // Folder is gone => remove session
                $em->remove($s);
            }
        }
        $em->flush();

        return $this->redirectToRoute('browse_target', ['id' => $id]);
    }

    #[Route('/target/delete/{id}', name: 'delete_target', methods: ['POST'])]
    public function deleteTarget(int $id, EntityManagerInterface $em, \Symfony\Component\HttpFoundation\Request $request): Response
    {
        $target = $em->getRepository(Target::class)->find($id);

        if (!$target) {
            throw $this->createNotFoundException('Target not found.');
        }

        if (!$this->isCsrfTokenValid('delete_target_' . $id, $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $filesystem = new Filesystem();

        if ($target->getPath()) {
            $targetPath = $this->resolver->toAbsolutePath($target->getPath());
            try {
                if ($filesystem->exists($targetPath)) {
                    $filesystem->remove($targetPath);
                }
            } catch (IOExceptionInterface $exception) {
                throw new \RuntimeException("An error occurred while deleting the directory at " . $exception->getPath(), 0, $exception);
            }
        }

        $em->remove($target);
        $em->flush();

        return $this->redirectToRoute('browse_targets');
    }

    #[Route('/target-refresh-about/{target}', name: 'app_target_refresh_about')]
    public function index(
        Target $target,
        Request $request,
        TelescopiusAPIService  $telescopiusAPI,
        EntityManagerInterface $entityManager
    ): Response
    {
        $searchName  = trim($request->query->get('searchName', '') ?: $target->getName());
        $targetAbout = $telescopiusAPI->getTargetAbout($searchName, $target->getCatalogIds()[0]??"");
        if ($targetAbout !== false) {
            $target
                ->setName($targetAbout['main_id'] . " - " . $targetAbout['main_name'])
                ->setCatalogIds($targetAbout['ids'])
                ->setRa($targetAbout['ra'])
                ->setDec($targetAbout['dec'])
                ->setConstellation($targetAbout['con_name'])
                ->setThumbnailUrl($targetAbout['thumbnail_url'])
                ->setTelescopiusUrl($targetAbout['url'])
                ->setType(implode(",", $targetAbout['types']))
            ;

            if (isset($targetAbout['visual_mag'])) $target->setVisualMag($targetAbout['visual_mag']);
            $entityManager->flush();

            if ($target->getPath() && !empty($targetAbout['thumbnail_url'])) {
                $imageData = @file_get_contents($targetAbout['thumbnail_url']);
                if ($imageData !== false) {
                    $this->savePreviewImage($imageData, $this->resolver->toAbsolutePath($target->getPath()));
                }
            }
        }
        return $this->redirectToRoute('browse_target', ['id' => $target->getId()]);
    }

    #[Route('/target/{id<\d+>}/preview', name: 'target_preview', methods: ['GET'])]
    public function preview(int $id, EntityManagerInterface $em): Response
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            throw $this->createNotFoundException();
        }

        $previewPath = rtrim($this->resolver->toAbsolutePath((string) $target->getPath()), '/') . '/preview.jpg';
        if (file_exists($previewPath)) {
            return new BinaryFileResponse($previewPath, 200, [
                'Content-Type'  => 'image/jpeg',
                'Cache-Control' => 'max-age=86400, public',
            ]);
        }

        if ($target->getThumbnailUrl()) {
            return new RedirectResponse($target->getThumbnailUrl());
        }

        throw $this->createNotFoundException('No preview available');
    }

    #[Route('/target/{id}/goal', name: 'target_goal_save', methods: ['POST'])]
    public function saveGoal(int $id, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($id);
        if (!$target) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $filterName  = $this->normalizer->normalize(trim((string) $request->request->get('filter', '')));
        $goalSeconds = (int) round((float) $request->request->get('hours', 0) * 3600);

        if ($filterName === '') {
            return new JsonResponse(['error' => 'Missing filter'], 400);
        }

        $goal = $em->getRepository(TargetGoal::class)->findOneBy(['target' => $target, 'filterName' => $filterName]);

        if ($goalSeconds <= 0) {
            if ($goal) {
                $em->remove($goal);
            }
        } else {
            if (!$goal) {
                $goal = (new TargetGoal())->setTarget($target)->setFilterName($filterName);
            }
            $goal->setGoalSeconds($goalSeconds);
            $em->persist($goal);
        }

        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/export/{id}/set-as-preview', name: 'export_set_as_preview', methods: ['POST'])]
    public function setExportAsPreview(int $id, EntityManagerInterface $em): JsonResponse
    {
        $export = $em->getRepository(Export::class)->find($id);
        if (!$export) {
            return new JsonResponse(['error' => 'Export not found'], 404);
        }

        $target = $export->getSession()?->getTarget();
        if (!$target || !$target->getPath()) {
            return new JsonResponse(['error' => 'Target path unavailable'], 404);
        }

        $imageData = @file_get_contents($this->resolver->toAbsolutePath($export->getPath()));
        if ($imageData === false) {
            return new JsonResponse(['error' => 'Cannot read export file'], 500);
        }

        $this->savePreviewImage($imageData, $this->resolver->toAbsolutePath($target->getPath()));

        return new JsonResponse(['success' => true]);
    }

    private function savePreviewImage(string $imageData, string $folderPath, int $maxWidth = 800): void
    {
        $source = @\imagecreatefromstring($imageData);
        if ($source === false) {
            return;
        }

        $origW = \imagesx($source);
        $origH = \imagesy($source);

        if ($origW > $maxWidth) {
            $newH    = (int) round($origH * $maxWidth / $origW);
            $resized = \imagescale($source, $maxWidth, $newH, IMG_BICUBIC);
            if ($resized === false) {
                \imagedestroy($source);
                return;
            }
        } else {
            $resized = $source;
        }

        \imagejpeg($resized, $folderPath . '/preview.jpg', 85);
        \imagedestroy($source);
        if ($resized !== $source) {
            \imagedestroy($resized);
        }
    }

    #[Route('/target/{target}/get-nina-sequence', name: 'app_target_get_nina_sequence')]
    public function generateNinaSequence(
        Target $target,
        EntityManagerInterface $em,
        Environment $twig
    ): Response {
        // 1) Render the JSON from Twig
        $json = $twig->render('generative/nina/sequence.json.twig', [
            'target' => $target,
        ]);

        // 2) Build a nice, safe filename
        $slug = preg_replace('~[^a-z0-9]+~i', '-', (string) $target->getName());
        $slug = trim($slug, '-');
        $filename = sprintf('NINA-%s-%s.sequence.json', $slug, (new \DateTimeImmutable())->format('Ymd_His'));

        // 3) Return as a downloadable response
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('Content-Length', (string) strlen($json));

        return $response;
    }
}
