<?php

namespace App\Tests\Service;

use App\Entity\Session;
use App\Entity\Target;
use App\Service\AppConfig;
use App\Service\FsChangeDetectorService;
use App\Service\StoragePathResolver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

class FsChangeDetectorServiceTest extends TestCase
{
    private function createResolver(string $root): StoragePathResolver
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn($root);
        $config->method('getSessionTemplate')->willReturn(['tree' => []]);

        return new StoragePathResolver($config);
    }

    private function createEm(array $targets = [], array $sessions = []): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $targetRepo = $this->createMock(EntityRepository::class);
        $targetRepo->method('findAll')->willReturn($targets);
        $targetRepo->method('findBy')->willReturn([]);

        $sessionRepo = $this->createMock(EntityRepository::class);
        $sessionRepo->method('findBy')->willReturn($sessions);

        $em->method('getRepository')->willReturnCallback(function (string $class) use ($targetRepo, $sessionRepo) {
            if ($class === Target::class) {
                return $targetRepo;
            }
            if ($class === Session::class) {
                return $sessionRepo;
            }
            return $this->createMock(EntityRepository::class);
        });

        return $em;
    }

    public function testDetectTargetChangesEmptyDir(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_empty_' . uniqid();
        mkdir($root);

        $service = new FsChangeDetectorService($this->createResolver($root), $this->createEm());
        $result = $service->detectTargetChanges();

        self::assertSame([], $result['new']);
        self::assertSame([], $result['missing']);

        rmdir($root);
    }

    public function testDetectTargetChangesNewDir(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_new_' . uniqid();
        mkdir($root);
        mkdir($root . '/NGC7000');

        $service = new FsChangeDetectorService($this->createResolver($root), $this->createEm());
        $result = $service->detectTargetChanges();

        self::assertCount(1, $result['new']);
        self::assertSame('NGC7000', $result['new'][0]['name']);
        self::assertSame('NGC7000', $result['new'][0]['path']);
        self::assertSame([], $result['missing']);

        rmdir($root . '/NGC7000');
        rmdir($root);
    }

    public function testDetectTargetChangesMissing(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_missing_' . uniqid();
        mkdir($root);

        $target = $this->createMock(Target::class);
        $target->method('getPath')->willReturn('IC1396');
        $target->method('getName')->willReturn('IC1396');
        $target->method('getId')->willReturn(42);

        $service = new FsChangeDetectorService($this->createResolver($root), $this->createEm([$target]));
        $result = $service->detectTargetChanges();

        self::assertSame([], $result['new']);
        self::assertCount(1, $result['missing']);
        self::assertSame('IC1396', $result['missing'][0]['name']);
        self::assertSame(42, $result['missing'][0]['id']);

        rmdir($root);
    }

    public function testDetectTargetChangesSkipsDocuments(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_docs_' . uniqid();
        mkdir($root);
        mkdir($root . '/--DOCUMENTS--');

        $service = new FsChangeDetectorService($this->createResolver($root), $this->createEm());
        $result = $service->detectTargetChanges();

        self::assertSame([], $result['new']);

        rmdir($root . '/--DOCUMENTS--');
        rmdir($root);
    }

    public function testDetectSessionChangesNewDir(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_sess_' . uniqid();
        mkdir($root);
        mkdir($root . '/MyTarget');
        mkdir($root . '/MyTarget/20260101_session1');

        $target = $this->createMock(Target::class);
        $target->method('getPath')->willReturn('MyTarget');
        $target->method('getId')->willReturn(1);

        $em = $this->createEm([], []);
        $sessionRepo = $this->createMock(EntityRepository::class);
        $sessionRepo->method('findBy')->willReturn([]);
        $em->method('getRepository')->willReturnCallback(function (string $class) use ($sessionRepo) {
            if ($class === Session::class) {
                return $sessionRepo;
            }
            return $this->createMock(EntityRepository::class);
        });

        $service = new FsChangeDetectorService($this->createResolver($root), $em);
        $result = $service->detectSessionChanges($target);

        self::assertCount(1, $result['new']);
        self::assertSame('20260101_session1', $result['new'][0]['name']);
        self::assertSame('MyTarget/20260101_session1', $result['new'][0]['path']);

        rmdir($root . '/MyTarget/20260101_session1');
        rmdir($root . '/MyTarget');
        rmdir($root);
    }

    public function testDetectSessionChangesMissing(): void
    {
        $root = sys_get_temp_dir() . '/astropsy_test_sess_miss_' . uniqid();
        mkdir($root);
        mkdir($root . '/MyTarget');

        $target = $this->createMock(Target::class);
        $target->method('getPath')->willReturn('MyTarget');
        $target->method('getId')->willReturn(1);

        $session = $this->createMock(Session::class);
        $session->method('getPath')->willReturn('MyTarget/20260101_gone');
        $session->method('getId')->willReturn(99);

        $em = $this->createMock(EntityManagerInterface::class);
        $sessionRepo = $this->createMock(EntityRepository::class);
        $sessionRepo->method('findBy')->willReturn([$session]);
        $em->method('getRepository')->willReturn($sessionRepo);

        $service = new FsChangeDetectorService($this->createResolver($root), $em);
        $result = $service->detectSessionChanges($target);

        self::assertSame([], $result['new']);
        self::assertCount(1, $result['missing']);
        self::assertSame(99, $result['missing'][0]['id']);

        rmdir($root . '/MyTarget');
        rmdir($root);
    }

    public function testDetectTargetChangesNonexistentRoot(): void
    {
        $service = new FsChangeDetectorService($this->createResolver('/nonexistent'), $this->createEm());
        $result = $service->detectTargetChanges();

        self::assertSame([], $result['new']);
        self::assertSame([], $result['missing']);
    }
}
