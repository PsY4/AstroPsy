<?php
namespace App\Service;

use App\Entity\Notification;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function createEveningAlert(array $weather, string $obsName, array $targets): Notification
    {
        $count = count($targets);
        $title = $count > 0
            ? sprintf('Bonne nuit ce soir — %d cible%s', $count, $count > 1 ? 's' : '')
            : 'Conditions favorables ce soir';

        $summary = sprintf(
            'Météo favorable · Nuages %d%% · Vent %.1f m/s · %d cible%s disponible%s',
            $weather['cloud_avg'] ?? 0,
            $weather['wind_max'] ?? 0,
            $count,
            $count > 1 ? 's' : '',
            $count > 1 ? 's' : ''
        );

        $targetPayloads = array_map(fn($d) => [
            'name'         => $d['target']->getName(),
            'type'         => $d['target']->getType(),
            'useful_h'     => round($d['usefulH'] ?? 0, 1),
            'window_start' => $d['windowStart'] ?? null,
            'window_end'   => $d['windowEnd']   ?? null,
            'moon_sep'     => isset($d['minSep']) ? round($d['minSep']) : null,
        ], $targets);

        $notif = new Notification();
        $notif->setType('evening_alert');
        $notif->setTitle($title);
        $notif->setSummary($summary);
        $notif->setCreatedAt(new \DateTimeImmutable());
        $notif->setData([
            'weather'  => $weather,
            'obs_name' => $obsName,
            'targets'  => $targetPayloads,
        ]);

        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }

    public function createMigrationReport(int $migrated, int $errors, int $skipped): Notification
    {
        $title = sprintf('Migration complete — %d migrated, %d errors, %d skipped', $migrated, $errors, $skipped);
        $summary = $errors > 0
            ? sprintf('%d session(s) migrated with %d error(s). Check logs for details.', $migrated, $errors)
            : sprintf('%d session(s) migrated successfully. %d skipped.', $migrated, $skipped);

        $notif = new Notification();
        $notif->setType('migration_report');
        $notif->setTitle($title);
        $notif->setSummary($summary);
        $notif->setCreatedAt(new \DateTimeImmutable());
        $notif->setData([
            'migrated' => $migrated,
            'errors'   => $errors,
            'skipped'  => $skipped,
        ]);

        $this->em->persist($notif);
        $this->em->flush();

        return $notif;
    }

    public function markAsRead(int $id): void
    {
        $notif = $this->em->find(Notification::class, $id);
        if ($notif && !$notif->isRead()) {
            $notif->setReadAt(new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    public function markAllAsRead(): void
    {
        $this->em->createQuery(
            'UPDATE App\Entity\Notification n SET n.readAt = :now WHERE n.readAt IS NULL'
        )->execute(['now' => new \DateTimeImmutable()]);
    }

    public function getUnreadCount(): int
    {
        return (int) $this->em->createQuery(
            'SELECT COUNT(n.id) FROM App\Entity\Notification n WHERE n.readAt IS NULL'
        )->getSingleScalarResult();
    }

    /** @return Notification[] */
    public function getRecent(int $limit = 50): array
    {
        return $this->em->createQuery(
            'SELECT n FROM App\Entity\Notification n ORDER BY n.createdAt DESC'
        )->setMaxResults($limit)->getResult();
    }
}
