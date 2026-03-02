<?php

namespace App\Tests\Service;

use App\Entity\Doc;
use App\Service\DocService;
use App\Service\StoragePathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DocServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/docservice_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Cleanup temp files
        $files = glob($this->tmpDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    private function createService(): DocService
    {
        $resolver = $this->createMock(StoragePathResolver::class);
        $resolver->method('getDocumentsPath')->willReturn($this->tmpDir);

        return new DocService($resolver);
    }

    public function testSaveDocToStorageCreatesFile(): void
    {
        $service = $this->createService();
        $doc = new Doc();
        $doc->setTitle('Test Document');
        $doc->setDoc('# Hello World');

        $filename = $service->saveDocToStorage($doc);

        $this->assertFileExists($this->tmpDir . '/' . $filename);
        $this->assertStringEqualsFile($this->tmpDir . '/' . $filename, '# Hello World');
    }

    public function testSaveDocToStorageSetsPath(): void
    {
        $service = $this->createService();
        $doc = new Doc();
        $doc->setTitle('My Notes');
        $doc->setDoc('content');

        $service->saveDocToStorage($doc);

        $this->assertNotNull($doc->getPath());
        $this->assertStringEndsWith('.md', $doc->getPath());
        $this->assertStringStartsWith('my-notes-', $doc->getPath());
    }

    public function testSafeFilenameWithSpecialChars(): void
    {
        $service = $this->createService();

        $filename = $service->generateSafeFilename('Étoiles & Nébuleuses (test)', 'pdf');

        $this->assertStringEndsWith('.pdf', $filename);
        $this->assertDoesNotMatchRegularExpression('/[éà&()\\s]/', $filename);
        $this->assertStringContainsString('etoiles', $filename);
    }

    public function testSafeFilenameTruncatesLongNames(): void
    {
        $service = $this->createService();

        $longName = str_repeat('abcdefghij', 20); // 200 chars
        $filename = $service->generateSafeFilename($longName, 'txt');

        // slug part should be max 100 chars + '-' + uniqid + '.txt'
        $parts = explode('-', pathinfo($filename, PATHINFO_FILENAME));
        array_pop($parts); // remove uniqid
        $slugPart = implode('-', $parts);
        $this->assertLessThanOrEqual(100, strlen($slugPart));
    }

    public function testValidateFilenameRejectsTraversal(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $service->validateFilename('../../etc/passwd');
    }

    public function testValidateFilenameRejectsSlash(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $service->validateFilename('path/to/file.txt');
    }

    public function testValidateFilenameRejectsBackslash(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $service->validateFilename('path\\to\\file.txt');
    }

    public function testValidateFilenameAcceptsNormalFilename(): void
    {
        $service = $this->createService();

        // Should not throw
        $service->validateFilename('my-document-65f1a2b3c4d5e.pdf');
        $this->assertTrue(true);
    }

    public function testMoveUploadedFile(): void
    {
        $service = $this->createService();

        // Create a real PNG file (1x1 pixel) to simulate upload
        $tmpFile = $this->tmpDir . '/upload_test.png';
        $img = \imagecreatetruecolor(1, 1);
        \imagepng($img, $tmpFile);
        \imagedestroy($img);

        $uploadedFile = new UploadedFile($tmpFile, 'Mon Image Test.png', 'image/png', null, true);

        $newFilename = $service->moveUploadedFile($uploadedFile);

        $this->assertStringStartsWith('mon-image-test-', $newFilename);
        $this->assertStringEndsWith('.png', $newFilename);
        $this->assertFileExists($this->tmpDir . '/' . $newFilename);
    }
}
