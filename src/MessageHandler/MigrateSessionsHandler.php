<?php

namespace App\MessageHandler;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Message\MigrateSessionsMessage;
use App\Service\AppConfig;
use App\Service\NotificationService;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class MigrateSessionsHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StoragePathResolver $resolver,
        private readonly NotificationService $notificationService,
    ) {}

    public function __invoke(MigrateSessionsMessage $message): void
    {
        $template = $message->template;
        $filesystem = new Filesystem();

        $sessions = $this->em->getRepository(Session::class)->findAll();

        $migrated = 0;
        $errors   = 0;
        $skipped  = 0;

        // Build role→path map from the NEW template
        $newRoleMap = [];
        $this->buildRoleMap($template['tree'] ?? [], '', $newRoleMap);

        foreach ($sessions as $session) {
            $sessionPath = $this->resolver->toAbsolutePath($session->getPath());
            if (!$sessionPath || !is_dir($sessionPath)) {
                $skipped++;
                continue;
            }

            $sessionMoved = false;
            $movedFiles   = [];

            try {
                foreach (SessionFolder::cases() as $folder) {
                    $newRelPath = $newRoleMap[$folder->value] ?? $folder->defaultRelativePath();
                    $oldDir     = Path::normalize($sessionPath . '/' . $folder->defaultRelativePath());
                    $newDir     = Path::normalize($sessionPath . '/' . $newRelPath);

                    if ($oldDir === $newDir) {
                        continue;
                    }

                    if (!is_dir($oldDir)) {
                        // Just create the new dir
                        $filesystem->mkdir($newDir);
                        continue;
                    }

                    $filesystem->mkdir($newDir);

                    // Move files
                    $files = @scandir($oldDir);
                    if ($files === false) {
                        continue;
                    }

                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') {
                            continue;
                        }
                        $src = $oldDir . '/' . $f;
                        $dst = $newDir . '/' . $f;

                        if (file_exists($dst)) {
                            continue; // skip conflicts
                        }

                        rename($src, $dst);
                        $movedFiles[] = ['src' => $src, 'dst' => $dst];

                        // Update DB paths (relative)
                        $this->updateDbPaths(
                            $this->resolver->toRelativePath($src),
                            $this->resolver->toRelativePath($dst)
                        );
                    }

                    $sessionMoved = true;

                    // Remove old dir if empty
                    $this->removeEmptyDirs($oldDir);
                }

                // Create any new dirs from template that don't exist yet
                $allPaths = [];
                $this->collectPaths($template['tree'] ?? [], '', $allPaths);
                foreach ($allPaths as $rel) {
                    $abs = Path::normalize($sessionPath . '/' . $rel);
                    if (!is_dir($abs)) {
                        $filesystem->mkdir($abs);
                    }
                }

                if ($sessionMoved) {
                    $migrated++;
                }

                $this->em->flush();
            } catch (\Throwable $e) {
                $errors++;

                // Rollback moved files
                foreach (array_reverse($movedFiles) as $move) {
                    if (file_exists($move['dst']) && !file_exists($move['src'])) {
                        @rename($move['dst'], $move['src']);
                        $this->updateDbPaths(
                            $this->resolver->toRelativePath($move['dst']),
                            $this->resolver->toRelativePath($move['src'])
                        );
                    }
                }

                $this->em->flush();
            }
        }

        if (!$sessionMoved && $migrated === 0 && $errors === 0) {
            $skipped = count($sessions);
        }

        $this->notificationService->createMigrationReport($migrated, $errors, $skipped);
    }

    private function updateDbPaths(string $oldPath, string $newPath): void
    {
        // Update Exposure paths
        $this->em->createQuery(
            'UPDATE App\Entity\Exposure e SET e.path = :new WHERE e.path = :old'
        )->execute(['old' => $oldPath, 'new' => $newPath]);

        // Update Master paths
        $this->em->createQuery(
            'UPDATE App\Entity\Master m SET m.path = :new WHERE m.path = :old'
        )->execute(['old' => $oldPath, 'new' => $newPath]);

        // Update Export paths
        $this->em->createQuery(
            'UPDATE App\Entity\Export e SET e.path = :new WHERE e.path = :old'
        )->execute(['old' => $oldPath, 'new' => $newPath]);
    }

    private function removeEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if ($items === false) {
            return;
        }
        $items = array_diff($items, ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeEmptyDirs($path);
            }
        }
        // Re-scan after removing children
        $items = @scandir($dir);
        if ($items !== false && count(array_diff($items, ['.', '..'])) === 0) {
            @rmdir($dir);
        }
    }

    private function buildRoleMap(array $nodes, string $prefix, array &$map): void
    {
        foreach ($nodes as $node) {
            $name = $node['name'] ?? '';
            $path = $prefix !== '' ? $prefix . '/' . $name : $name;
            if (isset($node['role']) && $node['role'] !== '') {
                $map[$node['role']] = $path;
            }
            if (!empty($node['children'])) {
                $this->buildRoleMap($node['children'], $path, $map);
            }
        }
    }

    private function collectPaths(array $nodes, string $prefix, array &$paths): void
    {
        foreach ($nodes as $node) {
            $name = $node['name'] ?? '';
            $path = $prefix !== '' ? $prefix . '/' . $name : $name;
            $paths[] = $path;
            if (!empty($node['children'])) {
                $this->collectPaths($node['children'], $path, $paths);
            }
        }
    }
}
