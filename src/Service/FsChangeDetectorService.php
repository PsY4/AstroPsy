<?php

namespace App\Service;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Session;
use App\Entity\Target;
use App\Enum\SessionFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

class FsChangeDetectorService
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Compare FS directories vs Target entities in DB (read-only).
     * @return array{new: array<array{name: string, path: string}>, missing: array<array{name: string, id: int, path: string}>}
     */
    public function detectTargetChanges(): array
    {
        $root = $this->resolver->getSessionsRoot();
        $result = ['new' => [], 'missing' => []];

        if (!is_dir($root)) {
            return $result;
        }

        $existingTargets = $this->em->getRepository(Target::class)->findAll();
        $byPath = [];
        foreach ($existingTargets as $t) {
            $byPath[$t->getPath()] = $t;
        }

        $foundPaths = [];

        $finder = new Finder();
        $finder->in($root)->depth('== 0')->directories()->ignoreDotFiles(true);

        foreach ($finder as $dir) {
            $name = $dir->getBasename();
            if ($name === '--DOCUMENTS--') {
                continue;
            }
            // Use relative path (just the directory name for targets)
            $relPath = $name;
            $foundPaths[$relPath] = true;

            if (!isset($byPath[$relPath])) {
                $result['new'][] = ['name' => $name, 'path' => $relPath];
            }
        }

        foreach ($existingTargets as $t) {
            if (!isset($foundPaths[$t->getPath()])) {
                $result['missing'][] = ['name' => $t->getName(), 'id' => $t->getId(), 'path' => $t->getPath()];
            }
        }

        return $result;
    }

    /**
     * Compare FS directories vs Session entities for a target (read-only).
     * @return array{new: array<array{name: string, path: string}>, missing: array<array{name: string, id: int, path: string}>}
     */
    public function detectSessionChanges(Target $target): array
    {
        $root = $this->resolver->toAbsolutePath($target->getPath());
        $result = ['new' => [], 'missing' => []];

        if (!$root || !is_dir($root)) {
            return $result;
        }

        $existingSessions = $this->em->getRepository(Session::class)->findBy(['target' => $target->getId()]);
        $byPath = [];
        foreach ($existingSessions as $s) {
            $byPath[$s->getPath()] = $s;
        }

        $foundPaths = [];

        $finder = new Finder();
        $finder->in($root)->depth('== 0')->directories()->ignoreDotFiles(true);

        foreach ($finder as $dir) {
            $name = $dir->getBasename();
            $relPath = $target->getPath() . '/' . $name;
            $foundPaths[$relPath] = true;

            if (!isset($byPath[$relPath])) {
                $result['new'][] = ['name' => $name, 'path' => $relPath];
            }
        }

        foreach ($existingSessions as $s) {
            if (!isset($foundPaths[$s->getPath()])) {
                $name = basename($s->getPath());
                $result['missing'][] = ['name' => $name, 'id' => $s->getId(), 'path' => $s->getPath()];
            }
        }

        return $result;
    }

    /**
     * Compare FS files vs Exposure/Master/Export entities for a session (read-only).
     * Returns counts per role — no header parsing.
     * @return array{new: array<array{role: string, count: int}>, missing: array<array{role: string, count: int}>}
     */
    public function detectFileChanges(Session $session): array
    {
        $result = ['new' => [], 'missing' => []];

        // Build a set of ALL exposure paths in DB (type-agnostic)
        $allExposurePaths = [];
        foreach ($this->em->getRepository(Exposure::class)->findBy(['session' => $session]) as $e) {
            $allExposurePaths[$e->getPath()] = true;
        }

        foreach (SessionFolder::rawFolders() as $folder) {
            $label = $folder->name;
            $dir = $this->resolver->resolve($session, $folder);
            if (!is_dir($dir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($dir)->name($folder->filePattern());

            $newCount = 0;
            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if ($absPath) {
                    $relPath = $this->resolver->toRelativePath($absPath);
                    if (!isset($allExposurePaths[$relPath])) {
                        $newCount++;
                    }
                }
            }

            if ($newCount > 0) {
                $result['new'][] = ['role' => $label, 'count' => $newCount];
            }
        }

        // Masters
        $masterDir = $this->resolver->resolve($session, SessionFolder::MASTER);
        if (is_dir($masterDir)) {
            $finder = new Finder();
            $finder->files()->in($masterDir)->name(SessionFolder::MASTER->filePattern());

            $dbPaths = [];
            foreach ($this->em->getRepository(Master::class)->findBy(['session' => $session]) as $m) {
                $dbPaths[$m->getPath()] = true;
            }

            $newCount = 0;
            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if ($absPath) {
                    $relPath = $this->resolver->toRelativePath($absPath);
                    if (!isset($dbPaths[$relPath])) {
                        $newCount++;
                    }
                }
            }
            if ($newCount > 0) {
                $result['new'][] = ['role' => 'MASTER', 'count' => $newCount];
            }
        }

        // Exports
        $exportDir = $this->resolver->resolve($session, SessionFolder::EXPORT);
        if (is_dir($exportDir)) {
            $finder = new Finder();
            $finder->files()->in($exportDir)->name(SessionFolder::EXPORT->filePattern());

            $dbPaths = [];
            foreach ($this->em->getRepository(Export::class)->findBy(['session' => $session]) as $e) {
                $dbPaths[$e->getPath()] = true;
            }

            $newCount = 0;
            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if ($absPath) {
                    $relPath = $this->resolver->toRelativePath($absPath);
                    if (!isset($dbPaths[$relPath])) {
                        $newCount++;
                    }
                }
            }
            if ($newCount > 0) {
                $result['new'][] = ['role' => 'EXPORT', 'count' => $newCount];
            }
        }

        return $result;
    }

    /**
     * Apply target changes: create new targets, remove missing ones.
     * @param string[] $addPaths  relative paths (directory names)
     * @param int[] $removeIds
     */
    public function applyTargetChanges(array $addPaths, array $removeIds): void
    {
        foreach ($addPaths as $path) {
            $target = new Target();
            $target->setName(basename($path));
            $target->setPath($path);
            $this->em->persist($target);
        }

        foreach ($removeIds as $id) {
            $target = $this->em->find(Target::class, $id);
            if ($target) {
                $this->em->remove($target);
            }
        }

        $this->em->flush();
    }

    /**
     * Apply session changes: create new sessions, remove missing ones.
     * @param string[] $addPaths  relative paths
     * @param int[] $removeIds
     */
    public function applySessionChanges(Target $target, array $addPaths, array $removeIds): void
    {
        foreach ($addPaths as $path) {
            $name = basename($path);
            $session = new Session();
            $session->setTarget($target);
            $session->setPath($path);
            try {
                $session->setStartedAt(new \DateTime(explode('_', $name)[0]));
            } catch (\Exception) {
                $session->setStartedAt(new \DateTime());
            }
            $this->em->persist($session);
        }

        foreach ($removeIds as $id) {
            $session = $this->em->find(Session::class, $id);
            if ($session) {
                $this->em->remove($session);
            }
        }

        $this->em->flush();
    }
}
