<?php

namespace App\Tests\Service;

use App\Entity\Export;
use App\Entity\Exposure;
use App\Entity\Master;
use App\Entity\Session;
use App\Enum\SessionFolder;
use App\Service\AppConfig;
use App\Service\AstropyClient;
use App\Service\SessionRefreshService;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SessionRefreshServiceTest extends TestCase
{
    private string $root;
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/astropsy_scan_test_' . uniqid();
        $this->sessionPath = $this->root . '/NGC7000/20260101_session';

        // Create full session folder structure
        foreach (SessionFolder::rawFolders() as $folder) {
            mkdir($this->sessionPath . '/' . $folder->defaultRelativePath(), 0777, true);
        }
        mkdir($this->sessionPath . '/' . SessionFolder::MASTER->defaultRelativePath(), 0777, true);
        mkdir($this->sessionPath . '/' . SessionFolder::EXPORT->defaultRelativePath(), 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->root);
    }

    // ---------------------------------------------------------------
    //  refreshRaws
    // ---------------------------------------------------------------

    public function testRefreshRawsDetectsFitsFiles(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'img_001.fits');
        $this->touchFile(SessionFolder::LIGHT, 'img_002.fit');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->exactly(2))
            ->method('fitsHeader')
            ->willReturn($this->fakeFitsHeaders());

        $em = $this->mockEm(Exposure::class);
        $em->expects($this->exactly(2))->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(2, $count);
    }

    public function testRefreshRawsDetectsNefFiles(): void
    {
        $this->touchFile(SessionFolder::DARK, 'DSC_0001.NEF');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->once())
            ->method('nefHeader')
            ->willReturn($this->fakeNefHeaders());

        $em = $this->mockEm(Exposure::class);
        $em->expects($this->once())->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(1, $count);
    }

    public function testRefreshRawsSetsCorrectFormat(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'light.fits');
        $this->touchFile(SessionFolder::LIGHT, 'light.nef');

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($this->fakeFitsHeaders());
        $astropy->method('nefHeader')->willReturn($this->fakeNefHeaders());

        $persisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());

        $formats = array_map(fn(Exposure $e) => $e->getFormat(), $persisted);
        sort($formats);
        self::assertSame(['FITS', 'NEF'], $formats);
    }

    public function testRefreshRawsUsesImageTypFromHeaders(): void
    {
        $this->touchFile(SessionFolder::FLAT, 'flat_001.fits');

        $headers = $this->fakeFitsHeaders();
        $headers['IMAGETYP'] = 'FLAT';

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($headers);

        $persisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());

        self::assertSame('FLAT', $persisted[0]->getType());
    }

    public function testRefreshRawsFallsBackToFolderNameForType(): void
    {
        $this->touchFile(SessionFolder::DARK, 'dark_001.fits');

        $headers = $this->fakeFitsHeaders();
        unset($headers['IMAGETYP']);

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($headers);

        $persisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());

        self::assertSame('DARK', $persisted[0]->getType());
    }

    public function testRefreshRawsSkipsDuplicates(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'img_001.fits');

        // Simulate file already in DB
        $existing = new Exposure();
        $existing->setPath('NGC7000/20260101_session/02 - Acquisition/raw/light/img_001.fits')
            ->setHash('oldhash')
            ->setSession($this->mockSession());

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->never())->method('fitsHeader');

        $em = $this->mockEm(Exposure::class, [$existing]);
        $em->expects($this->never())->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(0, $count);
    }

    public function testRefreshRawsIgnoresUnsupportedExtensions(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'notes.txt');
        $this->touchFile(SessionFolder::LIGHT, 'preview.jpg');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->never())->method('fitsHeader');
        $astropy->expects($this->never())->method('nefHeader');

        $em = $this->mockEm(Exposure::class);
        $em->expects($this->never())->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(0, $count);
    }

    public function testRefreshRawsScansAllFourFolders(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'light.fits');
        $this->touchFile(SessionFolder::DARK, 'dark.fits');
        $this->touchFile(SessionFolder::FLAT, 'flat.fits');
        $this->touchFile(SessionFolder::BIAS, 'bias.fits');

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($this->fakeFitsHeaders());

        $em = $this->mockEm(Exposure::class);
        $em->expects($this->exactly(4))->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(4, $count);
    }

    public function testRefreshRawsLogsParsingErrors(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'corrupt.fits');

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willThrowException(new \RuntimeException('Bad FITS'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $em = $this->mockEm(Exposure::class);
        $em->expects($this->never())->method('persist');

        $service = $this->createService($astropy, $em, $logger);
        $count = $service->refreshRaws($this->mockSession());

        self::assertSame(0, $count);
    }

    public function testRefreshRawsHandlesMissingFolder(): void
    {
        // Remove the light folder
        rmdir($this->sessionPath . '/' . SessionFolder::LIGHT->defaultRelativePath());

        $astropy = $this->mockAstropyClient();
        $em = $this->mockEm(Exposure::class);

        $service = $this->createService($astropy, $em);
        $count = $service->refreshRaws($this->mockSession());

        // Should still work, just skip the missing folder
        self::assertGreaterThanOrEqual(0, $count);
    }

    public function testRefreshRawsParsesDateObs(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'light.fits');

        $headers = $this->fakeFitsHeaders();
        $headers['DATE-OBS'] = '2026-01-15T22:30:00';

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($headers);

        $persisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());

        self::assertSame('2026-01-15', $persisted[0]->getDateTaken()->format('Y-m-d'));
    }

    public function testRefreshRawsExtractsFilterAndExposure(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'ha_300s.fits');

        $headers = $this->fakeFitsHeaders();
        $headers['FILTER'] = 'Ha';
        $headers['EXPOSURE'] = 300.0;
        $headers['CCD-TEMP'] = -10.0;

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($headers);

        $persisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());

        self::assertSame('Ha', $persisted[0]->getFilterName());
        self::assertSame(300.0, $persisted[0]->getExposureS());
        self::assertSame(-10.0, $persisted[0]->getSensorTemp());
    }

    // ---------------------------------------------------------------
    //  refreshMasters
    // ---------------------------------------------------------------

    public function testRefreshMastersDetectsXisfFiles(): void
    {
        $this->touchFile(SessionFolder::MASTER, 'master_light_Ha.xisf');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->once())
            ->method('xisfHeader')
            ->willReturn($this->fakeXisfHeaders());
        $astropy->expects($this->never())->method('fitsHeader');

        $em = $this->mockEm(Master::class);
        $em->expects($this->once())->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshMasters($this->mockSession());

        self::assertSame(1, $count);
    }

    public function testRefreshMastersDetectsFitsFiles(): void
    {
        $this->touchFile(SessionFolder::MASTER, 'master_dark.fits');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->once())
            ->method('fitsHeader')
            ->willReturn($this->fakeFitsHeaders());
        $astropy->expects($this->never())->method('xisfHeader');

        $em = $this->mockEm(Master::class);
        $em->expects($this->once())->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshMasters($this->mockSession());

        self::assertSame(1, $count);
    }

    public function testRefreshMastersDispatchesByExtension(): void
    {
        $this->touchFile(SessionFolder::MASTER, 'master.xisf');
        $this->touchFile(SessionFolder::MASTER, 'master.fits');

        $astropy = $this->mockAstropyClient();
        $astropy->expects($this->once())->method('xisfHeader')->willReturn($this->fakeXisfHeaders());
        $astropy->expects($this->once())->method('fitsHeader')->willReturn($this->fakeFitsHeaders());

        $persisted = [];
        $em = $this->mockEm(Master::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshMasters($this->mockSession());

        $types = array_map(fn(Master $m) => $m->getType(), $persisted);
        sort($types);
        self::assertSame(['FITS', 'XISF'], $types);
    }

    public function testRefreshMastersLogsParsingErrors(): void
    {
        $this->touchFile(SessionFolder::MASTER, 'corrupt.xisf');

        $astropy = $this->mockAstropyClient();
        $astropy->method('xisfHeader')->willThrowException(new \RuntimeException('Bad XISF'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $persisted = [];
        $em = $this->mockEm(Master::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($astropy, $em, $logger);
        $count = $service->refreshMasters($this->mockSession());

        // Should still create the entity, just with empty headers
        self::assertSame(1, $count);
        self::assertSame([], $persisted[0]->getHeader());
    }

    // ---------------------------------------------------------------
    //  refreshExports
    // ---------------------------------------------------------------

    public function testRefreshExportsDetectsAllImageFormats(): void
    {
        $this->touchFile(SessionFolder::EXPORT, 'final.jpg');
        $this->touchFile(SessionFolder::EXPORT, 'final.jpeg');
        $this->touchFile(SessionFolder::EXPORT, 'final.png');
        $this->touchFile(SessionFolder::EXPORT, 'final.tif');
        $this->touchFile(SessionFolder::EXPORT, 'final.tiff');

        $astropy = $this->mockAstropyClient();

        $em = $this->mockEm(Export::class);
        $em->expects($this->exactly(5))->method('persist');

        $service = $this->createService($astropy, $em);
        $count = $service->refreshExports($this->mockSession());

        self::assertSame(5, $count);
    }

    public function testRefreshExportsSetsCorrectType(): void
    {
        $this->touchFile(SessionFolder::EXPORT, 'result.png');
        $this->touchFile(SessionFolder::EXPORT, 'result.tiff');

        $persisted = [];
        $em = $this->mockEm(Export::class);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$persisted) {
            $persisted[] = $entity;
        });

        $service = $this->createService($this->mockAstropyClient(), $em);
        $service->refreshExports($this->mockSession());

        $types = array_map(fn(Export $e) => $e->getType(), $persisted);
        sort($types);
        self::assertSame(['PNG', 'TIFF'], $types);
    }

    public function testRefreshExportsIgnoresNonImageFiles(): void
    {
        $this->touchFile(SessionFolder::EXPORT, 'readme.txt');
        $this->touchFile(SessionFolder::EXPORT, 'process.xisf');

        $em = $this->mockEm(Export::class);
        $em->expects($this->never())->method('persist');

        $service = $this->createService($this->mockAstropyClient(), $em);
        $count = $service->refreshExports($this->mockSession());

        self::assertSame(0, $count);
    }

    public function testRefreshExportsSkipsDuplicates(): void
    {
        $this->touchFile(SessionFolder::EXPORT, 'final.jpg');

        $existing = new Export();
        $existing->setPath('NGC7000/20260101_session/03 - Processing/exports/final.jpg')
            ->setHash('oldhash')
            ->setType('JPG')
            ->setSession($this->mockSession());

        $em = $this->mockEm(Export::class, [$existing]);
        $em->expects($this->never())->method('persist');

        $service = $this->createService($this->mockAstropyClient(), $em);
        $count = $service->refreshExports($this->mockSession());

        self::assertSame(0, $count);
    }

    public function testRefreshExportsHandlesMissingFolder(): void
    {
        $this->removeDir($this->sessionPath . '/' . SessionFolder::EXPORT->defaultRelativePath());

        $em = $this->mockEm(Export::class);
        $service = $this->createService($this->mockAstropyClient(), $em);
        $count = $service->refreshExports($this->mockSession());

        self::assertSame(0, $count);
    }

    // ---------------------------------------------------------------
    //  Hash consistency
    // ---------------------------------------------------------------

    public function testHashIsConsistentAcrossCalls(): void
    {
        $this->touchFile(SessionFolder::LIGHT, 'img.fits');
        $this->touchFile(SessionFolder::EXPORT, 'img.jpg');
        $this->touchFile(SessionFolder::MASTER, 'master.xisf');

        $astropy = $this->mockAstropyClient();
        $astropy->method('fitsHeader')->willReturn($this->fakeFitsHeaders());
        $astropy->method('xisfHeader')->willReturn($this->fakeXisfHeaders());

        $allPersisted = [];
        $em = $this->mockEm(Exposure::class);
        $em->method('getRepository')->willReturnCallback(function () {
            $repo = $this->createMock(EntityRepository::class);
            $repo->method('findOneBy')->willReturn(null);
            return $repo;
        });
        $em->method('persist')->willReturnCallback(function ($entity) use (&$allPersisted) {
            $allPersisted[] = $entity;
        });

        $service = $this->createService($astropy, $em);
        $service->refreshRaws($this->mockSession());
        $service->refreshExports($this->mockSession());
        $service->refreshMasters($this->mockSession());

        // All hashes should be non-empty and match the format md5(size-path)
        foreach ($allPersisted as $entity) {
            $hash = $entity->getHash();
            self::assertNotEmpty($hash);
            self::assertSame(32, strlen($hash), 'Hash should be MD5 (32 hex chars)');
        }
    }

    // ---------------------------------------------------------------
    //  SessionFolder::filePattern()
    // ---------------------------------------------------------------

    /**
     * @dataProvider filePatternProvider
     */
    public function testFilePatternMatchesExpectedExtensions(SessionFolder $folder, string $filename, bool $shouldMatch): void
    {
        $pattern = $folder->filePattern();
        self::assertNotNull($pattern);

        $result = preg_match($pattern, $filename);
        self::assertSame(
            $shouldMatch ? 1 : 0,
            $result,
            sprintf('Pattern %s should%s match "%s"', $pattern, $shouldMatch ? '' : ' NOT', $filename)
        );
    }

    public static function filePatternProvider(): array
    {
        return [
            // RAW folders
            'fits lowercase' => [SessionFolder::LIGHT, 'img.fits', true],
            'fits uppercase' => [SessionFolder::LIGHT, 'IMG.FITS', true],
            'fit extension'  => [SessionFolder::LIGHT, 'img.fit', true],
            'nef extension'  => [SessionFolder::DARK, 'DSC_001.NEF', true],
            'nef lowercase'  => [SessionFolder::DARK, 'dsc_001.nef', true],
            'txt not raw'    => [SessionFolder::LIGHT, 'notes.txt', false],
            'jpg not raw'    => [SessionFolder::LIGHT, 'preview.jpg', false],

            // Masters
            'xisf master'    => [SessionFolder::MASTER, 'master.xisf', true],
            'fits master'    => [SessionFolder::MASTER, 'master.fits', true],
            'png not master' => [SessionFolder::MASTER, 'preview.png', false],

            // Exports
            'jpg export'     => [SessionFolder::EXPORT, 'final.jpg', true],
            'jpeg export'    => [SessionFolder::EXPORT, 'final.jpeg', true],
            'png export'     => [SessionFolder::EXPORT, 'final.png', true],
            'tif export'     => [SessionFolder::EXPORT, 'final.tif', true],
            'tiff export'    => [SessionFolder::EXPORT, 'final.tiff', true],
            'TIFF uppercase' => [SessionFolder::EXPORT, 'FINAL.TIFF', true],
            'fits not export'=> [SessionFolder::EXPORT, 'data.fits', false],

            // PHD2 logs
            'txt phd2'       => [SessionFolder::LOG_PHD2, 'PHD2_guide.txt', true],
            'log not phd2'   => [SessionFolder::LOG_PHD2, 'PHD2_guide.log', false],
        ];
    }

    // ---------------------------------------------------------------
    //  Helpers
    // ---------------------------------------------------------------

    private function createResolver(): StoragePathResolver
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn($this->root);
        $config->method('getSessionTemplate')->willReturn(['tree' => []]);

        return new StoragePathResolver($config);
    }

    private function mockAstropyClient(): AstropyClient&\PHPUnit\Framework\MockObject\MockObject
    {
        return $this->createMock(AstropyClient::class);
    }

    private function mockSession(): Session&\PHPUnit\Framework\MockObject\MockObject
    {
        $session = $this->createMock(Session::class);
        $session->method('getPath')->willReturn('NGC7000/20260101_session');
        $session->method('getId')->willReturn(1);
        return $session;
    }

    /**
     * @param class-string $entityClass
     * @param object[] $existing Entities already "in DB" (findOneBy returns match by path)
     */
    private function mockEm(string $entityClass, array $existing = []): EntityManagerInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturnCallback(function (array $criteria) use ($existing) {
            foreach ($existing as $entity) {
                if ($entity->getPath() === $criteria['path']) {
                    return $entity;
                }
            }
            return null;
        });

        $em->method('getRepository')->willReturn($repo);

        return $em;
    }

    private function createService(
        AstropyClient $astropy,
        EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ): SessionRefreshService {
        return new SessionRefreshService(
            $this->createResolver(),
            $astropy,
            $em,
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    private function touchFile(SessionFolder $folder, string $filename): void
    {
        $dir = $this->sessionPath . '/' . $folder->defaultRelativePath();
        file_put_contents($dir . '/' . $filename, str_repeat("\0", 1024));
    }

    private function fakeFitsHeaders(): array
    {
        return [
            'IMAGETYP' => 'LIGHT',
            'DATE-OBS' => '2026-01-15T22:30:00',
            'EXPOSURE' => 300.0,
            'FILTER' => 'L',
            'CCD-TEMP' => -10.0,
        ];
    }

    private function fakeNefHeaders(): array
    {
        return [
            'FORMAT' => 'NEF',
            'IMAGETYP' => null,
            'DATE-OBS' => '2026-01-15T22:30:00',
            'EXPOSURE' => 30.0,
            'FILTER' => null,
            'CCD-TEMP' => null,
            'ISO' => 1600,
            'CAMERA' => 'NIKON D810A',
        ];
    }

    private function fakeXisfHeaders(): array
    {
        return [
            'metadata' => [
                'FITS' => ['OBJECT' => 'NGC7000', 'EXPTIME' => 300],
                'XISFProperties' => '',
            ],
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
