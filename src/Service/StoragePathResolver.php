<?php

namespace App\Service;

use App\Entity\Session;
use App\Enum\SessionFolder;
use Symfony\Component\Filesystem\Path;

class StoragePathResolver
{
    private ?array $roleMap = null;

    public function __construct(
        private readonly AppConfig $config,
    ) {}

    public function getSessionsRoot(): string
    {
        $custom = $this->config->getSessionsRoot();
        if ($custom !== null && $custom !== '') {
            return Path::normalize($custom);
        }

        return Path::normalize(getenv('SESSIONS_ROOT') ?: '/app/data/sessions');
    }

    public function getDocumentsPath(): string
    {
        return Path::join($this->getSessionsRoot(), '--DOCUMENTS--');
    }

    public function toAbsolutePath(string $path): string
    {
        if ($path === '' || $path[0] === '/') {
            return $path;
        }

        return Path::normalize($this->getSessionsRoot() . '/' . $path);
    }

    public function toRelativePath(string $path): string
    {
        $root = $this->getSessionsRoot();
        $prefix = rtrim($root, '/') . '/';

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    public function resolve(Session $session, SessionFolder $folder): string
    {
        return Path::normalize($this->toAbsolutePath($session->getPath()) . '/' . $this->getRelativePath($folder));
    }

    public function getRelativePath(SessionFolder $folder): string
    {
        $map = $this->getRoleMap();
        return $map[$folder->value] ?? $folder->defaultRelativePath();
    }

    public function getTemplate(): array
    {
        return $this->config->getSessionTemplate();
    }

    public function getAllRelativePaths(): array
    {
        $tree = $this->getTemplate()['tree'] ?? [];
        $paths = [''];
        $this->collectPaths($tree, '', $paths);
        return $paths;
    }

    /**
     * @return string[] List of error messages (empty = valid)
     */
    public function validateTemplate(array $template): array
    {
        $errors = [];

        if (!isset($template['tree']) || !is_array($template['tree'])) {
            $errors[] = 'Missing or invalid "tree" key.';
            return $errors;
        }

        $foundRoles = [];
        $this->collectRoles($template['tree'], $foundRoles, $errors);

        // Check all 9 roles are present
        foreach (SessionFolder::cases() as $case) {
            if (!in_array($case->value, $foundRoles, true)) {
                $errors[] = sprintf('Missing role: %s', $case->value);
            }
        }

        // Check for duplicate roles
        $counts = array_count_values($foundRoles);
        foreach ($counts as $role => $count) {
            if ($count > 1) {
                $errors[] = sprintf('Duplicate role: %s', $role);
            }
        }

        return $errors;
    }

    /**
     * Returns all relative paths marked with allowExtra in the template.
     * @return string[]
     */
    public function getAllowExtraPaths(): array
    {
        $template = $this->getTemplate();
        $paths = [];
        $this->collectAllowExtra($template['tree'] ?? [], '', $paths);
        return $paths;
    }

    /**
     * Analyze consistency between disk and configured template for a session.
     * @return array{missing: string[], unexpected: string[]}
     */
    public function analyzeConsistency(Session $session): array
    {
        $sessionPath = $this->toAbsolutePath($session->getPath());
        $expected = $this->getAllRelativePaths();
        $allowExtra = $this->getAllowExtraPaths();
        $missing = [];
        $unexpected = [];

        // Check expected dirs exist
        foreach ($expected as $rel) {
            if ($rel === '') {
                continue;
            }
            $abs = Path::normalize($sessionPath . '/' . $rel);
            if (!is_dir($abs)) {
                $missing[] = $rel;
            }
        }

        // Check for unexpected dirs with files at role-level depth
        if (is_dir($sessionPath)) {
            $actualDirs = $this->listDirsRecursive($sessionPath, $sessionPath);
            $expectedSet = array_flip($expected);
            foreach ($actualDirs as $dir) {
                if (!isset($expectedSet[$dir])) {
                    // Skip if under an allowExtra parent
                    if ($this->isUnderAllowExtra($dir, $allowExtra)) {
                        continue;
                    }
                    // Only flag if it contains files
                    $abs = Path::normalize($sessionPath . '/' . $dir);
                    if ($this->dirHasFiles($abs)) {
                        $unexpected[] = $dir;
                    }
                }
            }
        }

        return ['missing' => $missing, 'unexpected' => $unexpected];
    }

    // --- Private ---

    private function getRoleMap(): array
    {
        if ($this->roleMap !== null) {
            return $this->roleMap;
        }

        $template = $this->getTemplate();
        $tree = $template['tree'] ?? [];
        $this->roleMap = [];
        $this->buildRoleMap($tree, '', $this->roleMap);

        // Fallback: fill missing roles with defaults
        foreach (SessionFolder::cases() as $case) {
            if (!isset($this->roleMap[$case->value])) {
                $this->roleMap[$case->value] = $case->defaultRelativePath();
            }
        }

        return $this->roleMap;
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

    private function collectRoles(array $nodes, array &$foundRoles, array &$errors): void
    {
        foreach ($nodes as $node) {
            $name = $node['name'] ?? '';

            if ($name === '' || preg_match('#[/\\\\]|^\.\.#', $name)) {
                $errors[] = sprintf('Invalid folder name: "%s"', $name);
            }

            if (isset($node['role']) && $node['role'] !== '') {
                $role = $node['role'];
                if (SessionFolder::tryFrom($role) === null) {
                    $errors[] = sprintf('Unknown role: %s', $role);
                }
                $foundRoles[] = $role;
            }

            if (!empty($node['children'])) {
                $this->collectRoles($node['children'], $foundRoles, $errors);
            }
        }
    }

    private function listDirsRecursive(string $base, string $root): array
    {
        $result = [];
        $items = @scandir($base);
        if ($items === false) {
            return $result;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $base . '/' . $item;
            if (is_dir($full)) {
                $rel = ltrim(str_replace($root, '', $full), '/');
                $result[] = $rel;
                $result = array_merge($result, $this->listDirsRecursive($full, $root));
            }
        }
        return $result;
    }

    private function dirHasFiles(string $dir): bool
    {
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (is_file($dir . '/' . $item)) {
                return true;
            }
        }
        return false;
    }

    private function collectAllowExtra(array $nodes, string $prefix, array &$paths): void
    {
        foreach ($nodes as $node) {
            $name = $node['name'] ?? '';
            $path = $prefix !== '' ? $prefix . '/' . $name : $name;

            if (!empty($node['allowExtra'])) {
                $paths[] = $path;
            }

            if (!empty($node['children'])) {
                $this->collectAllowExtra($node['children'], $path, $paths);
            }
        }
    }

    private function isUnderAllowExtra(string $dir, array $allowExtraPaths): bool
    {
        foreach ($allowExtraPaths as $aePath) {
            if ($dir === $aePath || str_starts_with($dir, $aePath . '/')) {
                return true;
            }
        }
        return false;
    }
}
