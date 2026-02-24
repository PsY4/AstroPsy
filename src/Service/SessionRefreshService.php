<?php

namespace App\Service;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Session;
use App\Enum\SessionFolder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;

/**
 * Handles file-system scanning and DB sync for a Session's raws, exports and masters.
 */
class SessionRefreshService
{
    public function __construct(
        private readonly StoragePathResolver $resolver,
    ) {}

    public function refreshRaws(Session $session, AstropyClient $astropyClient, EntityManagerInterface $em): int
    {
        $exposureRepo = $em->getRepository(Exposure::class);
        $rawFolders   = [
            'bias'  => SessionFolder::BIAS,
            'dark'  => SessionFolder::DARK,
            'flat'  => SessionFolder::FLAT,
            'light' => SessionFolder::LIGHT,
        ];
        $count = 0;

        foreach ($rawFolders as $subdir => $folder) {
            $currentDir = $this->resolver->resolve($session, $folder);
            if (!is_dir($currentDir)) {
                continue;
            }

            $finder = new Finder();
            $finder->files()->in($currentDir)->name('/\.(fit|fits|nef)$/i');

            foreach ($finder as $file) {
                $absPath = $file->getRealPath();
                if (!$absPath) {
                    continue;
                }
                $filePath = $this->resolver->toRelativePath($absPath);

                /** @var Exposure|null $image */
                $image = $exposureRepo->findOneBy(['path' => $filePath, 'session' => $session]);

                $stat  = @stat($absPath);
                $size  = $stat ? $stat['size'] : null;
                $mtime = $stat ? (new \DateTimeImmutable())->setTimestamp($stat['mtime']) : null;
                $hash  = md5($size && $mtime ? sprintf('%s-%s-%s', $size, $mtime->getTimestamp(), $absPath) : (string) $absPath);

                $ext    = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
                $isFits = in_array($ext, ['fit', 'fits'], true);
                $isNef  = ($ext === 'nef');

                if ($image) {
                    $image->setHash($hash);
                } else {
                    $headers = [];
                    try {
                        if ($isFits) {
                            $headers = $astropyClient->fitsHeader($absPath);
                        } elseif ($isNef) {
                            $headers = $astropyClient->nefHeader($absPath);
                        } else {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }

                    $format     = $isFits ? 'FITS' : 'NEF';
                    $imageTyp   = $headers['IMAGETYP'] ?? strtoupper($subdir);
                    $dateObsStr = $headers['DATE-OBS'] ?? null;
                    $dateTaken  = null;
                    if ($dateObsStr) {
                        try {
                            $dateTaken = new \DateTimeImmutable($dateObsStr);
                        } catch (\Exception) {
                            $dateTaken = $mtime;
                        }
                    } else {
                        $dateTaken = $mtime;
                    }

                    $exposureS  = isset($headers['EXPOSURE']) && is_numeric($headers['EXPOSURE'])
                        ? (float) $headers['EXPOSURE']
                        : null;

                    $image = new Exposure();
                    $image->setSession($session)
                        ->setType($imageTyp)
                        ->setHash($hash)
                        ->setPath($filePath)
                        ->setRawHeader($headers)
                        ->setDateTaken($dateTaken)
                        ->setFilterName($headers['FILTER'] ?? null)
                        ->setExposureS($exposureS)
                        ->setSensorTemp($headers['CCD-TEMP'] ?? null)
                        ->setFormat($format);

                    $em->persist($image);
                    $count++;
                }
            }
        }

        $em->flush();
        return $count;
    }

    public function refreshExports(Session $session, AstropyClient $astropyClient, EntityManagerInterface $em): int
    {
        $exportsRepo = $em->getRepository(Export::class);
        $exportPath  = $this->resolver->resolve($session, SessionFolder::EXPORT);
        $count = 0;

        if (!is_dir($exportPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($exportPath)->name('/\.(jpg|jpeg|png|tif)$/i');

        foreach ($finder as $file) {
            $absPath   = $file->getRealPath();
            $filePath  = $this->resolver->toRelativePath($absPath);
            /** @var Export|null $image */
            $image     = $exportsRepo->findOneBy(['path' => $filePath, 'session' => $session]);

            $stat      = @stat($absPath);
            $size      = $stat ? $stat['size'] : null;
            $mtime     = $stat ? (new \DateTimeImmutable())->setTimestamp($stat['mtime']) : null;
            $hash      = md5($size && $mtime ? sprintf('%s-%s-' . $absPath, $size, $mtime->getTimestamp()) : null);
            $extension = pathinfo($absPath, PATHINFO_EXTENSION);

            if ($image) {
                $image->setHash($hash)->setType(strtoupper($extension));
            } else {
                $image = new Export();
                $image->setSession($session)
                    ->setHash($hash)
                    ->setType(strtoupper($extension))
                    ->setPath($filePath);
                $em->persist($image);
                $count++;
            }
        }

        $em->flush();
        return $count;
    }

    public function refreshMasters(Session $session, AstropyClient $astropyClient, EntityManagerInterface $em): int
    {
        $mastersRepo = $em->getRepository(Master::class);
        $masterPath  = $this->resolver->resolve($session, SessionFolder::MASTER);
        $count = 0;

        if (!is_dir($masterPath)) {
            return 0;
        }

        $finder = new Finder();
        $finder->files()->in($masterPath)->name('/\.(xisf|fits)$/i');

        foreach ($finder as $file) {
            $absPath   = $file->getRealPath();
            $filePath  = $this->resolver->toRelativePath($absPath);
            /** @var Master|null $image */
            $image     = $mastersRepo->findOneBy(['path' => $filePath, 'session' => $session]);

            $stat      = @stat($absPath);
            $size      = $stat ? $stat['size'] : null;
            $mtime     = $stat ? (new \DateTimeImmutable())->setTimestamp($stat['mtime']) : null;
            $hash      = md5($size && $mtime ? sprintf('%s-%s-' . $absPath, $size, $mtime->getTimestamp()) : null);
            $extension = pathinfo($absPath, PATHINFO_EXTENSION);

            if ($image) {
                if ($image->getHeader() == []) {
                    $image->setHeader($astropyClient->xisfHeader($absPath));
                }
                $em->persist($image);
            } else {
                $image = new Master();
                $image->setSession($session)
                    ->setHeader($astropyClient->xisfHeader($absPath))
                    ->setHash($hash)
                    ->setType(strtoupper($extension))
                    ->setPath($filePath);
                $em->persist($image);
                $count++;
            }
        }

        $em->flush();
        return $count;
    }
}
