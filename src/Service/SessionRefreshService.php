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

                $format = in_array($ext, self::FITS_EXTENSIONS, true) ? 'FITS' : strtoupper($ext);

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
     * List all files on disk for a given step.
     *
     * @return list<array{path: string, name: string, folder: string}>
     */
    public function listFilesForStep(Session $session, string $step): array
    {
        $folders = match ($step) {
            'raws'    => SessionFolder::rawFolders(),
            'masters' => [SessionFolder::MASTER],
            'exports' => [SessionFolder::EXPORT],
            default   => throw new \InvalidArgumentException("Unknown step: $step"),
        };

        $files = [];
        foreach ($folders as $folder) {
            $dir = $this->resolver->resolve($session, $folder);
            if (!is_dir($dir)) {
                continue;
            }
            $finder = new Finder();
            $finder->files()->in($dir)->name($folder->filePattern());
            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if (!$absPath) {
                    continue;
                }
                $files[] = [
                    'path'   => $this->resolver->toRelativePath($absPath),
                    'name'   => $file->getFilename(),
                    'folder' => strtolower($folder->name),
                ];
            }
        }

        return $files;
    }

    /**
     * Process a single raw file (FITS or camera RAW).
     *
     * @return array{status: string}
     */
    public function processSingleRaw(Session $session, string $relPath, string $folderHint = 'light'): array
    {
        $absPath = $this->resolver->toAbsolutePath($relPath);
        $hash = $this->computeHash($absPath);

        $existing = $this->em->getRepository(Exposure::class)
            ->findOneBy(['path' => $relPath, 'session' => $session]);

        if ($existing) {
            $existing->setHash($hash);
            $this->em->flush();
            return ['status' => 'updated'];
        }

        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $headers = $this->extractRawHeaders($absPath, $ext);

        $imageTyp = $headers['IMAGETYP'] ?? strtoupper($folderHint);
        $dateTaken = $this->parseDateObs($headers['DATE-OBS'] ?? null, $absPath);
        $exposureS = isset($headers['EXPOSURE']) && is_numeric($headers['EXPOSURE'])
            ? (float) $headers['EXPOSURE']
            : null;
        $format = in_array($ext, self::FITS_EXTENSIONS, true) ? 'FITS' : strtoupper($ext);

        $exposure = new Exposure();
        $exposure->setSession($session)
            ->setType($imageTyp)
            ->setHash($hash)
            ->setPath($relPath)
            ->setRawHeader($headers)
            ->setDateTaken($dateTaken)
            ->setFilterName($headers['FILTER'] ?? null)
            ->setExposureS($exposureS)
            ->setSensorTemp($headers['CCD-TEMP'] ?? null)
            ->setFormat($format);

        $this->em->persist($exposure);
        $this->em->flush();

        return ['status' => 'created'];
    }

    /**
     * Process a single master file (XISF or FITS).
     *
     * @return array{status: string}
     */
    public function processSingleMaster(Session $session, string $relPath): array
    {
        $absPath = $this->resolver->toAbsolutePath($relPath);
        $hash = $this->computeHash($absPath);
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));

        $existing = $this->em->getRepository(Master::class)
            ->findOneBy(['path' => $relPath, 'session' => $session]);

        if ($existing) {
            if ($existing->getHeader() == []) {
                try {
                    $existing->setHeader($this->extractMasterHeaders($absPath, $ext));
                } catch (\Throwable $e) {
                    $this->logger->warning('Scan: failed to parse master {path}: {error}', [
                        'path' => $relPath, 'error' => $e->getMessage(),
                    ]);
                }
            }
            $this->em->flush();
            return ['status' => 'updated'];
        }

        $headers = [];
        try {
            $headers = $this->extractMasterHeaders($absPath, $ext);
        } catch (\Throwable $e) {
            $this->logger->warning('Scan: failed to parse master {path}: {error}', [
                'path' => $relPath, 'error' => $e->getMessage(),
            ]);
        }

        $master = new Master();
        $master->setSession($session)
            ->setHeader($headers)
            ->setHash($hash)
            ->setType(strtoupper($ext))
            ->setPath($relPath);
        $this->em->persist($master);
        $this->em->flush();

        return ['status' => 'created'];
    }

    /**
     * Process a single export file.
     *
     * @return array{status: string}
     */
    public function processSingleExport(Session $session, string $relPath): array
    {
        $absPath = $this->resolver->toAbsolutePath($relPath);
        $hash = $this->computeHash($absPath);
        $ext = strtoupper(pathinfo($absPath, PATHINFO_EXTENSION));

        $existing = $this->em->getRepository(Export::class)
            ->findOneBy(['path' => $relPath, 'session' => $session]);

        if ($existing) {
            $existing->setHash($hash)->setType($ext);
            $this->em->flush();
            return ['status' => 'updated'];
        }

        $export = new Export();
        $export->setSession($session)
            ->setHash($hash)
            ->setType($ext)
            ->setPath($relPath);
        $this->em->persist($export);
        $this->em->flush();

        return ['status' => 'created'];
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

    /** FITS extensions (parsed by astropy). */
    private const FITS_EXTENSIONS = ['fit', 'fits'];

    /** Camera RAW extensions (parsed by rawpy/exifread). */
    private const RAW_EXTENSIONS = ['nef', 'cr2', 'cr3', 'arw', 'orf', 'rw2', 'raf', 'dng', 'pef', 'srw', 'nrw'];

    /**
     * Extract headers from a raw file (FITS or camera RAW) via the Python microservice.
     */
    private function extractRawHeaders(string $absPath, string $ext): array
    {
        if (in_array($ext, self::FITS_EXTENSIONS, true)) {
            return $this->astropyClient->fitsHeader($absPath);
        }

        if (in_array($ext, self::RAW_EXTENSIONS, true)) {
            return $this->astropyClient->rawHeader($absPath);
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
