<?php
namespace App\Controller;

use App\Entity\Notification;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationService    $notificationService,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/notifications', name: 'notifications_index', methods: ['GET'])]
    public function index(): Response
    {
        $notifications = $this->notificationService->getRecent(50);

        return $this->render('notifications/index.html.twig', [
            'notifications' => $notifications,
            'pageTitle'     => 'Notifications',
        ]);
    }

    #[Route('/api/notifications', name: 'api_notifications', methods: ['GET'])]
    public function apiList(): Response
    {
        $notifications = $this->notificationService->getRecent(50);

        return $this->json(array_map(fn(Notification $n) => [
            'id'         => $n->getId(),
            'type'       => $n->getType(),
            'title'      => $n->getTitle(),
            'summary'    => $n->getSummary(),
            'data'       => $n->getData(),
            'created_at' => $n->getCreatedAt()->format('c'),
            'read'       => $n->isRead(),
        ], $notifications));
    }

    #[Route('/api/notifications/unread-count', name: 'api_notifications_unread_count', methods: ['GET'])]
    public function apiUnreadCount(): Response
    {
        return $this->json(['count' => $this->notificationService->getUnreadCount()]);
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notification_read', methods: ['POST'])]
    public function apiMarkRead(int $id): Response
    {
        $this->notificationService->markAsRead($id);
        return $this->json(['ok' => true]);
    }

    #[Route('/api/notifications/read-all', name: 'api_notifications_read_all', methods: ['POST'])]
    public function apiMarkAllRead(): Response
    {
        $this->notificationService->markAllAsRead();
        return $this->json(['ok' => true]);
    }
}
