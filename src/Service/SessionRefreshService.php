<?php

namespace App\Service;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Session;
use App\Enum\SessionFolder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Finder\Finder;

/**
 * Handles file-system scanning and DB sync for a Session's raws, exports and masters.
 */
class SessionRefreshService
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
        private readonly AstropyClient $astropyClient,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

    public function refreshRaws(Session $session): int
    {
        $exposureRepo = $this->em->getRepository(Exposure::class);
        $count = 0;

        foreach (SessionFolder::rawFolders() as $folder) {
            $currentDir = $this->resolver->resolve($session, $folder);
            if (!is_dir($currentDir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($currentDir)->name($folder->filePattern());

            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if (!$absPath) {
                    continue;
                }
                $filePath = $this->resolver->toRelativePath($absPath);
                $hash = $this->computeHash($absPath);

                /** @var Exposure|null $existing */
                $existing = $exposureRepo->findOneBy(['path' => $filePath, 'session' => $session]);

                if ($existing) {
                    $existing->setHash($hash);
                    continue;
                }

                $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

                try {
                    $headers = $this->extractRawHeaders($absPath, $ext);
                } catch (\Throwable $e) {
                    $this->logger->warning('Scan: failed to parse {path}: {error}', [
                        'path' => $filePath,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                $subdir = strtolower($folder->name);
                $imageTyp = $headers['IMAGETYP'] ?? strtoupper($subdir);

                $dateTaken = $this->parseDateObs($headers['DATE-OBS'] ?? null, $absPath);

                $exposureS = isset($headers['EXPOSURE']) && is_numeric($headers['EXPOSURE'])
                    ? (float) $headers['EXPOSURE']
                    : null;

                $format = in_array($ext, ['fit', 'fits'], true) ? 'FITS' : strtoupper($ext);

                $exposure = new Exposure();
                $exposure->setSession($session)
                    ->setType($imageTyp)
                    ->setHash($hash)
                    ->setPath($filePath)
                    ->setRawHeader($headers)
                    ->setDateTaken($dateTaken)
                    ->setFilterName($headers['FILTER'] ?? null)
                    ->setExposureS($exposureS)
                    ->setSensorTemp($headers['CCD-TEMP'] ?? null)
                    ->setFormat($format);

                $this->em->persist($exposure);
                $count++;
            }
        }

        $this->em->flush();
        return $count;
    }

    public function refreshExports(Session $session): int
    {
        $exportsRepo = $this->em->getRepository(Export::class);
        $exportPath = $this->resolver->resolve($session, SessionFolder::EXPORT);
        $count = 0;

        if (!is_dir($exportPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($exportPath)->name(SessionFolder::EXPORT->filePattern());

        foreach ($finder as $file) {
            $absPath = $file->getRealPath();
            $filePath = $this->resolver->toRelativePath($absPath);
            $hash = $this->computeHash($absPath);
            $extension = strtoupper(pathinfo($absPath, PATHINFO_EXTENSION));

            /** @var Export|null $existing */
            $existing = $exportsRepo->findOneBy(['path' => $filePath, 'session' => $session]);

            if ($existing) {
                $existing->setHash($hash)->setType($extension);
                continue;
            }

            $export = new Export();
            $export->setSession($session)
                ->setHash($hash)
                ->setType($extension)
                ->setPath($filePath);
            $this->em->persist($export);
            $count++;
        }

        $this->em->flush();
        return $count;
    }

    public function refreshMasters(Session $session): int
    {
        $mastersRepo = $this->em->getRepository(Master::class);
        $masterPath = $this->resolver->resolve($session, SessionFolder::MASTER);
        $count = 0;

        if (!is_dir($masterPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($masterPath)->name(SessionFolder::MASTER->filePattern());

        foreach ($finder as $file) {
            $absPath = $file->getRealPath();
            $filePath = $this->resolver->toRelativePath($absPath);
            $hash = $this->computeHash($absPath);
            $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

            /** @var Master|null $existing */
            $existing = $mastersRepo->findOneBy(['path' => $filePath, 'session' => $session]);

            if ($existing) {
                if ($existing->getHeader() == []) {
                    try {
                        $existing->setHeader($this->extractMasterHeaders($absPath, $ext));
                    } catch (\Throwable $e) {
                        $this->logger->warning('Scan: failed to parse master {path}: {error}', [
                            'path' => $filePath,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                continue;
            }

            $headers = [];
            try {
                $headers = $this->extractMasterHeaders($absPath, $ext);
            } catch (\Throwable $e) {
                $this->logger->warning('Scan: failed to parse master {path}: {error}', [
                    'path' => $filePath,
                    'error' => $e->getMessage(),
                ]);
            }

            $master = new Master();
            $master->setSession($session)
                ->setHeader($headers)
                ->setHash($hash)
                ->setType(strtoupper($ext))
                ->setPath($filePath);
            $this->em->persist($master);
            $count++;
        }

        $this->em->flush();
        return $count;
    }

    /**
     * Compute a stable hash for deduplication based on file size and path.
     */
    private function computeHash(string $absPath): string
    {
        $stat = @stat($absPath);
        $size = $stat ? $stat['size'] : 0;

        return md5(sprintf('%d-%s', $size, $absPath));
    }

    /**
     * Extract headers from a raw file (FITS or NEF) via the Python microservice.
     */
    private function extractRawHeaders(string $absPath, string $ext): array
    {
        if (in_array($ext, ['fit', 'fits'], true)) {
            return $this->astropyClient->fitsHeader($absPath);
        }

        if ($ext === 'nef') {
            return $this->astropyClient->nefHeader($absPath);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported raw format: .%s', $ext));
    }

    /**
     * Extract headers from a master file (XISF or FITS) via the Python microservice.
     */
    private function extractMasterHeaders(string $absPath, string $ext): array
    {
        if ($ext === 'xisf') {
            return $this->astropyClient->xisfHeader($absPath);
        }

        if (in_array($ext, ['fit', 'fits'], true)) {
            return $this->astropyClient->fitsHeader($absPath);
        }

        throw new \InvalidArgumentException(sprintf('Unsupported master format: .%s', $ext));
    }

    /**
     * Parse DATE-OBS string into DateTimeImmutable, with fallback to file mtime.
     */
    private function parseDateObs(?string $dateObsStr, string $absPath): ?\DateTimeImmutable
    {
        if ($dateObsStr) {
            try {
                return new \DateTimeImmutable($dateObsStr);
            } catch (\Exception) {
                // fall through to mtime
            }
        }

        $stat = @stat($absPath);
        return $stat ? (new \DateTimeImmutable())->setTimestamp($stat['mtime']) : null;
    }
}
