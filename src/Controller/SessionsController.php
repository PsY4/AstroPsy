<?php

namespace App\Controller;

use App\Repository\SessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SessionsController extends AbstractController
{
    #[Route('/sessions', name: 'sessions')]
    public function index(SessionRepository $sessionRepo): Response
    {
        $sessions = $sessionRepo->findForCalendar();

        $sessionsJson = json_encode(array_map(fn($s) => [
            'id'          => $s['id'],
            'date'        => $s['startedAt']->format('Y-m-d'),
            'target_name' => $s['target_name'],
            'target_id'   => (int) $s['target_id'],
            'target_type' => $s['target_type'] ?? '',
        ], $sessions), JSON_UNESCAPED_UNICODE);

        return $this->render('browse/sessions.html.twig', [
            'sessionsJson' => $sessionsJson,
            'count'        => count($sessions),
        ]);
    }
}
