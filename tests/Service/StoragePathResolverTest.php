<?php

namespace App\Tests\Service;

use App\Enum\SessionFolder;
use App\Service\AppConfig;
use App\Service\StoragePathResolver;
use PHPUnit\Framework\TestCase;

class StoragePathResolverTest extends TestCase
{
    private function createResolver(?array $templateOverride = null): StoragePathResolver
    {
        $config = $this->createMock(AppConfig::class);
        if ($templateOverride !== null) {
            $config->method('getSessionTemplate')->willReturn($templateOverride);
        } else {
            // Use real default template
            $config->method('getSessionTemplate')->willReturn([
                'version' => 1,
                'tree' => [
                    ['name' => '00 - Metadata', 'children' => []],
                    ['name' => '01 - Planning', 'children' => []],
                    ['name' => '02 - Acquisition', 'children' => [
                        ['name' => 'logs', 'children' => [
                            ['name' => 'nina', 'role' => 'LOG_NINA', 'children' => []],
                            ['name' => 'phd2', 'role' => 'LOG_PHD2', 'children' => []],
                            ['name' => 'session', 'allowExtra' => true, 'children' => []],
                        ]],
                        ['name' => 'metadata', 'allowExtra' => true, 'children' => []],
                        ['name' => 'raw', 'children' => [
                            ['name' => 'bias', 'role' => 'BIAS', 'children' => []],
                            ['name' => 'dark', 'role' => 'DARK', 'children' => []],
                            ['name' => 'flat', 'role' => 'FLAT', 'children' => []],
                            ['name' => 'light', 'role' => 'LIGHT', 'allowExtra' => true, 'children' => []],
                        ]],
                    ]],
                    ['name' => '03 - Processing', 'children' => [
                        ['name' => 'exports', 'role' => 'EXPORT', 'children' => []],
                        ['name' => 'logs', 'allowExtra' => true, 'children' => []],
                        ['name' => 'master', 'role' => 'MASTER', 'children' => []],
                        ['name' => 'pixinsight', 'allowExtra' => true, 'children' => []],
                    ]],
                    ['name' => '99 - Docs', 'role' => 'DOC', 'children' => []],
                ],
            ]);
        }

        return new StoragePathResolver($config);
    }

    public function testDefaultTemplateMapsMatchHardcodedPaths(): void
    {
        $resolver = $this->createResolver();

        $this->assertSame('02 - Acquisition/raw/light', $resolver->getRelativePath(SessionFolder::LIGHT));
        $this->assertSame('02 - Acquisition/raw/dark', $resolver->getRelativePath(SessionFolder::DARK));
        $this->assertSame('02 - Acquisition/raw/bias', $resolver->getRelativePath(SessionFolder::BIAS));
        $this->assertSame('02 - Acquisition/raw/flat', $resolver->getRelativePath(SessionFolder::FLAT));
        $this->assertSame('03 - Processing/master', $resolver->getRelativePath(SessionFolder::MASTER));
        $this->assertSame('03 - Processing/exports', $resolver->getRelativePath(SessionFolder::EXPORT));
        $this->assertSame('02 - Acquisition/logs/nina', $resolver->getRelativePath(SessionFolder::LOG_NINA));
        $this->assertSame('02 - Acquisition/logs/phd2', $resolver->getRelativePath(SessionFolder::LOG_PHD2));
        $this->assertSame('99 - Docs', $resolver->getRelativePath(SessionFolder::DOC));
    }

    public function testResolveBuildsAbsolutePath(): void
    {
        $resolver = $this->createResolver();

        $session = $this->createMock(\App\Entity\Session::class);
        $session->method('getPath')->willReturn('M31/2025-01-01');

        $root = $resolver->getSessionsRoot();

        $this->assertSame(
            $root . '/M31/2025-01-01/02 - Acquisition/raw/light',
            $resolver->resolve($session, SessionFolder::LIGHT)
        );
        $this->assertSame(
            $root . '/M31/2025-01-01/02 - Acquisition/logs/phd2',
            $resolver->resolve($session, SessionFolder::LOG_PHD2)
        );
    }

    public function testGetAllRelativePathsReturns16Paths(): void
    {
        $resolver = $this->createResolver();
        $paths = $resolver->getAllRelativePaths();

        // Root '' + 19 dirs (5 top-level + 14 nested)
        $this->assertCount(20, $paths);
        $this->assertSame('', $paths[0]);
        $this->assertContains('00 - Metadata', $paths);
        $this->assertContains('02 - Acquisition/raw/light', $paths);
        $this->assertContains('03 - Processing/master', $paths);
        $this->assertContains('99 - Docs', $paths);
    }

    public function testValidateTemplateValid(): void
    {
        $resolver = $this->createResolver();
        $errors = $resolver->validateTemplate($resolver->getTemplate());
        $this->assertEmpty($errors);
    }

    public function testValidateTemplateMissingRole(): void
    {
        $template = [
            'version' => 1,
            'tree' => [
                ['name' => 'light', 'role' => 'LIGHT', 'children' => []],
                ['name' => 'dark', 'role' => 'DARK', 'children' => []],
                // Missing BIAS, FLAT, MASTER, EXPORT, LOG_NINA, LOG_PHD2, DOC
            ],
        ];

        $resolver = $this->createResolver($template);
        $errors = $resolver->validateTemplate($template);

        $this->assertNotEmpty($errors);
        $this->assertGreaterThanOrEqual(7, count($errors)); // 7 missing roles
    }

    public function testValidateTemplateDuplicateRole(): void
    {
        $template = [
            'version' => 1,
            'tree' => [
                ['name' => 'light1', 'role' => 'LIGHT', 'children' => []],
                ['name' => 'light2', 'role' => 'LIGHT', 'children' => []],
                ['name' => 'dark', 'role' => 'DARK', 'children' => []],
                ['name' => 'bias', 'role' => 'BIAS', 'children' => []],
                ['name' => 'flat', 'role' => 'FLAT', 'children' => []],
                ['name' => 'master', 'role' => 'MASTER', 'children' => []],
                ['name' => 'export', 'role' => 'EXPORT', 'children' => []],
                ['name' => 'nina', 'role' => 'LOG_NINA', 'children' => []],
                ['name' => 'phd2', 'role' => 'LOG_PHD2', 'children' => []],
                ['name' => 'doc', 'role' => 'DOC', 'children' => []],
            ],
        ];

        $resolver = $this->createResolver($template);
        $errors = $resolver->validateTemplate($template);

        $this->assertNotEmpty($errors);
        $haseDuplicate = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'Duplicate role: LIGHT')) {
                $haseDuplicate = true;
            }
        }
        $this->assertTrue($haseDuplicate, 'Should detect duplicate LIGHT role');
    }

    public function testValidateTemplateInvalidFolderName(): void
    {
        $template = [
            'version' => 1,
            'tree' => [
                ['name' => '../escape', 'role' => 'LIGHT', 'children' => []],
                ['name' => 'dark', 'role' => 'DARK', 'children' => []],
                ['name' => 'bias', 'role' => 'BIAS', 'children' => []],
                ['name' => 'flat', 'role' => 'FLAT', 'children' => []],
                ['name' => 'master', 'role' => 'MASTER', 'children' => []],
                ['name' => 'export', 'role' => 'EXPORT', 'children' => []],
                ['name' => 'nina', 'role' => 'LOG_NINA', 'children' => []],
                ['name' => 'phd2', 'role' => 'LOG_PHD2', 'children' => []],
                ['name' => 'doc', 'role' => 'DOC', 'children' => []],
            ],
        ];

        $resolver = $this->createResolver($template);
        $errors = $resolver->validateTemplate($template);

        $hasNameError = false;
        foreach ($errors as $e) {
            if (str_contains($e, 'Invalid folder name')) {
                $hasNameError = true;
            }
        }
        $this->assertTrue($hasNameError, 'Should detect invalid folder name');
    }

    public function testAnalyzeConsistencyAllowExtraIgnoresSubdirs(): void
    {
        // Create a temp session dir with the default structure + extra subdirs
        $tmpDir = sys_get_temp_dir() . '/astropsy_test_' . uniqid();
        mkdir($tmpDir, 0777, true);

        // Template: one folder with allowExtra, one without
        $template = [
            'version' => 1,
            'tree' => [
                ['name' => 'lights', 'role' => 'LIGHT', 'allowExtra' => true, 'children' => []],
                ['name' => 'darks', 'role' => 'DARK', 'children' => []],
                ['name' => 'bias', 'role' => 'BIAS', 'children' => []],
                ['name' => 'flat', 'role' => 'FLAT', 'children' => []],
                ['name' => 'master', 'role' => 'MASTER', 'children' => []],
                ['name' => 'export', 'role' => 'EXPORT', 'children' => []],
                ['name' => 'nina', 'role' => 'LOG_NINA', 'children' => []],
                ['name' => 'phd2', 'role' => 'LOG_PHD2', 'children' => []],
                ['name' => 'doc', 'role' => 'DOC', 'children' => []],
            ],
        ];

        // Create expected dirs
        foreach (['lights', 'darks', 'bias', 'flat', 'master', 'export', 'nina', 'phd2', 'doc'] as $d) {
            mkdir($tmpDir . '/' . $d, 0777, true);
        }

        // Create extra subdir under lights (allowExtra=true) — should be ignored
        mkdir($tmpDir . '/lights/Ha', 0777, true);
        file_put_contents($tmpDir . '/lights/Ha/frame.fits', 'test');

        // Create extra subdir under darks (no allowExtra) — should be flagged
        mkdir($tmpDir . '/darks/old_backup', 0777, true);
        file_put_contents($tmpDir . '/darks/old_backup/dark.fits', 'test');

        $resolver = $this->createResolver($template);

        $session = $this->createMock(\App\Entity\Session::class);
        $session->method('getPath')->willReturn($tmpDir);

        $result = $resolver->analyzeConsistency($session);

        $this->assertEmpty($result['missing']);
        $this->assertNotContains('lights/Ha', $result['unexpected'], 'allowExtra dir should be ignored');
        $this->assertContains('darks/old_backup', $result['unexpected'], 'non-allowExtra dir should be flagged');

        // Cleanup
        $this->removeDir($tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGetSessionsRootReadsAppConfigFirst(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn('/custom/root');
        $config->method('getSessionTemplate')->willReturn([
            'version' => 1,
            'tree' => [],
        ]);

        $resolver = new StoragePathResolver($config);
        $this->assertSame('/custom/root', $resolver->getSessionsRoot());
    }

    public function testGetSessionsRootFallsBackToEnvWhenConfigNull(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn(null);
        $config->method('getSessionTemplate')->willReturn([
            'version' => 1,
            'tree' => [],
        ]);

        $resolver = new StoragePathResolver($config);
        // Should return env var or default — not null
        $root = $resolver->getSessionsRoot();
        $this->assertNotNull($root);
        $this->assertNotEmpty($root);
    }

    public function testToAbsolutePathConvertsRelative(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn('/app/data/sessions');
        $config->method('getSessionTemplate')->willReturn(['version' => 1, 'tree' => []]);

        $resolver = new StoragePathResolver($config);

        // Relative path → prepend root
        $this->assertSame('/app/data/sessions/NGC7000/2025-01-01', $resolver->toAbsolutePath('NGC7000/2025-01-01'));
        // Already absolute → return as-is
        $this->assertSame('/app/data/sessions/NGC7000', $resolver->toAbsolutePath('/app/data/sessions/NGC7000'));
        // Empty string → return as-is
        $this->assertSame('', $resolver->toAbsolutePath(''));
    }

    public function testToRelativePathStripsRoot(): void
    {
        $config = $this->createMock(AppConfig::class);
        $config->method('getSessionsRoot')->willReturn('/app/data/sessions');
        $config->method('getSessionTemplate')->willReturn(['version' => 1, 'tree' => []]);

        $resolver = new StoragePathResolver($config);

        // Absolute with root prefix → strip
        $this->assertSame('NGC7000/2025-01-01', $resolver->toRelativePath('/app/data/sessions/NGC7000/2025-01-01'));
        // Already relative → return as-is
        $this->assertSame('NGC7000/2025-01-01', $resolver->toRelativePath('NGC7000/2025-01-01'));
        // Different root → return as-is
        $this->assertSame('/other/root/target', $resolver->toRelativePath('/other/root/target'));
    }

    public function testGetRelativePathWithCustomTemplate(): void
    {
        $template = [
            'version' => 1,
            'tree' => [
                ['name' => 'Lights', 'role' => 'LIGHT', 'children' => []],
                ['name' => 'Darks', 'role' => 'DARK', 'children' => []],
                ['name' => 'Bias', 'role' => 'BIAS', 'children' => []],
                ['name' => 'Flats', 'role' => 'FLAT', 'children' => []],
                ['name' => 'Masters', 'role' => 'MASTER', 'children' => []],
                ['name' => 'Exports', 'role' => 'EXPORT', 'children' => []],
                ['name' => 'NINA-Logs', 'role' => 'LOG_NINA', 'children' => []],
                ['name' => 'PHD2-Logs', 'role' => 'LOG_PHD2', 'children' => []],
                ['name' => 'Documentation', 'role' => 'DOC', 'children' => []],
            ],
        ];

        $resolver = $this->createResolver($template);
        $this->assertSame('Lights', $resolver->getRelativePath(SessionFolder::LIGHT));
        $this->assertSame('PHD2-Logs', $resolver->getRelativePath(SessionFolder::LOG_PHD2));
    }
}
