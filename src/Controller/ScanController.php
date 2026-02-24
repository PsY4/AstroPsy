<?php

namespace App\Controller;

use App\Entity\Session;
use App\Entity\Target;
use App\Service\AstropyClient;
use App\Service\FsChangeDetectorService;
use App\Service\PHD2LogsReader;
use App\Service\SessionRefreshService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ScanController extends AbstractController
{
    public function __construct(
        private readonly FsChangeDetectorService $detector,
    ) {}

    #[Route('/api/scan/targets', name: 'api_scan_targets', methods: ['GET'])]
    public function scanTargets(): JsonResponse
    {
        return $this->json($this->detector->detectTargetChanges());
    }

    #[Route('/api/scan/sessions/{targetId}', name: 'api_scan_sessions', methods: ['GET'])]
    public function scanSessions(int $targetId, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($targetId);
        if (!$target) {
            return $this->json(['error' => 'Target not found'], 404);
        }

        return $this->json($this->detector->detectSessionChanges($target));
    }

    #[Route('/api/scan/files/{sessionId}', name: 'api_scan_files', methods: ['GET'])]
    public function scanFiles(int $sessionId, EntityManagerInterface $em): JsonResponse
    {
        $session = $em->getRepository(Session::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        return $this->json($this->detector->detectFileChanges($session));
    }

    #[Route('/api/scan/targets/apply', name: 'api_scan_targets_apply', methods: ['POST'])]
    public function applyTargets(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $add = $data['add'] ?? [];
        $remove = $data['remove'] ?? [];

        $this->detector->applyTargetChanges($add, $remove);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/scan/sessions/{targetId}/apply', name: 'api_scan_sessions_apply', methods: ['POST'])]
    public function applySessions(int $targetId, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $target = $em->getRepository(Target::class)->find($targetId);
        if (!$target) {
            return $this->json(['error' => 'Target not found'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $add = $data['add'] ?? [];
        $remove = $data['remove'] ?? [];

        $this->detector->applySessionChanges($target, $add, $remove);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/scan/files/{sessionId}/apply', name: 'api_scan_files_apply', methods: ['POST'])]
    public function applyFiles(
        int $sessionId,
        EntityManagerInterface $em,
        SessionRefreshService $refreshService,
        AstropyClient $astropyClient,
        PHD2LogsReader $phd2Reader,
    ): JsonResponse {
        $session = $em->getRepository(Session::class)->find($sessionId);
        if (!$session) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        $refreshService->refreshRaws($session, $astropyClient, $em);
        $refreshService->refreshExports($session, $astropyClient, $em);
        $refreshService->refreshMasters($session, $astropyClient, $em);

        return $this->json(['ok' => true]);
    }
}
